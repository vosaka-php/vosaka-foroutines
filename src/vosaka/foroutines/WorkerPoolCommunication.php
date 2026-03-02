<?php

declare(strict_types=1);

namespace vosaka\foroutines;

/**
 * Handles all communication between the parent process and worker
 * processes in both fork mode (Unix sockets) and socket mode (TCP streams).
 *
 * Responsibilities:
 *   - sendToWorker()        — write a line to a worker (fork or socket mode)
 *   - readLinesFromWorker() — non-blocking read of complete lines from a worker
 *   - drainStderr()         — forward stderr output from socket-mode workers
 *   - socketWriteLine()     — low-level write to a \Socket (fork child helper)
 *   - waitForReady()        — block until a worker sends READY
 *
 * All methods are static and operate on the shared state held in
 * {@see WorkerPoolState}.
 *
 * @internal This class is not part of the public API.
 */
final class WorkerPoolCommunication
{
    /**
     * Send a line to a worker (handles both fork and socket modes).
     *
     * Fork mode:  blocking socket_write() on the Unix socket pair.
     * Socket mode: blocking fwrite() on the accepted TCP stream.
     *
     * In both cases the socket is temporarily set to blocking mode for
     * a reliable full write, then restored to non-blocking.
     *
     * @param int    $index Worker slot index.
     * @param string $line  The line to send (without trailing newline).
     * @return bool True if the send succeeded.
     */
    public static function sendToWorker(int $index, string $line): bool
    {
        $worker = WorkerPoolState::$workers[$index];
        $data = $line . "\n";

        if ($worker["mode"] === "fork") {
            $socket = $worker["socket"];
            socket_set_block($socket);
            $len = strlen($data);
            $written = 0;
            while ($written < $len) {
                $sent = @socket_write($socket, substr($data, $written));
                if ($sent === false) {
                    socket_set_nonblock($socket);
                    return false;
                }
                $written += $sent;
            }
            socket_set_nonblock($socket);
            return true;
        }

        // Socket mode — write to TCP connection
        $conn = $worker["conn"];
        if (!is_resource($conn)) {
            return false;
        }

        // Temporarily make blocking for reliable write
        stream_set_blocking($conn, true);
        $len = strlen($data);
        $written = 0;
        while ($written < $len) {
            $bytes = @fwrite($conn, substr($data, $written));
            if ($bytes === false || $bytes === 0) {
                stream_set_blocking($conn, false);
                return false;
            }
            $written += $bytes;
        }
        @fflush($conn);
        stream_set_blocking($conn, false);
        return true;
    }

    /**
     * Non-blocking read of complete lines from a worker.
     *
     * Fork mode:  socket_read() on the non-blocking Unix socket.
     * Socket mode: stream_select() + fread() on the non-blocking TCP
     *   stream. Both work correctly on all platforms because TCP sockets
     *   have proper non-blocking support even on Windows.
     *
     * Partial data is accumulated in {@see WorkerPoolState::$readBuffers}
     * and only complete newline-terminated lines are returned.
     *
     * @param int $index Worker slot index.
     * @return string[] Array of complete lines (without newline).
     */
    public static function readLinesFromWorker(int $index): array
    {
        $worker = WorkerPoolState::$workers[$index];
        $data = "";

        if ($worker["mode"] === "fork") {
            $socket = $worker["socket"];
            // Non-blocking read (socket is already non-blocking)
            $chunk = @socket_read($socket, 1048576);
            if ($chunk !== false && $chunk !== "") {
                $data = $chunk;
            }
        } else {
            // Socket mode — TCP stream, non-blocking.
            // Use stream_select() as a readability check, then fread().
            $conn = $worker["conn"];
            if (is_resource($conn)) {
                $read = [$conn];
                $write = null;
                $except = null;
                $ready = @stream_select($read, $write, $except, 0, 0);
                if ($ready !== false && $ready > 0) {
                    $chunk = @fread($conn, 1048576);
                    if ($chunk !== false && $chunk !== "") {
                        $data = $chunk;
                    }
                }
            }
        }

        if ($data === "") {
            return [];
        }

        // Accumulate into the read buffer and extract complete lines
        WorkerPoolState::$readBuffers[$index] =
            (WorkerPoolState::$readBuffers[$index] ?? "") . $data;
        $lines = [];

        while (
            ($nlPos = strpos(WorkerPoolState::$readBuffers[$index], "\n")) !==
            false
        ) {
            $line = substr(WorkerPoolState::$readBuffers[$index], 0, $nlPos);
            WorkerPoolState::$readBuffers[$index] = substr(
                WorkerPoolState::$readBuffers[$index],
                $nlPos + 1,
            );
            $line = rtrim($line, "\r");
            if ($line !== "") {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Drain stderr from a socket-mode worker for debugging purposes.
     *
     * On Windows, proc_open pipes do NOT support non-blocking I/O —
     * stream_set_blocking(false) silently fails, and fread() will block
     * indefinitely waiting for data. We therefore skip draining entirely
     * on Windows to avoid hanging the scheduler loop. On Linux/macOS
     * (where we use fork mode anyway) this is not an issue, but we
     * guard it for safety.
     *
     * @param int $index Worker slot index.
     */
    public static function drainStderr(int $index): void
    {
        // On Windows, proc_open stderr pipes are always blocking regardless
        // of stream_set_blocking(false). Reading from them when no data is
        // available will block forever and hang the entire scheduler loop.
        if (WorkerPoolState::isWindows()) {
            return;
        }

        $worker = WorkerPoolState::$workers[$index] ?? null;
        if ($worker === null || $worker["mode"] !== "socket") {
            return;
        }

        $stderr = $worker["stderr"] ?? null;
        if (!is_resource($stderr)) {
            return;
        }

        // stderr was set to non-blocking in SocketWorkerManager::spawn()
        $errChunk = @fread($stderr, 65536);
        if ($errChunk !== false && $errChunk !== "") {
            fwrite(STDERR, $errChunk);
        }
    }

    /**
     * Write a line to a \Socket (with newline terminator).
     *
     * This is used by fork-based child workers to send messages back
     * to the parent process over the Unix socket pair.
     *
     * @param \Socket $socket The socket to write to.
     * @param string  $line   The line to send (without trailing newline).
     */
    public static function socketWriteLine(\Socket $socket, string $line): void
    {
        $data = $line . "\n";
        $len = strlen($data);
        $written = 0;
        while ($written < $len) {
            $sent = @socket_write($socket, substr($data, $written));
            if ($sent === false) {
                break;
            }
            $written += $sent;
        }
    }

    /**
     * Wait for the READY signal from a newly spawned worker.
     *
     * Fork mode: polls via non-blocking socket_read + line buffer.
     * Socket mode: polls via stream_select + fread on TCP socket.
     *
     * Both use readLinesFromWorker() which handles the mode internally.
     *
     * @param int   $index          Worker slot index.
     * @param float $timeoutSeconds Maximum seconds to wait.
     * @throws \Exception If the worker doesn't become ready in time.
     */
    public static function waitForReady(
        int $index,
        float $timeoutSeconds,
    ): void {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $lines = self::readLinesFromWorker($index);
            foreach ($lines as $line) {
                if ($line === "READY") {
                    return;
                }
            }

            if (!WorkerLifecycle::isWorkerAlive($index)) {
                throw new \Exception(
                    "WorkerPool: Worker {$index} died during startup",
                );
            }

            usleep(5_000); // 5ms
        }

        throw new \Exception(
            "WorkerPool: Worker {$index} did not become ready within {$timeoutSeconds}s",
        );
    }

    /** Prevent instantiation */
    private function __construct() {}
}
