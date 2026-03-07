<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Exception;
use Laravel\SerializableClosure\SerializableClosure;

/**
 * Manages fork-based worker processes (Linux/macOS with pcntl + sockets).
 *
 * Fork workers use Unix socket pairs for bidirectional communication.
 * The child process loops reading from its end of the socket pair,
 * executes closures, and writes results back. The parent keeps its
 * end for sending tasks and reading results.
 *
 * Protocol (over the socket, newline-delimited):
 *   Parent → Child:  TASK:<base64(serialize(SerializableClosure))>\n
 *                     SHUTDOWN\n
 *   Child → Parent:  READY\n
 *                     RESULT:<base64(serialize(result))>\n
 *                     ERROR:<base64(serialize(errorArray))>\n
 *
 * Responsibilities:
 *   - spawn()                  — create a fork-based worker at a given slot
 *   - shutdown()               — gracefully stop a fork-based worker
 *   - forkWorkerLoop()         — the main loop run inside the child process
 *   - forkWorkerExecuteTask()  — execute a single task in the child process
 *
 * All methods are static and operate on the shared state held in
 * {@see WorkerPoolState}.
 *
 * @internal This class is not part of the public API.
 */
final class ForkWorkerManager
{
    /**
     * Spawns a single fork-based worker.
     *
     * Creates a Unix socket pair. The child process loops reading from
     * its end, executes closures, and writes results back. The parent
     * keeps its end for sending tasks and reading results.
     *
     * @param int $index Worker slot index.
     * @throws Exception If socket_create_pair() or pcntl_fork() fails.
     */
    public static function spawn(int $index): void
    {
        $sockets = [];
        if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets)) {
            throw new Exception(
                "WorkerPool: Failed to create socket pair for worker {$index}",
            );
        }

        [$parentSocket, $childSocket] = $sockets;

        $pid = pcntl_fork();

        if ($pid === -1) {
            socket_close($parentSocket);
            socket_close($childSocket);
            throw new Exception(
                "WorkerPool: pcntl_fork() failed for worker {$index}",
            );
        }

        if ($pid === 0) {
            // ─── CHILD PROCESS ───────────────────────────────────
            socket_close($parentSocket);

            // Reset inherited state
            WorkerPoolState::$workers = [];
            WorkerPoolState::$pendingTasks = [];
            WorkerPoolState::$activeTasks = [];
            WorkerPoolState::$returns = [];
            WorkerPoolState::$readBuffers = [];
            WorkerPoolState::$booted = false;
            Launch::$queue = new \SplQueue();
            Launch::$activeCount = 0;
            AsyncIO::resetState();
            EventLoop::resetState();

            WorkerPoolState::$isWorker = true;

            self::forkWorkerLoop($childSocket);
            // Never returns
        }

        // ─── PARENT PROCESS ─────────────────────────────────────
        socket_close($childSocket);

        // Set parent socket to non-blocking for polling
        socket_set_nonblock($parentSocket);

        WorkerPoolState::$workers[$index] = [
            "busy" => false,
            "pid" => $pid,
            "socket" => $parentSocket,
            "mode" => "fork",
        ];
        WorkerPoolState::$readBuffers[$index] = "";

        // Wait for READY signal from child (with timeout)
        WorkerPoolCommunication::waitForReady($index, 5.0);
    }

    /**
     * Shutdown a fork-based worker. Send SHUTDOWN, wait for exit, close socket.
     *
     * Attempts a graceful shutdown by sending the SHUTDOWN command over
     * the socket. If the child doesn't exit within 2 seconds, it is
     * forcibly killed with SIGKILL.
     *
     * @param int $index Worker slot index.
     */
    public static function shutdown(int $index): void
    {
        $worker = WorkerPoolState::$workers[$index] ?? null;
        if ($worker === null) {
            return;
        }

        $socket = $worker["socket"] ?? null;
        $pid = $worker["pid"] ?? null;

        if ($socket !== null && $socket instanceof \Socket) {
            try {
                socket_set_block($socket);
                WorkerPoolCommunication::socketWriteLine($socket, "SHUTDOWN");
            } catch (\Throwable) {
            }
            @socket_close($socket);
        }

        if ($pid !== null && $pid > 0) {
            $deadline = microtime(true) + 2.0;
            while (microtime(true) < $deadline) {
                $status = 0;
                $res = pcntl_waitpid($pid, $status, WNOHANG);
                if ($res === $pid || $res === -1) {
                    return;
                }
                usleep(10_000);
            }
            if (function_exists("posix_kill")) {
                posix_kill($pid, 9);
            }
            pcntl_waitpid($pid, $status);
        }
    }

    /**
     * The main loop executed by each fork-based child worker.
     * Runs forever until SHUTDOWN or parent closes the socket.
     *
     * @param \Socket $socket The child's end of the socket pair.
     */
    private static function forkWorkerLoop(\Socket $socket): never
    {
        // Signal parent that we are alive
        WorkerPoolCommunication::socketWriteLine($socket, "READY");

        $buffer = "";

        while (true) {
            // Blocking read — worker idles here waiting for a task
            $data = @socket_read($socket, 65536);

            if ($data === false || $data === "") {
                // Parent closed the socket → exit
                break;
            }

            $buffer .= $data;

            // Process complete lines
            while (($nlPos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $nlPos);
                $buffer = substr($buffer, $nlPos + 1);
                $line = rtrim($line, "\r");

                if ($line === "") {
                    continue;
                }

                if ($line === "SHUTDOWN") {
                    socket_close($socket);
                    exit(0);
                }

                if (str_starts_with($line, "TASK:")) {
                    $payload = substr($line, 5);
                    self::forkWorkerExecuteTask($socket, $payload);
                    continue;
                }

                if (str_starts_with($line, "BATCH:")) {
                    $payload = substr($line, 6);
                    self::forkWorkerExecuteBatch($socket, $payload);
                    continue;
                }
            }
        }

        socket_close($socket);
        exit(0);
    }

    /**
     * Execute a single task in the fork-based worker child process.
     *
     * Deserializes the closure from the base64-encoded payload, executes
     * it, and sends the result (or error) back to the parent.
     *
     * After execution, sends a READY signal to indicate availability
     * for the next task.
     *
     * @param \Socket $socket        The child's socket to write results to.
     * @param string  $base64Payload Base64-encoded serialized closure.
     */
    private static function forkWorkerExecuteTask(
        \Socket $socket,
        string $base64Payload,
    ): void {
        try {
            $decoded = base64_decode($base64Payload, true);
            if ($decoded === false) {
                throw new \RuntimeException(
                    "Failed to base64_decode task payload",
                );
            }

            /** @var SerializableClosure $sc */
            $sc = unserialize($decoded);
            $closure = $sc->getClosure();

            $result = \vosaka\foroutines\CallableUtils::executeTask($closure);

            $encoded = base64_encode(serialize($result));
            WorkerPoolCommunication::socketWriteLine(
                $socket,
                "RESULT:" . $encoded,
            );
        } catch (\Throwable $e) {
            $errorData = \vosaka\foroutines\CallableUtils::buildWorkerError($e);
            $encoded = base64_encode(serialize($errorData));
            WorkerPoolCommunication::socketWriteLine(
                $socket,
                "ERROR:" . $encoded,
            );
        }

        // Signal readiness for next task
        WorkerPoolCommunication::socketWriteLine($socket, "READY");
    }

    /**
     * Execute a batch of tasks in the fork-based worker child process.
     *
     * Deserializes the batch payload (base64 → JSON array of task entries),
     * executes each closure sequentially, collects all results, and sends
     * them back as a single BATCH_RESULTS: message followed by READY.
     *
     * Protocol:
     *   Input:  BATCH:<base64(json([{"payload":"<base64closure>","id":<int>}, ...]))>
     *   Output: BATCH_RESULTS:<base64(json([{"id":<int>,"type":"result"|"error","payload":"<base64>"}, ...]))>
     *           READY
     *
     * @param \Socket $socket        The child's socket to write results to.
     * @param string  $base64Payload Base64-encoded JSON array of task entries.
     */
    private static function forkWorkerExecuteBatch(
        \Socket $socket,
        string $base64Payload,
    ): void {
        $results = [];

        try {
            $decoded = base64_decode($base64Payload, true);
            if ($decoded === false) {
                throw new \RuntimeException(
                    "Failed to base64_decode batch payload",
                );
            }

            $tasks = json_decode($decoded, true);
            if (!is_array($tasks)) {
                throw new \RuntimeException(
                    "Failed to json_decode batch payload",
                );
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
                        throw new \RuntimeException(
                            "Failed to base64_decode task payload",
                        );
                    }

                    /** @var SerializableClosure $sc */
                    $sc = unserialize($closureDecoded);
                    $closure = $sc->getClosure();

                    $result = \vosaka\foroutines\CallableUtils::executeTask($closure);

                    $results[] = [
                        "id" => $taskId,
                        "type" => "result",
                        "payload" => base64_encode(serialize($result)),
                    ];
                } catch (\Throwable $e) {
                    $errorData = \vosaka\foroutines\CallableUtils::buildWorkerError($e);
                    $results[] = [
                        "id" => $taskId,
                        "type" => "error",
                        "payload" => base64_encode(serialize($errorData)),
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Entire batch decode failed — nothing we can return per-task
            // The parent will handle missing results via dead-worker check.
        }

        // Send all results in a single message
        $batchJson = json_encode($results, JSON_UNESCAPED_SLASHES);
        $batchEncoded = base64_encode($batchJson);
        WorkerPoolCommunication::socketWriteLine(
            $socket,
            "BATCH_RESULTS:" . $batchEncoded,
        );

        // Signal readiness for next task/batch
        WorkerPoolCommunication::socketWriteLine($socket, "READY");
    }

    /** Prevent instantiation */
    private function __construct() {}
}
