<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Closure;
use Exception;

/**
 * A **true** WorkerPool: pre-spawns a fixed number of long-lived child
 * processes that idle in a loop waiting for tasks. When a task is submitted
 * the pool picks a free worker, sends the serialized closure over a
 * communication channel, and the worker executes it and returns the result.
 * Workers stay alive for the entire lifetime of the pool and are only
 * terminated on explicit shutdown or when the parent process exits.
 *
 * On Linux/macOS with pcntl+sockets: uses pcntl_fork() + socket_create_pair()
 * On Windows / without pcntl: uses proc_open() with a persistent
 *   worker_loop.php script communicating via a TCP loopback socket
 *   (because Windows proc_open pipes do NOT support non-blocking I/O,
 *   stream_select always returns 1, and stream_set_timeout is ignored).
 *
 * Architecture:
 *   ┌───────────┐       task        ┌──────────┐
 *   │  Parent   │ ──────────────▶   │ Worker 0 │  (long-lived)
 *   │ (pool)    │ ◀──────────────   │          │
 *   │           │       result      └──────────┘
 *   │           │       task        ┌──────────┐
 *   │           │ ──────────────▶   │ Worker 1 │  (long-lived)
 *   │           │ ◀──────────────   │          │
 *   │           │       result      └──────────┘
 *   │           │         ...       ┌──────────┐
 *   │           │ ──────────────▶   │ Worker N │  (long-lived)
 *   │           │ ◀──────────────   │          │
 *   └───────────┘       result      └──────────┘
 *
 * Features:
 *   - **Task Batching**: When batchSize > 1, multiple pending tasks are
 *     grouped and sent to a worker in a single BATCH: message, reducing
 *     IPC round-trip overhead significantly for many small tasks.
 *
 *   - **Dynamic Pool Sizing**: When enabled, the pool automatically
 *     scales between minPoolSize and maxPoolSize based on workload
 *     pressure, spawning new workers when tasks queue up and shutting
 *     down idle workers when demand drops.
 *
 * This class acts as the public-facing **facade**. All internal logic is
 * delegated to purpose-specific classes:
 *
 *   - {@see WorkerPoolState}          — shared static state (workers, tasks, buffers…)
 *   - {@see WorkerPoolCommunication}  — send/read/drain between parent & workers
 *   - {@see WorkerLifecycle}          — health checks & respawning
 *   - {@see ForkWorkerManager}        — fork-based worker spawn/loop/shutdown
 *   - {@see SocketWorkerManager}      — TCP server, socket-based worker spawn/shutdown
 *   - {@see TaskDispatcher}           — dispatch pending tasks & poll results
 */
final class WorkerPool
{
    public function __construct() {}

    // ═══════════════════════════════════════════════════════════════════
    //  Public API
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Sets the initial number of worker processes in the pool.
     * Must be called BEFORE the pool is booted (before any task is added).
     *
     * @param int $size Number of workers (must be > 0).
     * @throws Exception if size <= 0.
     */
    public static function setPoolSize(int $size): void
    {
        if ($size <= 0) {
            throw new Exception("Pool size must be greater than 0.");
        }
        WorkerPoolState::$poolSize = $size;
    }

    /**
     * Sets the task batch size for the worker pool.
     *
     * When batchSize > 1, multiple pending tasks are grouped into a
     * single BATCH: message sent to each worker, reducing IPC overhead.
     * The worker processes all tasks in the batch sequentially and
     * returns all results in a single BATCH_RESULTS: response.
     *
     * Must be called BEFORE the pool is booted for best results,
     * but can also be changed at runtime — the new size takes effect
     * on the next dispatch cycle.
     *
     * @param int $size Number of tasks per batch (must be >= 1).
     *                  A value of 1 means no batching (original behavior).
     * @throws Exception if size < 1.
     */
    public static function setBatchSize(int $size): void
    {
        if ($size < 1) {
            throw new Exception("Batch size must be at least 1.");
        }
        WorkerPoolState::$batchSize = $size;
    }

    /**
     * Enables or disables dynamic pool sizing.
     *
     * When enabled, the pool automatically scales the number of worker
     * processes between $minPoolSize and $maxPoolSize based on workload:
     *
     *   - **Scale up**: When there are pending tasks and all workers are
     *     busy, a new worker is spawned (up to maxPoolSize).
     *   - **Scale down**: When a worker has been idle for longer than
     *     $idleTimeout seconds and the pool exceeds $minPoolSize, the
     *     idle worker is shut down.
     *
     * Can be called before or after boot. When called after boot,
     * scaling behavior starts on the next run() tick.
     *
     * @param bool  $enabled         Whether to enable dynamic scaling.
     * @param int   $minPoolSize     Minimum workers to keep alive (>= 0).
     * @param int   $maxPoolSize     Maximum workers allowed (0 = use $poolSize).
     * @param float $idleTimeout     Seconds a worker must be idle before scale-down (>= 1.0).
     * @param float $scaleUpCooldown Minimum seconds between scale-up events (>= 0.1).
     * @param float $scaleDownCooldown Minimum seconds between scale-down events (>= 1.0).
     */
    public static function setDynamicScaling(
        bool $enabled,
        int $minPoolSize = 2,
        int $maxPoolSize = 0,
        float $idleTimeout = 10.0,
        float $scaleUpCooldown = 0.5,
        float $scaleDownCooldown = 5.0,
    ): void {
        WorkerPoolState::$dynamicScalingEnabled = $enabled;
        WorkerPoolState::$minPoolSize = max(0, $minPoolSize);
        WorkerPoolState::$maxPoolSize = max(0, $maxPoolSize);
        WorkerPoolState::$idleTimeout = max(1.0, $idleTimeout);
        WorkerPoolState::$scaleUpCooldown = max(0.1, $scaleUpCooldown);
        WorkerPoolState::$scaleDownCooldown = max(1.0, $scaleDownCooldown);
    }

    /**
     * Sets the maximum pool size independently.
     *
     * Convenience method when you only need to change the upper bound
     * without reconfiguring all dynamic scaling parameters.
     *
     * @param int $size Maximum workers (0 = use $poolSize as max).
     * @throws Exception if size < 0.
     */
    public static function setMaxPoolSize(int $size): void
    {
        if ($size < 0) {
            throw new Exception("Max pool size must be >= 0.");
        }
        WorkerPoolState::$maxPoolSize = $size;
    }

    /**
     * Returns true when there are no pending tasks AND no active tasks.
     * Used by Thread::await() and RunBlocking to know when all IO work
     * is done.
     */
    public static function isEmpty(): bool
    {
        return WorkerPoolState::isEmpty();
    }

    /**
     * Adds a closure to the pool's task queue.
     * The pool will be lazily booted on the next run() call if not already.
     *
     * @param Closure $closure The closure to execute in a worker process.
     * @return int A unique task ID.
     */
    public static function add(Closure $closure): int
    {
        $id = WorkerPoolState::$nextTaskId++;
        WorkerPoolState::$pendingTasks[] = [
            "closure" => $closure,
            "id" => $id,
        ];
        return $id;
    }

    /**
     * Adds a closure and returns an Async handle that resolves to the
     * closure's return value once the worker finishes.
     *
     * @param Closure $closure The closure to execute in a worker process.
     * @return Async An Async that yields the result.
     */
    public static function addAsync(Closure $closure): Async
    {
        $id = self::add($closure);
        return Async::new(function () use ($id) {
            while (!array_key_exists($id, WorkerPoolState::$returns)) {
                Pause::force();
            }
            $result = WorkerPoolState::$returns[$id];
            unset(WorkerPoolState::$returns[$id]);

            if ($result instanceof \Throwable) {
                throw $result;
            }

            return $result;
        });
    }

    /**
     * Main tick function — called by the event loop (Thread::await /
     * RunBlocking) on every iteration.
     *
     * 1. Boots worker processes lazily on first call.
     * 2. If dynamic scaling is enabled, evaluates scale-up/scale-down.
     * 3. Dispatches pending tasks to free workers (single or batch mode).
     * 4. Polls active workers for completed results (non-blocking).
     */
    public static function run(): void
    {
        if (
            empty(WorkerPoolState::$pendingTasks) &&
            empty(WorkerPoolState::$activeTasks)
        ) {
            // Even when idle, run dynamic scale-down if enabled
            if (
                WorkerPoolState::$booted &&
                WorkerPoolState::$dynamicScalingEnabled
            ) {
                self::evaluateScaleDown();
            }
            return;
        }

        // Lazy-boot the pool on first use
        if (!WorkerPoolState::$booted) {
            self::boot();
        }

        // Dynamic scaling: check if we need more workers
        if (WorkerPoolState::$dynamicScalingEnabled) {
            self::evaluateScaleUp();
        }

        // Dispatch pending tasks to idle workers
        TaskDispatcher::dispatchPending();

        // Poll for completed results (non-blocking)
        TaskDispatcher::pollResults();

        // Dynamic scaling: check if we can shrink
        if (WorkerPoolState::$dynamicScalingEnabled) {
            self::evaluateScaleDown();
        }
    }

    /**
     * Gracefully shuts down all worker processes.
     * Sends SHUTDOWN command, waits for exit, and cleans up resources.
     */
    public static function shutdown(): void
    {
        if (!WorkerPoolState::$booted) {
            return;
        }

        foreach (WorkerPoolState::$workers as $i => $worker) {
            try {
                if ($worker["mode"] === "fork") {
                    ForkWorkerManager::shutdown($i);
                } else {
                    SocketWorkerManager::shutdown($i);
                }
            } catch (\Throwable) {
                // Best-effort cleanup
            }
        }

        // Close the TCP server socket if it was created
        if (WorkerPoolState::$serverSocket !== null) {
            @fclose(WorkerPoolState::$serverSocket);
            WorkerPoolState::$serverSocket = null;
            WorkerPoolState::$serverPort = 0;
        }

        WorkerPoolState::$workers = [];
        WorkerPoolState::$activeTasks = [];
        WorkerPoolState::$readBuffers = [];
        WorkerPoolState::$workerIdleSince = [];
        WorkerPoolState::$booted = false;
    }

    /**
     * Gracefully shuts down a single worker by index.
     *
     * Used by dynamic scaling to remove idle workers. After shutdown,
     * the worker slot is removed from all state arrays.
     *
     * @param int $index Worker slot index.
     */
    public static function shutdownWorker(int $index): void
    {
        $worker = WorkerPoolState::$workers[$index] ?? null;
        if ($worker === null) {
            return;
        }

        try {
            if ($worker["mode"] === "fork") {
                ForkWorkerManager::shutdown($index);
            } else {
                SocketWorkerManager::shutdown($index);
            }
        } catch (\Throwable) {
            // Best-effort cleanup
        }

        unset(WorkerPoolState::$workers[$index]);
        unset(WorkerPoolState::$activeTasks[$index]);
        unset(WorkerPoolState::$readBuffers[$index]);
        unset(WorkerPoolState::$workerIdleSince[$index]);
    }

    /**
     * Resets all static state. Used by ForkProcess after pcntl_fork()
     * to clear stale pool state inherited from the parent process.
     */
    public static function resetState(): void
    {
        WorkerPoolState::resetAll();
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Boot
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Spawns the worker processes. Called once, lazily.
     */
    private static function boot(): void
    {
        if (WorkerPoolState::$booted) {
            return;
        }

        // Register a shutdown function to clean up workers when the
        // parent script ends (even on fatal errors). Only register once.
        if (!WorkerPoolState::$shutdownRegistered) {
            register_shutdown_function([self::class, "shutdown"]);
            WorkerPoolState::$shutdownRegistered = true;
        }

        // Resolve maxPoolSize if not explicitly set
        if (WorkerPoolState::$maxPoolSize === 0) {
            WorkerPoolState::$maxPoolSize = WorkerPoolState::$poolSize;
        }

        // Ensure minPoolSize does not exceed maxPoolSize
        if (
            WorkerPoolState::$minPoolSize >
            WorkerPoolState::effectiveMaxPoolSize()
        ) {
            WorkerPoolState::$minPoolSize = WorkerPoolState::effectiveMaxPoolSize();
        }

        $useFork = WorkerPoolState::canUseFork();

        // For socket mode (Windows), create a TCP server that workers
        // will connect to for bidirectional communication.
        if (!$useFork) {
            SocketWorkerManager::createTcpServer();
        }

        for ($i = 0; $i < WorkerPoolState::$poolSize; $i++) {
            if ($useFork) {
                ForkWorkerManager::spawn($i);
            } else {
                SocketWorkerManager::spawn($i);
            }

            // Mark newly spawned worker as idle now
            WorkerPoolState::$workerIdleSince[$i] = microtime(true);
        }

        WorkerPoolState::$booted = true;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Dynamic Scaling
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Evaluates whether the pool should scale UP (spawn more workers).
     *
     * Conditions for scale-up:
     *   1. There are pending tasks waiting for a free worker.
     *   2. All existing workers are busy (no idle workers available).
     *   3. Current worker count < effectiveMaxPoolSize.
     *   4. Cooldown period since last scale-up has elapsed.
     *
     * When conditions are met, spawns ONE new worker. The next run()
     * tick can spawn another if pressure continues, rate-limited by
     * the cooldown.
     */
    private static function evaluateScaleUp(): void
    {
        // Nothing to scale for if no pending tasks
        if (empty(WorkerPoolState::$pendingTasks)) {
            return;
        }

        // Only scale up if all workers are busy
        if (WorkerPoolState::idleWorkerCount() > 0) {
            return;
        }

        // Check if we've reached the max
        $maxSize = WorkerPoolState::effectiveMaxPoolSize();
        $currentCount = WorkerPoolState::workerCount();
        if ($currentCount >= $maxSize) {
            return;
        }

        // Enforce cooldown
        $now = microtime(true);
        if (
            $now - WorkerPoolState::$lastScaleUpTime <
            WorkerPoolState::$scaleUpCooldown
        ) {
            return;
        }

        // Spawn one new worker at the next available index
        $newIndex = self::nextAvailableWorkerIndex();

        try {
            $useFork = WorkerPoolState::canUseFork();

            // Ensure TCP server exists for socket mode
            if (!$useFork && WorkerPoolState::$serverSocket === null) {
                SocketWorkerManager::createTcpServer();
            }

            if ($useFork) {
                ForkWorkerManager::spawn($newIndex);
            } else {
                SocketWorkerManager::spawn($newIndex);
            }

            WorkerPoolState::$workerIdleSince[$newIndex] = microtime(true);
            WorkerPoolState::$lastScaleUpTime = $now;
        } catch (\Throwable $e) {
            error_log(
                "WorkerPool: Failed to scale up (spawn worker {$newIndex}): " .
                    $e->getMessage(),
            );
        }
    }

    /**
     * Evaluates whether the pool should scale DOWN (shut down idle workers).
     *
     * Conditions for scale-down:
     *   1. Dynamic scaling is enabled.
     *   2. Current worker count > $minPoolSize.
     *   3. Cooldown period since last scale-down has elapsed.
     *   4. At least one worker has been idle for >= $idleTimeout seconds.
     *   5. There are no pending tasks (we don't shrink under pressure).
     *
     * When conditions are met, shuts down ONE idle worker. The next run()
     * tick can shut down another if conditions persist.
     */
    private static function evaluateScaleDown(): void
    {
        $currentCount = WorkerPoolState::workerCount();
        $minSize = WorkerPoolState::$minPoolSize;

        // Can't shrink below minimum
        if ($currentCount <= $minSize) {
            return;
        }

        // Don't shrink if there are pending tasks
        if (!empty(WorkerPoolState::$pendingTasks)) {
            return;
        }

        // Enforce cooldown
        $now = microtime(true);
        if (
            $now - WorkerPoolState::$lastScaleDownTime <
            WorkerPoolState::$scaleDownCooldown
        ) {
            return;
        }

        // Find the worker that has been idle the longest
        $longestIdleIndex = null;
        $longestIdleTime = 0.0;

        foreach (WorkerPoolState::$workers as $i => $worker) {
            // Skip busy workers
            if ($worker["busy"]) {
                continue;
            }

            // Skip workers with active tasks (batch in progress)
            if (isset(WorkerPoolState::$activeTasks[$i])) {
                continue;
            }

            $idleSince = WorkerPoolState::$workerIdleSince[$i] ?? $now;
            $idleDuration = $now - $idleSince;

            if (
                $idleDuration >= WorkerPoolState::$idleTimeout &&
                $idleDuration > $longestIdleTime
            ) {
                $longestIdleTime = $idleDuration;
                $longestIdleIndex = $i;
            }
        }

        // No worker idle long enough
        if ($longestIdleIndex === null) {
            return;
        }

        // Shut down the idle worker
        self::shutdownWorker($longestIdleIndex);
        WorkerPoolState::$lastScaleDownTime = $now;
    }

    /**
     * Returns the next available (unused) worker index.
     *
     * Worker indices can have gaps after scale-down events. This method
     * finds the smallest non-negative integer not currently in use.
     *
     * @return int The next available worker slot index.
     */
    private static function nextAvailableWorkerIndex(): int
    {
        $index = 0;
        while (isset(WorkerPoolState::$workers[$index])) {
            $index++;
        }
        return $index;
    }
}
