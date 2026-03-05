<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Fiber;
use SplQueue;
use Throwable;

/**
 * RuntimeFiberPool — Internal global fiber pool integrated into the VOsaka scheduler.
 *
 * This is the core engine that replaces raw `new Fiber()` calls throughout
 * the system. Instead of allocating a new PHP Fiber for every Launch/Async
 * task (costing ~5-15µs per allocation + GC pressure), the RuntimeFiberPool
 * maintains a set of reusable "worker shell" fibers that loop internally,
 * accepting tasks and executing them cooperatively.
 *
 * === Architecture ===
 *
 * Each pooled fiber runs an infinite loop (a "worker shell"):
 *   1. Suspend (idle) — waiting for a task
 *   2. Receive a callable via resume([$callable, $taskId])
 *   3. Execute the callable — the callable MAY call Pause::new()/force(),
 *      Delay::new(), Channel ops, Async::await(), etc. which call
 *      Fiber::suspend(). When that happens, the worker shell fiber
 *      suspends and the scheduler resumes it later. This is fully
 *      cooperative.
 *   4. When the callable returns (task complete), store result, mark
 *      the fiber as idle, and loop back to step 1.
 *
 * === Two execution modes ===
 *
 * **SYNC mode** (for FiberPool public API — batch processing):
 *   Task runs to completion synchronously within a single resume() call.
 *   The task callable must NOT call Fiber::suspend(). Result is available
 *   immediately after dispatch.
 *
 * **COOPERATIVE mode** (for Launch/Async — scheduler integration):
 *   Task callable may suspend/resume many times (Pause, Delay, Channel,
 *   I/O waits, etc.). The scheduler drives the fiber forward by calling
 *   resume() on each tick, just like a regular non-pooled fiber. When
 *   the task completes, the worker shell recycles itself back to idle.
 *
 * === Integration points ===
 *
 *   - Launch::makeLaunch() calls RuntimeFiberPool::acquireFiber() instead
 *     of `new Fiber($callable)`.
 *   - Async::new() for DEFAULT dispatcher uses RuntimeFiberPool.
 *   - RunBlocking main loop calls RuntimeFiberPool::tick() to manage
 *     the pool (auto-scaling, recycling).
 *   - FiberPool (public API) delegates to RuntimeFiberPool for its
 *     sync-mode worker shells.
 *
 * === Why a singleton? ===
 *
 * The pool is global because the VOsaka scheduler is global (static
 * methods on Launch, Pause, EventLoop, WorkerPool, AsyncIO). A single
 * pool amortizes allocation cost across ALL subsystems and provides
 * unified auto-scaling.
 */
final class RuntimeFiberPool
{
    // ─── Singleton ───────────────────────────────────────────────────

    private static ?self $instance = null;

    /**
     * Whether the pool has been initialized.
     * Separating this from $instance !== null allows resetState() to
     * fully tear down and re-bootstrap cleanly.
     */
    private static bool $booted = false;

    // ─── Pool state ──────────────────────────────────────────────────

    /**
     * Idle fiber indices ready to accept a cooperative task.
     * @var SplQueue<int>
     */
    private SplQueue $idleQueue;

    /**
     * All managed worker shell fibers.
     * @var array<int, Fiber>
     */
    private array $fibers = [];

    /**
     * Mapping from fiber spl_object_id => pool slot index.
     * Used for O(1) lookup when a fiber terminates so we can
     * recycle its slot without scanning $fibers.
     * @var array<int, int>
     */
    private array $fiberIdToSlot = [];

    /**
     * Tasks currently running inside worker shells (slot => taskId).
     * When empty for a slot, the fiber is idle.
     * @var array<int, int>
     */
    private array $activeSlots = [];

    /**
     * Per-task completion results. Maps taskId => mixed.
     * @var array<int, mixed>
     */
    private array $results = [];

    /**
     * Per-task errors. Maps taskId => Throwable.
     * @var array<int, Throwable>
     */
    private array $errors = [];

    /**
     * Per-task completion flags. Maps taskId => true.
     * @var array<int, bool>
     */
    private array $completed = [];

    /**
     * Cooperative fibers currently being driven by the scheduler.
     * Maps taskId => slot index. These fibers are NOT idle — they
     * are mid-execution and need resume() calls from the scheduler.
     * @var array<int, int>
     */
    private array $cooperativeActive = [];

    /**
     * Reverse map: slot index => taskId for cooperative tasks.
     * @var array<int, int>
     */
    private array $slotToTask = [];

    /**
     * Callbacks to invoke when a cooperative task completes.
     * Maps taskId => callable(mixed $result, ?Throwable $error).
     * @var array<int, callable>
     */
    private array $completionCallbacks = [];

    /**
     * Pending tasks that couldn't be dispatched because no idle fiber
     * was available. Each entry: [$callable, $taskId, $mode].
     * @var SplQueue<array{0: callable, 1: int, 2: int}>
     */
    private SplQueue $pendingTasks;

    /**
     * Auto-incrementing task ID.
     */
    private int $nextTaskId = 1;

    /**
     * Current number of fibers in the pool.
     */
    private int $currentSize = 0;

    // ─── Configuration ───────────────────────────────────────────────

    /**
     * Default initial pool size.
     * Chosen to cover typical concurrent workloads without over-allocating.
     */
    private const DEFAULT_INITIAL_SIZE = 16;

    /**
     * Default max pool size (auto-scaling cap).
     */
    private const DEFAULT_MAX_SIZE = 128;

    /**
     * Scale-up cooldown in seconds.
     */
    private const SCALE_UP_COOLDOWN = 0.1;

    /**
     * Scale-down cooldown in seconds.
     */
    private const SCALE_DOWN_COOLDOWN = 5.0;

    /**
     * Idle timeout before a fiber is eligible for scale-down (seconds).
     */
    private const IDLE_TIMEOUT = 15.0;

    /**
     * Task mode: synchronous (task must complete in a single resume).
     */
    public const MODE_SYNC = 0;

    /**
     * Task mode: cooperative (task may suspend/resume many times).
     */
    public const MODE_COOPERATIVE = 1;

    private int $initialSize;
    private int $maxSize;
    private bool $autoScaling = true;
    private float $lastScaleUpTime = 0.0;
    private float $lastScaleDownTime = 0.0;

    /**
     * Per-slot idle-since timestamps for scale-down evaluation.
     * @var array<int, float>
     */
    private array $fiberIdleSince = [];

    /**
     * Whether the pool is shut down.
     */
    private bool $shutdown = false;

    /**
     * Stats counters for introspection.
     */
    private int $totalTasksSubmitted = 0;
    private int $totalTasksCompleted = 0;
    private int $totalFibersSpawned = 0;
    private int $totalFibersRecycled = 0;

    // ═════════════════════════════════════════════════════════════════
    //  Singleton lifecycle
    // ═════════════════════════════════════════════════════════════════

    private function __construct(int $initialSize, int $maxSize)
    {
        $this->initialSize = max(1, $initialSize);
        $this->maxSize = max($this->initialSize, $maxSize);
        $this->idleQueue = new SplQueue();
        $this->pendingTasks = new SplQueue();

        // Pre-create the initial pool of worker shell fibers
        for ($i = 0; $i < $this->initialSize; $i++) {
            $this->spawnWorkerShell($i);
        }
    }

    /**
     * Boot/get the global RuntimeFiberPool singleton.
     *
     * Called lazily on first use. After boot, the pool is ready to
     * accept tasks via submit() or acquireFiber().
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self(
                self::DEFAULT_INITIAL_SIZE,
                self::DEFAULT_MAX_SIZE,
            );
            self::$booted = true;
        }

        return self::$instance;
    }

    /**
     * Check if the pool has been booted.
     */
    public static function isBooted(): bool
    {
        return self::$booted;
    }

    /**
     * Configure the pool BEFORE first use.
     *
     * If the pool is already booted, this reconfigures it (applies to
     * future scaling decisions — existing fibers are NOT destroyed).
     *
     * @param int  $initialSize Initial number of worker shell fibers.
     * @param int  $maxSize     Maximum fibers for auto-scaling.
     * @param bool $autoScaling Enable/disable auto-scaling.
     */
    public static function configure(
        int $initialSize = self::DEFAULT_INITIAL_SIZE,
        int $maxSize = self::DEFAULT_MAX_SIZE,
        bool $autoScaling = true,
    ): void {
        if (self::$instance !== null) {
            // Reconfigure live instance
            self::$instance->maxSize = max(
                $maxSize,
                self::$instance->currentSize,
            );
            self::$instance->autoScaling = $autoScaling;
            // If initialSize increased, spawn more fibers
            $deficit = max(1, $initialSize) - self::$instance->currentSize;
            if ($deficit > 0) {
                for ($i = 0; $i < $deficit; $i++) {
                    $idx = self::$instance->nextSlotIndex();
                    self::$instance->spawnWorkerShell($idx);
                }
                self::$instance->initialSize = max(1, $initialSize);
            }
            return;
        }

        // Not yet booted — create with custom config
        self::$instance = new self(
            max(1, $initialSize),
            max($initialSize, $maxSize),
        );
        self::$instance->autoScaling = $autoScaling;
        self::$booted = true;
    }

    /**
     * Reset all state — used by ForkProcess after pcntl_fork() to
     * prevent child processes from inheriting parent fibers.
     */
    public static function resetState(): void
    {
        if (self::$instance !== null) {
            self::$instance->doShutdown();
        }
        self::$instance = null;
        self::$booted = false;
    }

    // ═════════════════════════════════════════════════════════════════
    //  Core public API — used by Launch, Async, and internal systems
    // ═════════════════════════════════════════════════════════════════

    /**
     * Submit a task in COOPERATIVE mode and return a managed Fiber.
     *
     * This is the primary integration point for Launch and Async.
     * Instead of `new Fiber($callable)`, they call this method which:
     *   1. Wraps the callable in a worker shell fiber from the pool
     *   2. Returns a "proxy fiber" that Launch/Async can start/resume/
     *      check isTerminated on — behaving identically to a raw Fiber
     *
     * The returned Fiber is the actual worker shell fiber. It is already
     * started (sitting at its first suspend point). The caller should
     * NOT call start() — the first resume() will dispatch the task.
     *
     * @param callable $callable The task to execute cooperatively.
     * @param callable|null $onComplete Optional completion callback.
     * @return Fiber The worker shell fiber, ready for resume().
     */
    public function acquireCooperativeFiber(
        callable $callable,
        ?callable $onComplete = null,
    ): Fiber {
        $taskId = $this->nextTaskId++;
        $this->totalTasksSubmitted++;

        // Try to get an idle worker shell
        if (!$this->idleQueue->isEmpty()) {
            $slotIndex = $this->idleQueue->dequeue();
            unset($this->fiberIdleSince[$slotIndex]);

            return $this->dispatchCooperative(
                $slotIndex,
                $callable,
                $taskId,
                $onComplete,
            );
        }

        // No idle fiber — try auto-scaling
        if ($this->autoScaling && $this->currentSize < $this->maxSize) {
            $now = microtime(true);
            if ($now - $this->lastScaleUpTime >= self::SCALE_UP_COOLDOWN) {
                $slotIndex = $this->nextSlotIndex();
                $this->spawnWorkerShell($slotIndex);
                $this->lastScaleUpTime = $now;

                // The new fiber is idle — use it
                if (!$this->idleQueue->isEmpty()) {
                    $slotIndex = $this->idleQueue->dequeue();
                    unset($this->fiberIdleSince[$slotIndex]);

                    return $this->dispatchCooperative(
                        $slotIndex,
                        $callable,
                        $taskId,
                        $onComplete,
                    );
                }
            }
        }

        // All fibers busy and can't scale — fall back to raw Fiber
        // This ensures the system never deadlocks. The raw fiber
        // won't be pooled but will work correctly with the scheduler.
        return $this->createRawFiber($callable, $taskId, $onComplete);
    }

    /**
     * Submit a task in SYNC mode (for FiberPool public API).
     *
     * The task runs to completion synchronously within the resume() call.
     * Result is available immediately.
     *
     * @param callable $callable The task to execute.
     * @return int Ticket ID for result retrieval.
     */
    public function submitSync(callable $callable): int
    {
        $taskId = $this->nextTaskId++;
        $this->totalTasksSubmitted++;

        if (!$this->idleQueue->isEmpty()) {
            $slotIndex = $this->idleQueue->dequeue();
            unset($this->fiberIdleSince[$slotIndex]);
            $this->dispatchSync($slotIndex, $callable, $taskId);
            return $taskId;
        }

        // No idle fiber — try scaling
        if ($this->autoScaling && $this->currentSize < $this->maxSize) {
            $now = microtime(true);
            if ($now - $this->lastScaleUpTime >= self::SCALE_UP_COOLDOWN) {
                $slotIndex = $this->nextSlotIndex();
                $this->spawnWorkerShell($slotIndex);
                $this->lastScaleUpTime = $now;

                if (!$this->idleQueue->isEmpty()) {
                    $slotIndex = $this->idleQueue->dequeue();
                    unset($this->fiberIdleSince[$slotIndex]);
                    $this->dispatchSync($slotIndex, $callable, $taskId);
                    return $taskId;
                }
            }
        }

        // Queue it for later
        $this->pendingTasks->enqueue([$callable, $taskId, self::MODE_SYNC]);
        return $taskId;
    }

    /**
     * Get the result for a sync-mode task.
     *
     * @param int $taskId Task ID returned by submitSync().
     * @return mixed The task's return value.
     * @throws \RuntimeException If the task threw an exception.
     */
    public function getSyncResult(int $taskId): mixed
    {
        // Dispatch pending if needed
        while (!isset($this->completed[$taskId])) {
            $this->dispatchPending();
            if (isset($this->completed[$taskId])) {
                break;
            }
            // Drive scheduler to free up fibers
            if (Fiber::getCurrent() !== null) {
                Pause::force();
            } else {
                $this->driveScheduler();
                usleep(100);
            }
        }

        if (isset($this->errors[$taskId])) {
            $error = $this->errors[$taskId];
            unset($this->errors[$taskId], $this->completed[$taskId]);
            throw new \RuntimeException(
                "RuntimeFiberPool sync task #{$taskId} failed: " .
                    $error->getMessage(),
                0,
                $error,
            );
        }

        $result = $this->results[$taskId] ?? null;
        unset($this->results[$taskId], $this->completed[$taskId]);
        return $result;
    }

    /**
     * Check if a task has completed.
     */
    public function isTaskCompleted(int $taskId): bool
    {
        return isset($this->completed[$taskId]);
    }

    /**
     * Notify the pool that a cooperative fiber has terminated.
     *
     * Called by the scheduler (Launch::runOnce) when it detects that
     * a pooled fiber's task has completed. This recycles the worker
     * shell back to idle state.
     *
     * @param Fiber $fiber The terminated fiber.
     */
    public function notifyFiberTerminated(Fiber $fiber): void
    {
        $fiberId = spl_object_id($fiber);
        $slotIndex = $this->fiberIdToSlot[$fiberId] ?? null;

        if ($slotIndex === null) {
            // Not a pooled fiber — nothing to do
            return;
        }

        $taskId = $this->slotToTask[$slotIndex] ?? null;

        // Clean up cooperative tracking
        if ($taskId !== null) {
            unset($this->cooperativeActive[$taskId]);
            unset($this->slotToTask[$slotIndex]);
            $this->totalTasksCompleted++;

            // Invoke completion callback if registered
            if (isset($this->completionCallbacks[$taskId])) {
                $cb = $this->completionCallbacks[$taskId];
                unset($this->completionCallbacks[$taskId]);
                try {
                    $cb();
                } catch (Throwable) {
                    // Completion callbacks should not throw
                }
            }
        }

        unset($this->activeSlots[$slotIndex]);

        // The worker shell fiber has terminated (the task inside it
        // completed or threw). We need to respawn a fresh worker shell
        // in this slot so the slot can be reused.
        $this->respawnSlot($slotIndex);
    }

    /**
     * Notify the pool that a cooperative fiber has been suspended.
     *
     * This is informational — the scheduler will resume it on the
     * next tick. The pool just needs to know the fiber is still active
     * (not idle).
     *
     * @param Fiber $fiber The suspended fiber.
     */
    public function notifyFiberSuspended(Fiber $fiber): void
    {
        // No-op for now. The fiber is tracked in cooperativeActive
        // and will be resumed by the scheduler. Future: could track
        // suspend count for diagnostics.
    }

    // ═════════════════════════════════════════════════════════════════
    //  Scheduler integration — called from RunBlocking / Launch
    // ═════════════════════════════════════════════════════════════════

    /**
     * Tick the pool: dispatch pending tasks, evaluate auto-scaling.
     *
     * Should be called periodically from the main scheduler loop
     * (RunBlocking, EventLoop, etc.).
     */
    public function tick(): void
    {
        if ($this->shutdown) {
            return;
        }

        $this->dispatchPending();

        if ($this->autoScaling) {
            $this->evaluateScaleDown();
        }
    }

    /**
     * Check if the pool has cooperative tasks that need driving.
     */
    public function hasCooperativeTasks(): bool
    {
        return !empty($this->cooperativeActive);
    }

    /**
     * Check if there are pending tasks waiting for idle fibers.
     */
    public function hasPendingTasks(): bool
    {
        return !$this->pendingTasks->isEmpty();
    }

    /**
     * Drain all pending sync tasks.
     */
    public function drainPending(): void
    {
        while (!$this->pendingTasks->isEmpty()) {
            $this->dispatchPending();
            if (!$this->pendingTasks->isEmpty()) {
                if (Fiber::getCurrent() !== null) {
                    Pause::force();
                } else {
                    $this->driveScheduler();
                    usleep(100);
                }
            }
        }
    }

    // ═════════════════════════════════════════════════════════════════
    //  Introspection & diagnostics
    // ═════════════════════════════════════════════════════════════════

    /**
     * Current total fibers in the pool.
     */
    public function size(): int
    {
        return $this->currentSize;
    }

    /**
     * Number of idle fibers ready for tasks.
     */
    public function idleCount(): int
    {
        return $this->idleQueue->count();
    }

    /**
     * Number of fibers actively running cooperative tasks.
     */
    public function cooperativeActiveCount(): int
    {
        return count($this->cooperativeActive);
    }

    /**
     * Number of pending tasks waiting for a fiber.
     */
    public function pendingCount(): int
    {
        return $this->pendingTasks->count();
    }

    /**
     * Maximum pool size (auto-scaling cap).
     */
    public function getMaxSize(): int
    {
        return $this->maxSize;
    }

    /**
     * Whether the pool is shut down.
     */
    public function isShutdown(): bool
    {
        return $this->shutdown;
    }

    /**
     * Get pool statistics for diagnostics.
     *
     * @return array{
     *     currentSize: int,
     *     idleCount: int,
     *     cooperativeActive: int,
     *     pendingTasks: int,
     *     totalSubmitted: int,
     *     totalCompleted: int,
     *     totalSpawned: int,
     *     totalRecycled: int,
     *     maxSize: int,
     *     autoScaling: bool,
     * }
     */
    public function stats(): array
    {
        return [
            "currentSize" => $this->currentSize,
            "idleCount" => $this->idleQueue->count(),
            "cooperativeActive" => count($this->cooperativeActive),
            "pendingTasks" => $this->pendingTasks->count(),
            "totalSubmitted" => $this->totalTasksSubmitted,
            "totalCompleted" => $this->totalTasksCompleted,
            "totalSpawned" => $this->totalFibersSpawned,
            "totalRecycled" => $this->totalFibersRecycled,
            "maxSize" => $this->maxSize,
            "autoScaling" => $this->autoScaling,
        ];
    }

    /**
     * Check if a given fiber belongs to this pool.
     */
    public function isManagedFiber(Fiber $fiber): bool
    {
        return isset($this->fiberIdToSlot[spl_object_id($fiber)]);
    }

    // ═════════════════════════════════════════════════════════════════
    //  Graceful shutdown
    // ═════════════════════════════════════════════════════════════════

    /**
     * Gracefully shut down the pool.
     *
     * Completes all pending tasks, then terminates all worker shells.
     */
    public function shutdown(): void
    {
        $this->doShutdown();
    }

    // ═════════════════════════════════════════════════════════════════
    //  Internal: Worker shell management
    // ═════════════════════════════════════════════════════════════════

    /**
     * Spawn a new worker shell fiber at the given slot index.
     *
     * The worker shell runs an infinite loop. In COOPERATIVE mode,
     * the callable inside can suspend/resume multiple times — each
     * Fiber::suspend() from Pause/Delay/Channel inside the callable
     * suspends the worker shell, and the scheduler's resume() on the
     * worker shell continues the callable.
     *
     * In SYNC mode, the callable runs to completion in a single
     * resume() call (no internal suspends expected).
     *
     * When the callable finishes (returns or throws), the worker shell
     * stores the result and suspends to signal "task done, I'm idle".
     * The pool then marks the slot as idle.
     *
     * @param int $index Slot index.
     */
    private function spawnWorkerShell(int $index): void
    {
        // References to pool storage for zero-copy result passing
        $resultsRef = &$this->results;
        $errorsRef = &$this->errors;
        $completedRef = &$this->completed;

        // Use a local variable to track if this is a cooperative task.
        // The pool sets this via the task info array.
        $fiber = new Fiber(static function () use (
            &$resultsRef,
            &$errorsRef,
            &$completedRef,
        ): void {
            while (true) {
                // Suspend to signal "idle". The pool resumes us with
                // [$callable, $taskId, $mode] or null (shutdown).
                /** @var array{0: callable, 1: int, 2: int}|null $taskInfo */
                $taskInfo = Fiber::suspend();

                if ($taskInfo === null) {
                    // Shutdown signal
                    return;
                }

                [$callable, $taskId, $mode] = $taskInfo;

                try {
                    $result = $callable();

                    // Handle Generator return values
                    if ($result instanceof \Generator) {
                        $genResult = null;
                        while ($result->valid()) {
                            $genResult = $result->current();
                            $result->next();
                            // In cooperative mode, yield between generator steps
                            if ($mode === 1) {
                                // MODE_COOPERATIVE
                                Pause::new();
                            }
                        }
                        try {
                            $returnValue = $result->getReturn();
                            if ($returnValue !== null) {
                                $genResult = $returnValue;
                            }
                        } catch (\Exception) {
                        }
                        $result = $genResult;
                    }

                    $resultsRef[$taskId] = $result;
                    $completedRef[$taskId] = true;
                } catch (Throwable $e) {
                    $errorsRef[$taskId] = $e;
                    $completedRef[$taskId] = true;
                }

                // Task done — suspend to signal "idle again".
                // For cooperative tasks, this suspend returns control
                // to the scheduler which then calls notifyFiberTerminated
                // ... actually, the fiber is NOT terminated, it just
                // suspended. We use a special sentinel to indicate
                // "task complete, recycle me".
                //
                // We signal completion by suspending with a sentinel value.
                Fiber::suspend("__TASK_COMPLETE__");

                // After being resumed again (with a new task), loop back.
            }
        });

        // Start the fiber — it will run to its first suspend (idle state)
        $fiber->start();

        $fiberId = spl_object_id($fiber);
        $this->fibers[$index] = $fiber;
        $this->fiberIdToSlot[$fiberId] = $index;
        $this->currentSize++;
        $this->totalFibersSpawned++;
        $this->idleQueue->enqueue($index);
        $this->fiberIdleSince[$index] = microtime(true);
    }

    /**
     * Respawn a worker shell in a slot whose previous fiber terminated
     * (e.g., due to an unrecoverable error or unexpected termination).
     */
    private function respawnSlot(int $slotIndex): void
    {
        // Clean up old fiber reference
        $oldFiber = $this->fibers[$slotIndex] ?? null;
        if ($oldFiber !== null) {
            $oldFiberId = spl_object_id($oldFiber);
            unset($this->fiberIdToSlot[$oldFiberId]);
        }
        unset($this->fibers[$slotIndex]);
        $this->currentSize--;

        // Spawn fresh worker shell in the same slot
        $this->spawnWorkerShell($slotIndex);
        $this->totalFibersRecycled++;
    }

    /**
     * Dispatch a task in COOPERATIVE mode to a worker shell.
     *
     * The callable is sent to the worker shell via resume(). Unlike
     * SYNC mode, the callable may call Fiber::suspend() internally
     * (via Pause, Delay, Channel, etc.). Each time it suspends, the
     * worker shell fiber suspends too, and the scheduler will resume
     * it on the next tick.
     *
     * We return the worker shell Fiber itself — Launch/Async will
     * treat it as if it were a regular fiber (check isTerminated,
     * isSuspended, call resume, etc.).
     *
     * IMPORTANT: The worker shell does NOT terminate when the task
     * completes. Instead, it stores the result and suspends with a
     * sentinel '__TASK_COMPLETE__'. The Launch/Async integration
     * layer detects this sentinel via isCooperativeTaskComplete()
     * and calls notifyFiberTerminated().
     *
     * @return Fiber The worker shell fiber.
     */
    private function dispatchCooperative(
        int $slotIndex,
        callable $callable,
        int $taskId,
        ?callable $onComplete,
    ): Fiber {
        $fiber = $this->fibers[$slotIndex];

        if ($fiber->isTerminated()) {
            // Dead fiber — respawn and retry
            $this->respawnSlot($slotIndex);
            $fiber = $this->fibers[$slotIndex];
            // Newly spawned fiber is in idle queue — dequeue it
            $this->removeFromIdleQueue($slotIndex);
        }

        if (!$fiber->isSuspended()) {
            // Unexpected state — fall back to raw fiber
            return $this->createRawFiber($callable, $taskId, $onComplete);
        }

        // Track this as an active cooperative task
        $this->activeSlots[$slotIndex] = $taskId;
        $this->cooperativeActive[$taskId] = $slotIndex;
        $this->slotToTask[$slotIndex] = $taskId;

        if ($onComplete !== null) {
            $this->completionCallbacks[$taskId] = $onComplete;
        }

        // Send the task to the worker shell — it starts executing the
        // callable. If the callable suspends (Pause::new()), this
        // resume() returns and the fiber is in suspended state.
        // If the callable completes without suspending, the fiber
        // suspends with '__TASK_COMPLETE__' sentinel.
        $fiber->resume([$callable, $taskId, self::MODE_COOPERATIVE]);

        // Check if the task completed synchronously (no internal suspends)
        if ($fiber->isSuspended()) {
            // The fiber suspended. It is either:
            //   a) At the __TASK_COMPLETE__ sentinel — task finished
            //      synchronously. We leave the completion markers
            //      ($this->completed, $this->cooperativeActive, etc.)
            //      intact so that Launch/Async can detect completion
            //      via isCooperativeTaskComplete() and explicitly
            //      recycle the fiber via recycleCooperativeFiber().
            //      DO NOT auto-recycle here — the caller needs the
            //      markers to retrieve the result.
            //   b) Mid-task (cooperative suspend via Pause/Delay/Channel)
            //      — the scheduler will resume it on the next tick.
            //
            // In both cases, we just return the fiber as-is.
        } elseif ($fiber->isTerminated()) {
            // Worker shell terminated unexpectedly
            $this->handleUnexpectedTermination($slotIndex, $taskId);
        }

        return $fiber;
    }

    /**
     * Dispatch a task in SYNC mode to a worker shell.
     *
     * The callable runs to completion within the resume() call.
     * By the time resume() returns, the result is stored.
     */
    private function dispatchSync(
        int $slotIndex,
        callable $callable,
        int $taskId,
    ): void {
        $fiber = $this->fibers[$slotIndex];

        if ($fiber->isTerminated()) {
            $this->respawnSlot($slotIndex);
            $fiber = $this->fibers[$slotIndex];
            $this->removeFromIdleQueue($slotIndex);
        }

        if (!$fiber->isSuspended()) {
            // Queue for later
            $this->pendingTasks->enqueue([$callable, $taskId, self::MODE_SYNC]);
            return;
        }

        $this->activeSlots[$slotIndex] = $taskId;

        // Dispatch — task runs synchronously
        $fiber->resume([$callable, $taskId, self::MODE_SYNC]);

        // Task should be complete now
        unset($this->activeSlots[$slotIndex]);
        $this->totalTasksCompleted++;

        // Recycle fiber to idle
        if ($fiber->isSuspended()) {
            // Check if it suspended with completion sentinel
            if (isset($this->completed[$taskId])) {
                // Good — task completed, fiber is at the sentinel suspend.
                // Resume it past the sentinel so it loops back to idle.
                $fiber->resume(null);

                // Now the fiber should be at the top-of-loop suspend (idle)
                if ($fiber->isSuspended()) {
                    $this->fiberIdleSince[$slotIndex] = microtime(true);
                    $this->idleQueue->enqueue($slotIndex);
                    $this->totalFibersRecycled++;
                } elseif ($fiber->isTerminated()) {
                    // Fiber terminated on the null resume (shutdown)
                    $this->respawnSlot($slotIndex);
                }
            } else {
                // Task didn't complete but fiber suspended — shouldn't
                // happen in sync mode. Mark slot as idle anyway.
                $this->fiberIdleSince[$slotIndex] = microtime(true);
                $this->idleQueue->enqueue($slotIndex);
            }
        } elseif ($fiber->isTerminated()) {
            // Worker shell died during task execution
            $this->respawnSlot($slotIndex);
        }
    }

    /**
     * Create a raw (non-pooled) fiber as fallback when the pool is
     * exhausted and can't scale.
     *
     * This ensures the system never deadlocks even when pool capacity
     * is exceeded. The fiber works identically to pre-pool behavior.
     */
    private function createRawFiber(
        callable $callable,
        int $taskId,
        ?callable $onComplete,
    ): Fiber {
        $fiber = new Fiber($callable);

        if ($onComplete !== null) {
            $this->completionCallbacks[$taskId] = $onComplete;
        }

        return $fiber;
    }

    /**
     * Check if a cooperative fiber has completed its task.
     *
     * The worker shell signals completion by storing a result in
     * $this->completed and suspending. The scheduler calls this to
     * know whether a suspended fiber is "idle-after-task" vs
     * "suspended-mid-task".
     *
     * @param Fiber $fiber The fiber to check.
     * @return bool True if the fiber's current task is complete.
     */
    public function isCooperativeTaskComplete(Fiber $fiber): bool
    {
        $fiberId = spl_object_id($fiber);
        $slotIndex = $this->fiberIdToSlot[$fiberId] ?? null;

        if ($slotIndex === null) {
            return false;
        }

        $taskId = $this->slotToTask[$slotIndex] ?? null;
        if ($taskId === null) {
            return false;
        }

        return isset($this->completed[$taskId]);
    }

    /**
     * Recycle a cooperative fiber slot after its task completed.
     *
     * Called when the scheduler detects that a pooled fiber's task
     * is done (via isCooperativeTaskComplete). This resumes the fiber
     * past the completion sentinel, making it idle for the next task.
     *
     * @param Fiber $fiber The fiber to recycle.
     * @return mixed The task's return value (from stored results).
     */
    public function recycleCooperativeFiber(Fiber $fiber): mixed
    {
        $fiberId = spl_object_id($fiber);
        $slotIndex = $this->fiberIdToSlot[$fiberId] ?? null;

        if ($slotIndex === null) {
            // Not a pooled fiber — return null
            return null;
        }

        $taskId = $this->slotToTask[$slotIndex] ?? null;
        $result = null;

        if ($taskId !== null) {
            // Get the result
            if (isset($this->errors[$taskId])) {
                $error = $this->errors[$taskId];
                unset($this->errors[$taskId]);
                unset($this->completed[$taskId]);
                // Let the caller handle the error via fiber->getReturn() pattern
                // Actually, store it so it can be rethrown
                $result = $error;
            } else {
                $result = $this->results[$taskId] ?? null;
                unset($this->results[$taskId]);
                unset($this->completed[$taskId]);
            }
        }

        $this->recycleCompletedSlot($slotIndex, $taskId);

        return $result;
    }

    /**
     * Internal: recycle a slot after task completion.
     */
    private function recycleCompletedSlot(int $slotIndex, ?int $taskId): void
    {
        // Clean up tracking
        if ($taskId !== null) {
            unset($this->cooperativeActive[$taskId]);
            unset($this->completionCallbacks[$taskId]);
            $this->totalTasksCompleted++;
        }
        unset($this->slotToTask[$slotIndex]);
        unset($this->activeSlots[$slotIndex]);

        $fiber = $this->fibers[$slotIndex] ?? null;
        if ($fiber === null || $fiber->isTerminated()) {
            // Fiber is dead — respawn
            if ($fiber !== null) {
                $this->respawnSlot($slotIndex);
            }
            return;
        }

        if ($fiber->isSuspended()) {
            // Resume past the '__TASK_COMPLETE__' sentinel suspend
            // so the fiber loops back to the idle suspend at the top.
            try {
                $fiber->resume(null);
            } catch (Throwable) {
                // If resume fails, respawn
                $this->respawnSlot($slotIndex);
                return;
            }

            if ($fiber->isSuspended()) {
                // Now at idle suspend — mark as idle
                $this->fiberIdleSince[$slotIndex] = microtime(true);
                $this->idleQueue->enqueue($slotIndex);
                $this->totalFibersRecycled++;
            } elseif ($fiber->isTerminated()) {
                // Fiber terminated on null resume
                $this->respawnSlot($slotIndex);
            }
        }
    }

    /**
     * Handle unexpected worker shell termination.
     */
    private function handleUnexpectedTermination(
        int $slotIndex,
        int $taskId,
    ): void {
        // If we don't have a result yet, mark as error
        if (!isset($this->completed[$taskId])) {
            $this->errors[$taskId] = new \RuntimeException(
                "Worker shell fiber terminated unexpectedly during task #{$taskId}",
            );
            $this->completed[$taskId] = true;
        }

        unset($this->cooperativeActive[$taskId]);
        unset($this->slotToTask[$slotIndex]);
        unset($this->activeSlots[$slotIndex]);
        $this->totalTasksCompleted++;

        // Respawn the worker shell
        $this->respawnSlot($slotIndex);
    }

    // ═════════════════════════════════════════════════════════════════
    //  Internal: Pending task dispatch & auto-scaling
    // ═════════════════════════════════════════════════════════════════

    /**
     * Dispatch as many pending tasks as possible to idle fibers.
     */
    private function dispatchPending(): void
    {
        while (
            !$this->pendingTasks->isEmpty() &&
            !$this->idleQueue->isEmpty()
        ) {
            [$callable, $taskId, $mode] = $this->pendingTasks->dequeue();
            $slotIndex = $this->idleQueue->dequeue();
            unset($this->fiberIdleSince[$slotIndex]);

            if ($mode === self::MODE_SYNC) {
                $this->dispatchSync($slotIndex, $callable, $taskId);
            } else {
                $this->dispatchCooperative(
                    $slotIndex,
                    $callable,
                    $taskId,
                    $this->completionCallbacks[$taskId] ?? null,
                );
            }
        }

        // Try auto-scaling if there are still pending tasks
        if (
            !$this->pendingTasks->isEmpty() &&
            $this->autoScaling &&
            $this->currentSize < $this->maxSize
        ) {
            $this->evaluateScaleUp();
            // Retry dispatch after scaling
            while (
                !$this->pendingTasks->isEmpty() &&
                !$this->idleQueue->isEmpty()
            ) {
                [$callable, $taskId, $mode] = $this->pendingTasks->dequeue();
                $slotIndex = $this->idleQueue->dequeue();
                unset($this->fiberIdleSince[$slotIndex]);

                if ($mode === self::MODE_SYNC) {
                    $this->dispatchSync($slotIndex, $callable, $taskId);
                } else {
                    $this->dispatchCooperative(
                        $slotIndex,
                        $callable,
                        $taskId,
                        $this->completionCallbacks[$taskId] ?? null,
                    );
                }
            }
        }
    }

    /**
     * Evaluate whether to scale up (spawn more worker shells).
     */
    private function evaluateScaleUp(): void
    {
        if ($this->pendingTasks->isEmpty()) {
            return;
        }
        if (!$this->idleQueue->isEmpty()) {
            return;
        }
        if ($this->currentSize >= $this->maxSize) {
            return;
        }

        $now = microtime(true);
        if ($now - $this->lastScaleUpTime < self::SCALE_UP_COOLDOWN) {
            return;
        }

        // Spawn one new worker shell
        $newIndex = $this->nextSlotIndex();
        $this->spawnWorkerShell($newIndex);
        $this->lastScaleUpTime = $now;
    }

    /**
     * Evaluate whether to scale down (remove excess idle worker shells).
     *
     * Never shrinks below initialSize.
     */
    private function evaluateScaleDown(): void
    {
        if ($this->currentSize <= $this->initialSize) {
            return;
        }
        if (!$this->pendingTasks->isEmpty()) {
            return;
        }

        $now = microtime(true);
        if ($now - $this->lastScaleDownTime < self::SCALE_DOWN_COOLDOWN) {
            return;
        }

        // Find the fiber idle the longest
        $longestIdleIndex = null;
        $longestIdleTime = 0.0;

        foreach ($this->fiberIdleSince as $index => $since) {
            $duration = $now - $since;
            if (
                $duration >= self::IDLE_TIMEOUT &&
                $duration > $longestIdleTime
            ) {
                $longestIdleTime = $duration;
                $longestIdleIndex = $index;
            }
        }

        if ($longestIdleIndex === null) {
            return;
        }

        $this->removeFiber($longestIdleIndex);
        $this->lastScaleDownTime = $now;
    }

    // ═════════════════════════════════════════════════════════════════
    //  Internal: Fiber slot utilities
    // ═════════════════════════════════════════════════════════════════

    /**
     * Remove a fiber from the pool entirely.
     */
    private function removeFiber(int $index): void
    {
        $this->removeFromIdleQueue($index);

        $fiber = $this->fibers[$index] ?? null;
        if ($fiber !== null) {
            $fiberId = spl_object_id($fiber);
            unset($this->fiberIdToSlot[$fiberId]);

            if ($fiber->isSuspended()) {
                try {
                    $fiber->resume(null); // Shutdown signal
                } catch (Throwable) {
                    // OK
                }
            }
        }

        unset($this->fibers[$index]);
        unset($this->fiberIdleSince[$index]);
        unset($this->activeSlots[$index]);
        unset($this->slotToTask[$index]);
        $this->currentSize--;
    }

    /**
     * Remove a specific index from the idle queue.
     */
    private function removeFromIdleQueue(int $targetIndex): void
    {
        $count = $this->idleQueue->count();
        for ($i = 0; $i < $count; $i++) {
            $index = $this->idleQueue->dequeue();
            if ($index !== $targetIndex) {
                $this->idleQueue->enqueue($index);
            }
        }
    }

    /**
     * Find the next available slot index.
     */
    private function nextSlotIndex(): int
    {
        $index = 0;
        while (isset($this->fibers[$index])) {
            $index++;
        }
        return $index;
    }

    /**
     * Drive the VOsaka scheduler subsystems (for non-Fiber contexts).
     */
    private function driveScheduler(): void
    {
        if (AsyncIO::hasPending()) {
            AsyncIO::pollOnce();
        }

        if (!WorkerPool::isEmpty()) {
            WorkerPool::run();
        }

        if (Launch::getInstance()->hasActiveTasks()) {
            Launch::getInstance()->runOnce();
        }
    }

    /**
     * Internal shutdown implementation.
     */
    private function doShutdown(): void
    {
        if ($this->shutdown) {
            return;
        }

        $this->shutdown = true;

        // Terminate all fibers
        foreach ($this->fibers as $index => $fiber) {
            if ($fiber->isSuspended()) {
                try {
                    $fiber->resume(null);
                } catch (Throwable) {
                    // OK
                }
            }
            $fiberId = spl_object_id($fiber);
            unset($this->fiberIdToSlot[$fiberId]);
        }

        $this->fibers = [];
        $this->activeSlots = [];
        $this->cooperativeActive = [];
        $this->slotToTask = [];
        $this->completionCallbacks = [];
        $this->results = [];
        $this->errors = [];
        $this->completed = [];
        $this->fiberIdleSince = [];
        $this->currentSize = 0;

        while (!$this->idleQueue->isEmpty()) {
            $this->idleQueue->dequeue();
        }
        while (!$this->pendingTasks->isEmpty()) {
            $this->pendingTasks->dequeue();
        }
    }
}
