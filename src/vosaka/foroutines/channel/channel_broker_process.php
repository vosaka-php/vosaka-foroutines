<?php

/**
 * Channel Broker Process — Background TCP server for inter-process channel communication.
 *
 * This script is spawned as a background process by ChannelSocketClient when
 * creating a new socket-based inter-process channel. It runs the ChannelBroker
 * event loop, managing an in-memory ring buffer and multiplexing client
 * connections via stream_select().
 *
 * Usage:
 *   php channel_broker_process.php <channel_name> <capacity> [<idle_timeout>]
 *
 * Arguments:
 *   <channel_name>   Unique channel identifier (used to register the port file).
 *   <capacity>       Maximum buffer size (0 = unbounded).
 *   <idle_timeout>   Optional. Seconds of inactivity before auto-shutdown (default: 300).
 *
 * Boot sequence:
 *   1. Parse arguments and validate.
 *   2. Create ChannelBroker instance with the given capacity.
 *   3. Call listen(0) to bind on an ephemeral TCP port on 127.0.0.1.
 *   4. Write "READY:<port>\n" to STDOUT so the spawning process knows the
 *      broker is ready and on which port.
 *   5. Enter the broker event loop (blocks until SHUTDOWN command or idle timeout).
 *
 * The parent process detects readiness by reading the "READY:<port>\n" line
 * from STDOUT. After that, STDOUT is not used for any further communication —
 * all channel data flows over TCP sockets.
 *
 * Port discovery:
 *   The port number is communicated directly via STDOUT to the parent process.
 *   The parent stores it in Channel::$_socketPort so that child processes
 *   (via $chan->connect() or __unserialize) can reconnect by port without
 *   any file-based discovery.  No port file is written to disk.
 *
 * STDIN is monitored for EOF to detect parent death (same pattern as
 * worker_socket_loop.php). If the parent dies, the broker exits gracefully
 * to prevent orphaned processes.
 *
 * Signal handling:
 *   On POSIX systems, SIGTERM and SIGINT are caught for graceful shutdown.
 *   On Windows, the broker relies on STDIN EOF detection and the SHUTDOWN
 *   protocol command.
 *
 * @internal This script is not part of the public API.
 */

declare(strict_types=1);

ini_set("display_errors", "off");
ini_set("log_errors", "1");
error_reporting(E_ALL);

// ── Bootstrap: find and load Composer autoloader ──────────────────────

require_once __DIR__ . '/../script_functions.php';
require_once findAutoload(__DIR__);

use vosaka\foroutines\channel\ChannelBroker;

// ── Validate arguments ────────────────────────────────────────────────

if (!isset($argv[1]) || $argv[1] === "") {
    fwrite(STDERR, "channel_broker_process: Missing channel_name argument\n");
    fwrite(
        STDERR,
        "Usage: php channel_broker_process.php <channel_name> <capacity> [<idle_timeout>]\n",
    );
    exit(1);
}

if (!isset($argv[2]) || !is_numeric($argv[2])) {
    fwrite(
        STDERR,
        "channel_broker_process: Missing or invalid capacity argument\n",
    );
    fwrite(
        STDERR,
        "Usage: php channel_broker_process.php <channel_name> <capacity> [<idle_timeout>]\n",
    );
    exit(1);
}

$channelName = $argv[1];
$capacity = (int) $argv[2];
$idleTimeout =
    isset($argv[3]) && is_numeric($argv[3]) ? (float) $argv[3] : 300.0;

if ($capacity < 0) {
    fwrite(STDERR, "channel_broker_process: Capacity must be >= 0\n");
    exit(1);
}

// ── Create and start the broker ───────────────────────────────────────

try {
    $broker = new ChannelBroker($channelName, $capacity, $idleTimeout);
    $broker->listen(0);
    $port = $broker->getPort();
} catch (Throwable $e) {
    fwrite(
        STDERR,
        "channel_broker_process: Failed to start broker: " .
            $e->getMessage() .
            "\n",
    );
    exit(1);
}

// ── Signal readiness to the parent process ────────────────────────────
// The port is communicated directly via STDOUT — no port file needed.
// The parent (ChannelSocketClient::createBroker) reads this line and
// stores the port in Channel::$_socketPort for child-process discovery.

fwrite(STDOUT, "READY:{$port}\n");
fflush(STDOUT);

// ── Install signal handlers (POSIX only) ──────────────────────────────

$signalReceived = false;

if (function_exists("pcntl_async_signals")) {
    pcntl_async_signals(true);

    $signalHandler = function (int $signo) use (
        $broker,
        &$signalReceived,
    ): void {
        $signalReceived = true;
        $broker->stop();
        fwrite(
            STDERR,
            "channel_broker_process: Received signal {$signo}, shutting down\n",
        );
    };

    if (function_exists("pcntl_signal")) {
        pcntl_signal(SIGTERM, $signalHandler);
        pcntl_signal(SIGINT, $signalHandler);
    }
}

// ── Make STDIN non-blocking for parent-death detection ────────────────
// On Windows this may silently fail, but isParentGone() handles it
// gracefully via feof().

@stream_set_blocking(STDIN, false);

/**
 * Check if STDIN has reached EOF (parent died / closed the pipe).
 * Non-blocking check — returns true if parent is gone.
 *
 * Same pattern as worker_socket_loop.php.
 */
function isParentGone(): bool
{
    if (!defined("STDIN") || !is_resource(STDIN)) {
        return true;
    }

    if (feof(STDIN)) {
        return true;
    }

    // On non-Windows platforms, use stream_select to check for data/EOF
    if (strncasecmp(PHP_OS, "WIN", 3) !== 0) {
        $read = [STDIN];
        $write = null;
        $except = null;
        $ready = @stream_select($read, $write, $except, 0, 0);

        if ($ready === false) {
            return true;
        }

        if ($ready > 0) {
            @fread(STDIN, 1024);
            if (feof(STDIN)) {
                return true;
            }
        }
    }

    return false;
}

// ── Main event loop ───────────────────────────────────────────────────
// We don't call $broker->run() directly because we need to interleave
// parent-death checks with broker ticks.

$parentCheckInterval = 0; // Check parent every N ticks
$parentCheckCounter = 0;
$parentCheckEvery = 20; // Every 20 ticks (~1 second at 50ms select timeout)

fwrite(
    STDERR,
    "channel_broker_process: Broker started for '{$channelName}' on port {$port} (capacity={$capacity}, idle_timeout={$idleTimeout}s)\n",
);

while ($broker->isRunning()) {
    // Run one tick of the broker event loop (50ms select timeout)
    $shouldContinue = $broker->tick(0.05);

    if (!$shouldContinue) {
        break;
    }

    // Periodically check if parent is still alive
    $parentCheckCounter++;
    if ($parentCheckCounter >= $parentCheckEvery) {
        $parentCheckCounter = 0;

        if (isParentGone()) {
            fwrite(
                STDERR,
                "channel_broker_process: Parent process gone, shutting down\n",
            );
            break;
        }
    }

    // Check for signals (POSIX only)
    if ($signalReceived) {
        break;
    }
}

// ── Cleanup ───────────────────────────────────────────────────────────

fwrite(
    STDERR,
    "channel_broker_process: Broker for '{$channelName}' shutting down\n",
);

$broker->cleanup();

exit(0);
