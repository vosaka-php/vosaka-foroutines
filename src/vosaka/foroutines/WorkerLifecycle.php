<?php

declare(strict_types=1);

namespace vosaka\foroutines;

/**
 * Manages the lifecycle of individual worker processes: health checks
 * and respawning dead workers.
 *
 * Responsibilities:
 *   - isWorkerAlive() — check whether a worker process is still running
 *   - respawnWorker() — clean up a dead worker slot and spawn a replacement
 *
 * Works with both fork-based workers (Linux/macOS) and socket-based
 * workers (Windows / fallback). All methods are static and operate on
 * the shared state held in {@see WorkerPoolState}.
 *
 * @internal This class is not part of the public API.
 */
final class WorkerLifecycle
{
    /**
     * Check if a worker process is still alive.
     *
     * Fork mode:  uses pcntl_waitpid() with WNOHANG to check if the
     *             child has exited without blocking.
     * Socket mode: uses proc_get_status() to query the process handle.
     *
     * @param int $index Worker slot index.
     * @return bool True if the worker process is still running.
     */
    public static function isWorkerAlive(int $index): bool
    {
        $worker = WorkerPoolState::$workers[$index] ?? null;
        if ($worker === null) {
            return false;
        }

        if ($worker["mode"] === "fork") {
            $pid = $worker["pid"] ?? null;
            if ($pid === null || $pid <= 0) {
                return false;
            }
            $status = 0;
            $res = pcntl_waitpid($pid, $status, WNOHANG);
            if ($res === $pid || $res === -1) {
                return false;
            }
            return true;
        }

        // Socket mode
        $process = $worker["process"] ?? null;
        if (!is_resource($process)) {
            return false;
        }
        $status = proc_get_status($process);
        return $status["running"];
    }

    /**
     * Respawn a dead worker at the given index.
     *
     * 1. Cleans up all resources associated with the dead worker slot
     *    (sockets, pipes, process handles).
     * 2. Resets the read buffer for the slot.
     * 3. Spawns a new worker using the appropriate strategy (fork or socket).
     * 4. If respawning fails, the slot is marked as non-busy with a null
     *    pid/process so that it can be retried later.
     *
     * @param int $index Worker slot index.
     */
    public static function respawnWorker(int $index): void
    {
        // ── Backoff gate: skip if cooldown hasn't elapsed ────────────
        $now = microtime(true);
        $nextAllowed = WorkerPoolState::$respawnNextAllowed[$index] ?? 0.0;
        if ($now < $nextAllowed) {
            // Still cooling down — caller will retry on the next tick
            return;
        }

        // ── Circuit-breaker: too many consecutive failures ───────────
        $attempts = WorkerPoolState::$respawnAttempts[$index] ?? 0;
        if ($attempts >= WorkerPoolState::$maxRespawnAttempts) {
            error_log(
                "WorkerPool: Worker {$index} exceeded max respawn attempts " .
                "({$attempts}/" . WorkerPoolState::$maxRespawnAttempts .
                "). Removing worker slot.",
            );
            // Remove the worker slot entirely
            unset(WorkerPoolState::$workers[$index]);
            unset(WorkerPoolState::$activeTasks[$index]);
            unset(WorkerPoolState::$readBuffers[$index]);
            unset(WorkerPoolState::$workerIdleSince[$index]);
            unset(WorkerPoolState::$respawnAttempts[$index]);
            unset(WorkerPoolState::$respawnNextAllowed[$index]);
            return;
        }

        // ── Cleanup old worker resources ─────────────────────────────
        $worker = WorkerPoolState::$workers[$index] ?? null;

        if ($worker !== null) {
            if ($worker["mode"] === "fork") {
                self::cleanupForkWorker($worker);
            } else {
                self::cleanupSocketWorker($worker);
            }
        }

        WorkerPoolState::$readBuffers[$index] = "";

        // ── Increment attempt counter & compute next backoff ─────────
        $attempts++;
        WorkerPoolState::$respawnAttempts[$index] = $attempts;

        // Exponential backoff: base × 2^(attempt-1), capped at 30s
        $delayMs = min(
            WorkerPoolState::$respawnBaseDelayMs * (1 << ($attempts - 1)),
            30_000,
        );
        WorkerPoolState::$respawnNextAllowed[$index] =
            $now + ($delayMs / 1000.0);

        try {
            if (WorkerPoolState::canUseFork()) {
                ForkWorkerManager::spawn($index);
            } else {
                SocketWorkerManager::spawn($index);
            }
        } catch (\Throwable $e) {
            WorkerPoolState::$workers[$index] = [
                "busy" => false,
                "pid" => null,
                "mode" => WorkerPoolState::canUseFork() ? "fork" : "socket",
            ];
            error_log(
                "WorkerPool: Failed to respawn worker {$index} " .
                "(attempt {$attempts}): " . $e->getMessage(),
            );
        }
    }

    /**
     * Reset the backoff counters for a worker slot.
     *
     * Called when a worker successfully completes a task, proving it is
     * healthy and breaking the consecutive-failure streak.
     *
     * @param int $index Worker slot index.
     */
    public static function resetBackoff(int $index): void
    {
        unset(WorkerPoolState::$respawnAttempts[$index]);
        unset(WorkerPoolState::$respawnNextAllowed[$index]);
    }

    /**
     * Clean up resources for a dead fork-based worker.
     *
     * Closes the parent-side Unix socket and reaps the child process
     * (non-blocking waitpid to prevent zombie accumulation).
     *
     * @param array<string, mixed> $worker The worker slot data.
     */
    private static function cleanupForkWorker(array $worker): void
    {
        $socket = $worker["socket"] ?? null;
        if ($socket !== null && $socket instanceof \Socket) {
            @socket_close($socket);
        }

        $pid = $worker["pid"] ?? 0;
        if ($pid > 0) {
            pcntl_waitpid($pid, $status, WNOHANG);
        }
    }

    /**
     * Clean up resources for a dead socket-based worker.
     *
     * Closes the TCP connection, all proc_open pipes (stdin, stdout,
     * stderr), and terminates + closes the process handle.
     *
     * @param array<string, mixed> $worker The worker slot data.
     */
    private static function cleanupSocketWorker(array $worker): void
    {
        // Close TCP connection
        if (is_resource($worker["conn"] ?? null)) {
            @fclose($worker["conn"]);
        }

        // Close pipes
        foreach (["stdin", "stdout", "stderr"] as $pipe) {
            if (is_resource($worker[$pipe] ?? null)) {
                @fclose($worker[$pipe]);
            }
        }

        // Terminate and close the process handle
        if (is_resource($worker["process"] ?? null)) {
            @proc_terminate($worker["process"]);
            @proc_close($worker["process"]);
        }
    }

    /** Prevent instantiation */
    private function __construct()
    {
    }
}
