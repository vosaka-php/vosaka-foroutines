<?php

declare(strict_types=1);

namespace vosaka\foroutines;

/**
 * Manages the lifecycle of individual worker processes: health checks
 * and respawning dead workers with exponential backoff.
 *
 * Responsibilities:
 *   - isWorkerAlive() — check whether a worker process is still running
 *   - respawnWorker() — clean up a dead worker slot and spawn a replacement
 *                       with exponential backoff to prevent CPU spin on
 *                       repeatedly crashing workers
 *
 * Backoff strategy:
 *   When a worker crashes and needs respawning, the backoff delay increases
 *   exponentially: baseDelay * 2^(attempts-1), capped at maxDelay.
 *   After maxRetries consecutive failures, the worker slot is permanently
 *   marked as dead and no further respawn attempts are made.
 *
 *   If a worker successfully becomes READY after respawn, its failure
 *   counter is reset to zero via resetBackoff(), so transient failures
 *   don't accumulate over the lifetime of the pool.
 *
 * Works with both fork-based workers (Linux/macOS) and socket-based
 * workers (Windows / fallback). All methods are static and operate on
 * the shared state held in {@see WorkerPoolState}.
 *
 * @internal This class is not part of the public API.
 */
final class WorkerLifecycle
{
    // ─── Backoff configuration ───────────────────────────────────────

    /**
     * Maximum number of consecutive respawn attempts before giving up.
     *
     * After this many failures in a row, the worker slot is considered
     * permanently dead and will not be respawned again. The slot remains
     * in WorkerPoolState::$workers with pid=null/process=null so that
     * dynamic scaling or manual intervention can reclaim it.
     *
     * @var int
     */
    private static int $maxRetries = 5;

    /**
     * Base delay in microseconds for the first backoff sleep.
     *
     * Each subsequent consecutive failure doubles the delay:
     *   attempt 1: 50ms
     *   attempt 2: 100ms
     *   attempt 3: 200ms
     *   attempt 4: 400ms
     *   attempt 5: 800ms (capped at maxDelay)
     *
     * @var int
     */
    private static int $baseDelayUs = 50_000; // 50ms

    /**
     * Maximum backoff delay in microseconds (cap).
     *
     * The exponential delay will never exceed this value, preventing
     * excessively long waits between respawn attempts.
     *
     * @var int
     */
    private static int $maxDelayUs = 2_000_000; // 2 seconds

    /**
     * Per-worker consecutive failure counter.
     *
     * Maps worker index => number of consecutive respawn failures.
     * Reset to 0 when a respawn succeeds (worker sends READY).
     *
     * @var array<int, int>
     */
    private static array $respawnAttempts = [];

    /**
     * Per-worker timestamp of the last respawn attempt.
     *
     * Used to enforce backoff delay: if the elapsed time since the
     * last attempt is less than the computed backoff, the respawn
     * is deferred (returns early without spawning).
     *
     * @var array<int, float>
     */
    private static array $lastRespawnTime = [];

    /**
     * Per-worker permanent death flag.
     *
     * When a worker exceeds $maxRetries consecutive failures, this
     * flag is set to true and no further respawn attempts are made.
     *
     * @var array<int, bool>
     */
    private static array $permanentlyDead = [];

    // ─── Public configuration API ────────────────────────────────────

    /**
     * Set the maximum number of consecutive respawn retries.
     *
     * @param int $retries Must be >= 1.
     */
    public static function setMaxRetries(int $retries): void
    {
        self::$maxRetries = max(1, $retries);
    }

    /**
     * Get the current max retries setting.
     *
     * @return int
     */
    public static function getMaxRetries(): int
    {
        return self::$maxRetries;
    }

    /**
     * Set the base backoff delay in milliseconds.
     *
     * @param int $ms Base delay in milliseconds (must be >= 1).
     */
    public static function setBaseDelay(int $ms): void
    {
        self::$baseDelayUs = max(1_000, $ms * 1_000);
    }

    /**
     * Set the maximum backoff delay cap in milliseconds.
     *
     * @param int $ms Maximum delay in milliseconds (must be >= 1).
     */
    public static function setMaxDelay(int $ms): void
    {
        self::$maxDelayUs = max(1_000, $ms * 1_000);
    }

    /**
     * Check whether a worker has been permanently marked as dead
     * (exceeded max retries).
     *
     * @param int $index Worker slot index.
     * @return bool True if the worker is permanently dead.
     */
    public static function isPermanentlyDead(int $index): bool
    {
        return self::$permanentlyDead[$index] ?? false;
    }

    /**
     * Get the number of consecutive respawn attempts for a worker.
     *
     * @param int $index Worker slot index.
     * @return int Number of consecutive failures (0 = healthy).
     */
    public static function getRespawnAttempts(int $index): int
    {
        return self::$respawnAttempts[$index] ?? 0;
    }

    /**
     * Reset the backoff state for a specific worker.
     *
     * Call this after a worker has been successfully respawned and
     * confirmed READY, so that transient failures don't accumulate
     * over the pool's lifetime.
     *
     * @param int $index Worker slot index.
     */
    public static function resetBackoff(int $index): void
    {
        self::$respawnAttempts[$index] = 0;
        unset(self::$lastRespawnTime[$index]);
        unset(self::$permanentlyDead[$index]);
    }

    /**
     * Reset all backoff state for all workers.
     *
     * Used during pool shutdown/reset and by ForkProcess after
     * pcntl_fork() to clear inherited state.
     */
    public static function resetAllBackoff(): void
    {
        self::$respawnAttempts = [];
        self::$lastRespawnTime = [];
        self::$permanentlyDead = [];
    }

    // ─── Health check ────────────────────────────────────────────────

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

    // ─── Respawn with backoff ────────────────────────────────────────

    /**
     * Respawn a dead worker at the given index with exponential backoff.
     *
     * Backoff behavior:
     *   1. If the worker has been permanently marked as dead (exceeded
     *      $maxRetries), logs a warning and returns immediately.
     *   2. Computes the backoff delay: $baseDelayUs * 2^(attempts-1),
     *      capped at $maxDelayUs. If insufficient time has elapsed
     *      since the last attempt, returns early (deferred respawn).
     *   3. Cleans up all resources associated with the dead worker slot.
     *   4. Sleeps for the computed backoff delay.
     *   5. Attempts to spawn a new worker.
     *   6. On success: resets the failure counter to 0.
     *      On failure: increments the counter; if it exceeds $maxRetries,
     *      marks the worker as permanently dead.
     *
     * @param int $index Worker slot index.
     */
    public static function respawnWorker(int $index): void
    {
        // Check if this worker has been permanently retired
        if (self::$permanentlyDead[$index] ?? false) {
            return;
        }

        $attempts = self::$respawnAttempts[$index] ?? 0;

        // If we've already exhausted all retries, mark permanently dead
        if ($attempts >= self::$maxRetries) {
            self::$permanentlyDead[$index] = true;
            error_log(
                "WorkerPool: Worker {$index} permanently retired after " .
                    self::$maxRetries .
                    " consecutive failed respawn attempts.",
            );

            // Leave a tombstone slot so the pool knows this index is used up
            WorkerPoolState::$workers[$index] = [
                "busy" => false,
                "pid" => null,
                "mode" => WorkerPoolState::canUseFork() ? "fork" : "socket",
            ];
            return;
        }

        // Compute exponential backoff delay
        $backoffDelayUs = self::computeBackoffDelay($attempts);

        // Check if enough time has passed since the last respawn attempt.
        // If not, defer — the caller (TaskDispatcher::pollResults or similar)
        // will try again on the next tick. This avoids blocking the
        // scheduler loop with a long usleep.
        $now = microtime(true);
        $lastAttempt = self::$lastRespawnTime[$index] ?? 0.0;
        $elapsedUs = ($now - $lastAttempt) * 1_000_000;

        if ($attempts > 0 && $elapsedUs < $backoffDelayUs) {
            // Not enough time has elapsed — defer this respawn to a later tick.
            // This is non-blocking: the scheduler continues processing other
            // workers and tasks instead of sleeping.
            return;
        }

        // Record this attempt timestamp
        self::$lastRespawnTime[$index] = $now;

        // Clean up the dead worker's resources
        $worker = WorkerPoolState::$workers[$index] ?? null;

        if ($worker !== null) {
            if ($worker["mode"] === "fork") {
                self::cleanupForkWorker($worker);
            } else {
                self::cleanupSocketWorker($worker);
            }
        }

        WorkerPoolState::$readBuffers[$index] = "";

        // Increment attempt counter BEFORE the spawn attempt
        $attempts++;
        self::$respawnAttempts[$index] = $attempts;

        // Log the respawn attempt with backoff info
        $backoffMs = (int) round($backoffDelayUs / 1_000);
        $maxRetries = self::$maxRetries;
        error_log(
            "WorkerPool: Respawning worker {$index} " .
                "(attempt {$attempts}/{$maxRetries}, backoff {$backoffMs}ms)",
        );

        try {
            if (WorkerPoolState::canUseFork()) {
                ForkWorkerManager::spawn($index);
            } else {
                SocketWorkerManager::spawn($index);
            }

            // Spawn succeeded — worker sent READY (checked inside spawn).
            // Reset backoff counter so transient failures don't accumulate.
            self::$respawnAttempts[$index] = 0;
            unset(self::$lastRespawnTime[$index]);
            unset(self::$permanentlyDead[$index]);

            // Update idle tracking for dynamic scaling
            WorkerPoolState::$workerIdleSince[$index] = microtime(true);
        } catch (\Throwable $e) {
            WorkerPoolState::$workers[$index] = [
                "busy" => false,
                "pid" => null,
                "mode" => WorkerPoolState::canUseFork() ? "fork" : "socket",
            ];

            error_log(
                "WorkerPool: Failed to respawn worker {$index} " .
                    "(attempt {$attempts}/{$maxRetries}): " .
                    $e->getMessage(),
            );

            // Check if this was the final attempt
            if ($attempts >= self::$maxRetries) {
                self::$permanentlyDead[$index] = true;
                error_log(
                    "WorkerPool: Worker {$index} permanently retired after " .
                        self::$maxRetries .
                        " consecutive failed respawn attempts.",
                );
            }
        }
    }

    // ─── Backoff computation ─────────────────────────────────────────

    /**
     * Compute the backoff delay in microseconds for the given attempt count.
     *
     * Uses exponential backoff: baseDelay * 2^attempts, capped at maxDelay.
     * A small jitter (±10%) is added to prevent thundering herd when
     * multiple workers crash simultaneously.
     *
     * @param int $attempts Number of consecutive failures so far (0-based).
     * @return int Delay in microseconds.
     */
    private static function computeBackoffDelay(int $attempts): int
    {
        if ($attempts <= 0) {
            return 0; // First attempt — no delay
        }

        // Exponential: baseDelay * 2^(attempts-1)
        // Use min() to prevent overflow on high attempt counts
        $exponent = min($attempts - 1, 20); // cap exponent to prevent overflow
        $delay = self::$baseDelayUs * (1 << $exponent);

        // Cap at maximum delay
        $delay = min($delay, self::$maxDelayUs);

        // Add ±10% jitter to prevent thundering herd
        $jitter = (int) ($delay * 0.1);
        if ($jitter > 0) {
            $delay += mt_rand(-$jitter, $jitter);
        }

        return max(0, $delay);
    }

    // ─── Resource cleanup ────────────────────────────────────────────

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
    private function __construct() {}
}
