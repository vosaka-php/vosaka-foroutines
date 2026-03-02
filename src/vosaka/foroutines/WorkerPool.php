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
     * Sets the maximum number of worker processes in the pool.
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
                Pause::new();
            }
            $result = WorkerPoolState::$returns[$id];
            unset(WorkerPoolState::$returns[$id]);
            return $result;
        });
    }

    /**
     * Main tick function — called by the event loop (Thread::await /
     * RunBlocking) on every iteration.
     *
     * 1. Boots worker processes lazily on first call.
     * 2. Dispatches pending tasks to free workers.
     * 3. Polls active workers for completed results (non-blocking).
     */
    public static function run(): void
    {
        if (
            empty(WorkerPoolState::$pendingTasks) &&
            empty(WorkerPoolState::$activeTasks)
        ) {
            return;
        }

        // Lazy-boot the pool on first use
        if (!WorkerPoolState::$booted) {
            self::boot();
        }

        // Dispatch pending tasks to idle workers
        TaskDispatcher::dispatchPending();

        // Poll for completed results (non-blocking)
        TaskDispatcher::pollResults();
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
        WorkerPoolState::$booted = false;
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
        }

        WorkerPoolState::$booted = true;
    }
}
