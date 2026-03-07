<?php

/**
 * Channel Broker Pool Process — Background TCP server for multiplexed
 * inter-process channel communication.
 *
 * This script is spawned as a single background process that manages
 * multiple channels simultaneously, eliminating the need for one process
 * per channel.
 *
 * Usage:
 *   php channel_broker_pool_process.php [<idle_timeout>]
 *
 * Arguments:
 *   <idle_timeout>   Optional. Seconds of inactivity before auto-shutdown (default: 300).
 *
 * Boot sequence:
 *   1. Parse arguments and validate.
 *   2. Create ChannelBrokerPool instance.
 *   3. Call listen(0) to bind on an ephemeral TCP port on 127.0.0.1.
 *   4. Write "READY:<port>\n" to STDOUT so the spawning process knows the
 *      pool is ready and on which port.
 *   5. Enter the pool event loop (blocks until POOL_SHUTDOWN command or idle timeout).
 *
 * The parent process detects readiness by reading the "READY:<port>\n" line
 * from STDOUT. After that, STDOUT is not used for any further communication —
 * all channel data flows over TCP sockets.
 *
 * Channels are created dynamically via the CREATE_CHANNEL protocol command.
 *
 * STDIN is monitored for EOF to detect parent death (same pattern as
 * channel_broker_process.php and worker_socket_loop.php). If the parent
 * dies, the pool exits gracefully to prevent orphaned processes.
 *
 * Signal handling:
 *   On POSIX systems, SIGTERM and SIGINT are caught for graceful shutdown.
 *   On Windows, the pool relies on STDIN EOF detection and the POOL_SHUTDOWN
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

use vosaka\foroutines\channel\ChannelBrokerPool;

// ── Validate arguments ────────────────────────────────────────────────

$idleTimeout =
    isset($argv[1]) && is_numeric($argv[1]) ? (float) $argv[1] : 300.0;

// ── Create and start the pool ─────────────────────────────────────────

try {
    $pool = new ChannelBrokerPool($idleTimeout);
    $pool->listen(0);
    $port = $pool->getPort();
} catch (Throwable $e) {
    fwrite(
        STDERR,
        "channel_broker_pool_process: Failed to start pool: " .
            $e->getMessage() .
            "\n",
    );
    exit(1);
}

// ── Signal readiness to the parent process ────────────────────────────

fwrite(STDOUT, "READY:{$port}\n");
fflush(STDOUT);

// ── Install signal handlers (POSIX only) ──────────────────────────────

$signalReceived = false;

if (function_exists("pcntl_async_signals")) {
    pcntl_async_signals(true);

    $signalHandler = function (int $signo) use ($pool, &$signalReceived): void {
        $signalReceived = true;
        $pool->stop();
        fwrite(
            STDERR,
            "channel_broker_pool_process: Received signal {$signo}, shutting down\n",
        );
    };

    if (function_exists("pcntl_signal")) {
        pcntl_signal(SIGTERM, $signalHandler);
        pcntl_signal(SIGINT, $signalHandler);
    }
}

// ── Make STDIN non-blocking for parent-death detection ────────────────

@stream_set_blocking(STDIN, false);

/**
 * Check if STDIN has reached EOF (parent died / closed the pipe).
 * Non-blocking check — returns true if parent is gone.
 */
function isParentGone(): bool
{
    if (!defined("STDIN") || !is_resource(STDIN)) {
        return true;
    }

    if (feof(STDIN)) {
        return true;
    }

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

$parentCheckCounter = 0;
$parentCheckEvery = 20; // Every 20 ticks (~1 second at 50ms select timeout)

fwrite(
    STDERR,
    "channel_broker_pool_process: Pool started on port {$port} (idle_timeout={$idleTimeout}s)\n",
);

while ($pool->isRunning()) {
    $shouldContinue = $pool->tick(0.05);

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
                "channel_broker_pool_process: Parent process gone, shutting down\n",
            );
            break;
        }
    }

    if ($signalReceived) {
        break;
    }
}

// ── Cleanup ───────────────────────────────────────────────────────────

fwrite(
    STDERR,
    "channel_broker_pool_process: Pool shutting down (managed " .
        $pool->getChannelCount() .
        " channel(s))\n",
);

$pool->cleanup();

exit(0);
