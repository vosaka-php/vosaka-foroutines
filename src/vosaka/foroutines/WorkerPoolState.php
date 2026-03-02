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
     * Maps worker index => task id.
     * @var array<int, int>
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
        self::$nextTaskId = 1;
        self::$serverSocket = null;
        self::$serverPort = 0;
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
    private function __construct() {}
}
