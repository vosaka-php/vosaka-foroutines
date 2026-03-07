<?php

/**
 * Persistent Worker Loop Script (TCP Socket Mode — Windows / Fallback)
 *
 * This script is the socket-mode counterpart of worker_loop.php. It is
 * spawned once per worker slot by WorkerPool::spawnSocketWorker() and stays
 * alive for the entire lifetime of the pool.
 *
 * Instead of using STDIN/STDOUT pipes for communication (which are broken
 * on Windows — stream_set_blocking(false) silently fails, stream_select()
 * always returns 1, and stream_set_timeout() is ignored by fgets()), this
 * script communicates with the parent over a TCP loopback socket.
 *
 * Boot sequence:
 *   1. Parent creates a TCP server on 127.0.0.1:<ephemeral-port>.
 *   2. Parent spawns this script via proc_open with the port as $argv[1].
 *   3. This script connects to 127.0.0.1:<port>.
 *   4. Parent accepts the connection (stream_socket_accept).
 *   5. This script sends "READY\n" over the TCP socket.
 *   6. Parent polls with stream_select() (works correctly on TCP sockets).
 *
 * Protocol (line-based, each message is a single line over TCP):
 *
 *   Parent → Child (TCP):
 *     TASK:<base64-encoded serialized closure>\n
 *     SHUTDOWN\n
 *
 *   Child → Parent (TCP):
 *     RESULT:<base64-encoded serialized result>\n
 *     ERROR:<base64-encoded serialized error array>\n
 *     READY\n
 *
 * The worker also monitors STDIN for EOF — when the parent process dies
 * it closes the stdin pipe, and this script detects that and exits
 * gracefully (preventing orphaned worker processes).
 *
 * All diagnostic output goes to STDERR for debugging purposes.
 */

declare(strict_types=1);

ini_set("display_errors", "off");
ini_set("log_errors", "1");
error_reporting(E_ALL);

// ── Bootstrap: find and load Composer autoloader ──────────────────────

require_once __DIR__ . '/script_functions.php';
require_once findAutoload(__DIR__);

\vosaka\foroutines\WorkerPoolState::$isWorker = true;

use Laravel\SerializableClosure\SerializableClosure;

// ── Validate arguments ────────────────────────────────────────────────

if (!isset($argv[1]) || !is_numeric($argv[1])) {
    fwrite(STDERR, "worker_socket_loop: Missing or invalid port argument\n");
    fwrite(STDERR, "Usage: php worker_socket_loop.php <port>\n");
    exit(1);
}

$port = (int) $argv[1];

if ($port <= 0 || $port > 65535) {
    fwrite(STDERR, "worker_socket_loop: Port out of range: {$port}\n");
    exit(1);
}

// ── Connect to the parent's TCP server ────────────────────────────────

$maxRetries = 50; // 50 × 100ms = 5 seconds max
$retryDelay = 100000; // 100ms in microseconds
$conn = false;

for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
    $conn = @stream_socket_client(
        "tcp://127.0.0.1:{$port}",
        $errno,
        $errstr,
        5.0, // connection timeout in seconds
        STREAM_CLIENT_CONNECT,
    );

    if ($conn !== false) {
        break;
    }

    // Parent may not be ready yet — retry after a short delay
    usleep($retryDelay);
}

if ($conn === false) {
    fwrite(
        STDERR,
        "worker_socket_loop: Failed to connect to 127.0.0.1:{$port}: [{$errno}] {$errstr}\n",
    );
    exit(1);
}

// Set TCP_NODELAY to reduce latency on small messages (available on most platforms)
// Silence errors in case the option is not supported.
@stream_context_set_option(
    stream_context_create(),
    "socket",
    "tcp_nodelay",
    true,
);

// ── Helpers ───────────────────────────────────────────────────────────

/**
 * Send a line to the parent over TCP.
 *
 * @param resource $conn The TCP connection stream.
 * @param string   $line The line to send (without trailing newline).
 */
function worker_send($conn, string $line): void
{
    $data = $line . "\n";
    $len = strlen($data);
    $written = 0;

    while ($written < $len) {
        $bytes = @fwrite($conn, substr($data, $written));
        if ($bytes === false || $bytes === 0) {
            // Connection broken — exit
            fwrite(
                STDERR,
                "worker_socket_loop: fwrite failed, connection broken\n",
            );
            exit(1);
        }
        $written += $bytes;
    }

    @fflush($conn);
}

/**
 * Check if STDIN has reached EOF (parent died / closed the pipe).
 * Non-blocking check — returns true if parent is gone.
 *
 * IMPORTANT — Windows caveat:
 *   On Windows, proc_open pipes do NOT support non-blocking I/O.
 *   stream_set_blocking(STDIN, false) silently fails, stream_select()
 *   on a pipe always returns 1 (ready), and fread() will BLOCK
 *   indefinitely waiting for data that never comes. We therefore
 *   MUST NOT call fread(STDIN) on Windows. We rely solely on feof()
 *   which correctly returns true when the parent closes the pipe.
 */
function is_parent_gone(): bool
{
    if (!defined("STDIN") || !is_resource(STDIN)) {
        return true;
    }

    // feof() is safe on all platforms — it returns true once the parent
    // closes the stdin pipe (on normal exit, fatal error, or kill).
    if (feof(STDIN)) {
        return true;
    }

    // On non-Windows platforms, we can safely use stream_select + fread
    // to consume any stray data written to our stdin (shouldn't happen
    // in normal operation, but defensive). On Windows we skip this
    // entirely because fread() would block forever.
    if (strncasecmp(PHP_OS, "WIN", 3) !== 0) {
        $read = [STDIN];
        $write = null;
        $except = null;
        $ready = @stream_select($read, $write, $except, 0, 0);

        if ($ready === false) {
            // stream_select error — assume parent is gone
            return true;
        }

        if ($ready > 0) {
            // Consume and discard any stray data, then re-check EOF
            @fread(STDIN, 1024);
            if (feof(STDIN)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Execute a task closure and send the result back to the parent.
 *
 * @param resource $conn           The TCP connection stream.
 * @param string   $base64Payload  Base64-encoded serialized closure.
 */
function worker_execute_task($conn, string $base64Payload): void
{
    try {
        $decoded = base64_decode($base64Payload, true);
        if ($decoded === false) {
            throw new RuntimeException("Failed to base64_decode task payload");
        }

        /** @var SerializableClosure $sc */
        $sc = unserialize($decoded);
        $closure = $sc->getClosure();

        // Execute the closure
        $result = \vosaka\foroutines\CallableUtils::executeTask($closure);

        $serializedResult = serialize($result);
        $encodedResult = base64_encode($serializedResult);

        worker_send($conn, "RESULT:" . $encodedResult);
    } catch (Throwable $e) {
        $errorData = \vosaka\foroutines\CallableUtils::buildWorkerError($e);

        $encodedError = base64_encode(serialize($errorData));
        worker_send($conn, "ERROR:" . $encodedError);

        fwrite(STDERR, "worker_socket_loop error: " . $e->getMessage() . "\n");
    }

    // Signal readiness for next task
    worker_send($conn, "READY");
}

/**
 * Execute a batch of task closures and send all results back to the parent.
 *
 * Protocol:
 *   Input:  BATCH:<base64(json([{"payload":"<base64closure>","id":<int>}, ...]))>
 *   Output: BATCH_RESULTS:<base64(json([{"id":<int>,"type":"result"|"error","payload":"<base64>"}, ...]))>
 *           READY
 *
 * @param resource $conn           The TCP connection stream.
 * @param string   $base64Payload  Base64-encoded JSON array of task entries.
 */
function worker_execute_batch($conn, string $base64Payload): void
{
    $results = [];

    try {
        $decoded = base64_decode($base64Payload, true);
        if ($decoded === false) {
            throw new RuntimeException("Failed to base64_decode batch payload");
        }

        $tasks = json_decode($decoded, true);
        if (!is_array($tasks)) {
            throw new RuntimeException("Failed to json_decode batch payload");
        }

        foreach ($tasks as $taskEntry) {
            $taskId = $taskEntry["id"] ?? null;
            $taskPayload = $taskEntry["payload"] ?? null;

            if ($taskId === null || $taskPayload === null) {
                continue;
            }

            try {
                $closureDecoded = base64_decode($taskPayload, true);
                if ($closureDecoded === false) {
                    throw new RuntimeException(
                        "Failed to base64_decode task payload",
                    );
                }

                /** @var SerializableClosure $sc */
                $sc = unserialize($closureDecoded);
                $closure = $sc->getClosure();

                // Execute the closure
                $result = \vosaka\foroutines\CallableUtils::executeTask($closure);

                $results[] = [
                    "id" => $taskId,
                    "type" => "result",
                    "payload" => base64_encode(serialize($result)),
                ];
            } catch (Throwable $e) {
                $errorData = \vosaka\foroutines\CallableUtils::buildWorkerError($e);
                $results[] = [
                    "id" => $taskId,
                    "type" => "error",
                    "payload" => base64_encode(serialize($errorData)),
                ];

                fwrite(
                    STDERR,
                    "worker_socket_loop batch error: " .
                        $e->getMessage() .
                        "\n",
                );
            }
        }
    } catch (Throwable $e) {
        fwrite(
            STDERR,
            "worker_socket_loop: batch decode failed: " .
                $e->getMessage() .
                "\n",
        );
    }

    // Send all results in a single message
    $batchJson = json_encode($results, JSON_UNESCAPED_SLASHES);
    $batchEncoded = base64_encode($batchJson);
    worker_send($conn, "BATCH_RESULTS:" . $batchEncoded);

    // Signal readiness for next task/batch
    worker_send($conn, "READY");
}

// ── Signal the parent that we are alive ───────────────────────────────

worker_send($conn, "READY");

// ── Make STDIN non-blocking for parent-death detection ────────────────
// On Windows this may silently fail, but is_parent_gone() handles that
// gracefully via stream_select + feof.
@stream_set_blocking(STDIN, false);

// ── Main loop ─────────────────────────────────────────────────────────

$buffer = "";

while (true) {
    // ── Check for data on the TCP socket (non-blocking) ───────────
    // Use stream_select to wait up to 50ms for incoming data. This
    // balances responsiveness with CPU usage. During the wait we also
    // get a chance to check if the parent is still alive.
    $read = [$conn];
    $write = null;
    $except = null;
    $ready = @stream_select($read, $write, $except, 0, 50000); // 50ms

    if ($ready === false) {
        // stream_select failed — connection likely broken
        fwrite(STDERR, "worker_socket_loop: stream_select failed\n");
        break;
    }

    if ($ready > 0) {
        $chunk = @fread($conn, 1048576); // 1 MB

        if ($chunk === false || $chunk === "") {
            // Connection closed by parent
            break;
        }

        $buffer .= $chunk;

        // Process all complete lines in the buffer
        while (($nlPos = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $nlPos);
            $buffer = substr($buffer, $nlPos + 1);
            $line = rtrim($line, "\r");

            if ($line === "") {
                continue;
            }

            // Graceful shutdown command
            if ($line === "SHUTDOWN") {
                @fclose($conn);
                exit(0);
            }

            // Task dispatch (single task)
            if (str_starts_with($line, "TASK:")) {
                $payload = substr($line, 5); // strip "TASK:" prefix
                worker_execute_task($conn, $payload);
                continue;
            }

            // Batch dispatch (multiple tasks)
            if (str_starts_with($line, "BATCH:")) {
                $payload = substr($line, 6); // strip "BATCH:" prefix
                worker_execute_batch($conn, $payload);
                continue;
            }

            // Unknown command — log and ignore
            fwrite(STDERR, "worker_socket_loop: unknown command: {$line}\n");
        }
    }

    // ── Check if parent is still alive ────────────────────────────
    // If the parent dies, the stdin pipe will be closed. We detect
    // this and exit to prevent orphaned worker processes.
    if (is_parent_gone()) {
        fwrite(STDERR, "worker_socket_loop: parent process gone, exiting\n");
        break;
    }

    // ── Check if TCP connection is still alive ────────────────────
    if (!is_resource($conn) || feof($conn)) {
        break;
    }
}

// ── Cleanup ───────────────────────────────────────────────────────────

if (is_resource($conn)) {
    @fclose($conn);
}

exit(0);
