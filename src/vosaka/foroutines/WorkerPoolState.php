<?php

declare(strict_types=1);

namespace vosaka\foroutines;

/**
 * Holds all shared static state for the WorkerPool subsystem.
 *
 * This class centralizes configuration, worker slots, task queues,
 * result storage, read buffers, and the TCP server resource — all of
 * which were previously scattered as private statics inside WorkerPool.
 *
 * Every field is `public static` so that the sibling manager/dispatcher
 * classes (ForkWorkerManager, SocketWorkerManager, TaskDispatcher,
 * WorkerPoolCommunication, and the refactored WorkerPool facade) can
 * access them directly without getter/setter boilerplate.
 *
 * @internal This class is not part of the public API.
 */
final class WorkerPoolState
{
    // ─── Pool configuration ──────────────────────────────────────────

    /** Default number of worker processes */
    public static int $poolSize = 4;

    /** Whether the pool has been booted (workers spawned) */
    public static bool $booted = false;

    /** Whether the current process is a worker process */
    public static bool $isWorker = false;

    /**
     * Worker slots.
     *
     * Fork mode keys:
     *   'busy'   => bool
     *   'pid'    => int
     *   'socket' => \Socket   (parent end, non-blocking)
     *   'mode'   => 'fork'
     *
     * Socket mode keys (Windows / fallback):
     *   'busy'    => bool
     *   'process' => resource  (proc_open handle)
     *   'stdin'   => resource  (writable pipe – only used to keep child alive)
     *   'stdout'  => resource  (readable pipe – not used for comms)
     *   'stderr'  => resource  (readable pipe – forwarded for debugging)
     *   'conn'    => resource  (accepted TCP socket stream, non-blocking)
     *   'mode'    => 'socket'
     *
     * @var array<int, array<string, mixed>>
     */
    public static array $workers = [];

    // ─── Task queue ──────────────────────────────────────────────────

    /**
     * Pending tasks waiting for a free worker.
     * @var array<int, array{closure: \Closure, id: int}>
     */
    public static array $pendingTasks = [];

    /**
     * Tasks currently being executed by a worker.
     *
     * Single-task mode: maps worker index => single task id (int).
     * Batch mode:       maps worker index => array of task ids (int[]).
     *
     * @var array<int, int|int[]>
     */
    public static array $activeTasks = [];

    /**
     * Completed results. Maps task id => mixed result.
     * @var array<int, mixed>
     */
    public static array $returns = [];

    /** Auto-increment task ID counter */
    public static int $nextTaskId = 1;

    /**
     * Per-worker read buffer for partial line accumulation.
     * @var array<int, string>
     */
    public static array $readBuffers = [];

    /** Whether the shutdown function has been registered */
    public static bool $shutdownRegistered = false;

    // ─── TCP server (socket-mode / Windows) ──────────────────────────

    /**
     * TCP server socket for socket-mode workers (Windows).
     * @var resource|null
     */
    public static $serverSocket = null;

    /** Port the TCP server is listening on */
    public static int $serverPort = 0;

    // ─── Task Batching configuration ─────────────────────────────────

    /**
     * Maximum number of tasks to send to a worker in a single batch.
     *
     * When set to 1 (default), the pool uses the original single-task
     * protocol (TASK:/RESULT:/READY). When > 1, pending tasks are
     * grouped into batches and sent via the BATCH: protocol, reducing
     * IPC round-trip overhead.
     *
     * Recommended values:
     *   - 1        : original behavior, lowest latency per task
     *   - 5-10     : good balance for many small/fast tasks
     *   - 20-50    : maximum throughput for trivial tasks
     *
     * @var int
     */
    public static int $batchSize = 1;

    // ─── Dynamic Pool Sizing configuration ───────────────────────────

    /**
     * Minimum number of worker processes to keep alive.
     *
     * Even when workload is zero, this many workers remain spawned
     * to avoid cold-start latency when new tasks arrive. Set to 0
     * to allow the pool to shrink to zero idle workers.
     *
     * @var int
     */
    public static int $minPoolSize = 2;

    /**
     * Maximum number of worker processes the pool can scale up to.
     *
     * The pool will never spawn more workers than this, regardless
     * of pending task count. Defaults to the initial $poolSize value
     * but can be overridden via WorkerPool::setMaxPoolSize().
     *
     * A value of 0 means "use $poolSize as the hard max" (no dynamic
     * scaling beyond the initial size). This is resolved at boot time.
     *
     * @var int
     */
    public static int $maxPoolSize = 0;

    /**
     * Whether dynamic pool sizing is enabled.
     *
     * When false, the pool behaves exactly as before: a fixed number
     * of workers ($poolSize) are spawned at boot and never changed.
     *
     * When true, the pool can scale between $minPoolSize and
     * $maxPoolSize based on workload pressure.
     *
     * @var bool
     */
    public static bool $dynamicScalingEnabled = false;

    /**
     * Minimum interval (in seconds) between consecutive scale-up events.
     *
     * Prevents thrashing when task submission is bursty. After spawning
     * a new worker, the pool waits at least this long before considering
     * another scale-up.
     *
     * @var float
     */
    public static float $scaleUpCooldown = 0.5;

    /**
     * Minimum interval (in seconds) between consecutive scale-down events.
     *
     * After shutting down an idle worker, the pool waits at least this
     * long before considering another scale-down. This prevents rapid
     * spawn/kill cycles for fluctuating workloads.
     *
     * @var float
     */
    public static float $scaleDownCooldown = 5.0;

    /**
     * How long (in seconds) a worker must be idle before it becomes
     * eligible for scale-down.
     *
     * An idle worker is one that is not busy AND has no tasks dispatched
     * to it. Only workers idle longer than this threshold will be
     * considered for shutdown during scale-down.
     *
     * @var float
     */
    public static float $idleTimeout = 10.0;

    /**
     * Timestamp (microtime(true)) of the last scale-up event.
     * Used to enforce $scaleUpCooldown.
     *
     * @var float
     */
    public static float $lastScaleUpTime = 0.0;

    /**
     * Timestamp (microtime(true)) of the last scale-down event.
     * Used to enforce $scaleDownCooldown.
     *
     * @var float
     */
    public static float $lastScaleDownTime = 0.0;

    /**
     * Per-worker timestamp of when the worker last became idle.
     *
     * Set to microtime(true) when a worker transitions from busy → idle
     * (i.e. when it sends READY after completing a task). Reset to 0.0
     * when the worker is assigned a new task (busy again).
     *
     * Used by the dynamic scaler to determine whether an idle worker
     * has exceeded $idleTimeout and should be shut down.
     *
     * @var array<int, float>
     */
    public static array $workerIdleSince = [];

    // ─── Respawn Backoff ─────────────────────────────────────────────

    /**
     * Per-worker consecutive respawn attempt count.
     *
     * Reset to 0 when a worker successfully completes a task (proving
     * it is healthy). Incremented on each respawnWorker() call.
     *
     * @var array<int, int>
     */
    public static array $respawnAttempts = [];

    /**
     * Per-worker earliest-allowed respawn time (microtime(true)).
     *
     * When a worker is respawned, the next respawn is delayed by an
     * exponential backoff. If the current time is before this value,
     * the respawn is skipped (caller will retry on the next tick).
     *
     * @var array<int, float>
     */
    public static array $respawnNextAllowed = [];

    /**
     * Maximum consecutive respawn attempts before circuit-breaking.
     *
     * After this many consecutive failures the worker slot is removed
     * entirely to prevent infinite CPU-spinning respawn loops.
     *
     * @var int
     */
    public static int $maxRespawnAttempts = 10;

    /**
     * Base delay in milliseconds for exponential backoff.
     *
     * First retry waits $respawnBaseDelayMs, second waits 2×, third
     * waits 4×, etc. Capped at 30 000 ms (30 s).
     *
     * @var int
     */
    public static int $respawnBaseDelayMs = 100;

    // ─── Helpers ─────────────────────────────────────────────────────

    /**
     * Resets all static state to initial values.
     *
     * Used by ForkProcess after pcntl_fork() to clear stale pool state
     * inherited from the parent process, and by WorkerPool::shutdown()
     * after all workers have been terminated.
     *
     * IMPORTANT: In a forked child we must NOT close the parent's
     * sockets or kill sibling workers — just drop the references.
     */
    public static function resetAll(): void
    {
        self::$workers = [];
        self::$pendingTasks = [];
        self::$activeTasks = [];
        self::$returns = [];
        self::$readBuffers = [];
        self::$booted = false;
        self::$isWorker = false;
        self::$nextTaskId = 1;
        self::$serverSocket = null;
        self::$serverPort = 0;
        self::$workerIdleSince = [];
        self::$lastScaleUpTime = 0.0;
        self::$lastScaleDownTime = 0.0;
        self::$respawnAttempts = [];
        self::$respawnNextAllowed = [];
    }

    /**
     * Returns true when there are no pending tasks AND no active tasks.
     * Used by Thread::await() and RunBlocking to know when all IO work
     * is done.
     */
    public static function isEmpty(): bool
    {
        return empty(self::$pendingTasks) && empty(self::$activeTasks);
    }

    /**
     * Returns the number of workers currently alive (spawned).
     *
     * @return int
     */
    public static function workerCount(): int
    {
        return count(self::$workers);
    }

    /**
     * Returns the number of workers that are currently idle (not busy).
     *
     * @return int
     */
    public static function idleWorkerCount(): int
    {
        $count = 0;
        foreach (self::$workers as $worker) {
            if (!$worker["busy"]) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Returns the effective maximum pool size.
     *
     * If $maxPoolSize is 0 (default / not explicitly set), falls back
     * to $poolSize. Otherwise returns $maxPoolSize.
     *
     * @return int
     */
    public static function effectiveMaxPoolSize(): int
    {
        return self::$maxPoolSize > 0 ? self::$maxPoolSize : self::$poolSize;
    }

    /**
     * Check whether we can use the fork-based worker strategy.
     *
     * Requirements:
     *   - Not running on Windows
     *   - pcntl extension loaded with pcntl_fork available
     *   - socket_create_pair available
     *   - pcntl_fork not in disable_functions
     */
    public static function canUseFork(): bool
    {
        if (self::isWindows()) {
            return false;
        }
        if (!extension_loaded("pcntl") || !function_exists("pcntl_fork")) {
            return false;
        }
        if (!function_exists("socket_create_pair")) {
            return false;
        }
        $disabled = array_map(
            "trim",
            explode(",", (string) ini_get("disable_functions")),
        );
        if (in_array("pcntl_fork", $disabled, true)) {
            return false;
        }
        return true;
    }

    /**
     * Returns true if the current OS is Windows.
     */
    public static function isWindows(): bool
    {
        return strncasecmp(PHP_OS, "WIN", 3) === 0;
    }

    /** Prevent instantiation */
    private function __construct()
    {
    }
}
