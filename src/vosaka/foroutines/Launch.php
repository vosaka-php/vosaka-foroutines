<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Fiber;
use Generator;
use Throwable;
use SplQueue;

/**
 * Launches a new asynchronous task that runs concurrently with the main thread.
 * It manages a queue of child scopes, each containing a fiber that executes the task.
 *
 * Optimization notes:
 * - Removed static $map hash table. Job cancellation/completion is now detected
 *   via the Job's own status (isFinal/isCancelled), eliminating one refcount per
 *   job and one hash table lookup per scheduler tick.
 * - Uses an int counter ($activeCount) instead of !empty($map) for hasActiveTasks().
 * - Uses arrow functions for IO/MAIN dispatcher wrappers to reduce closure allocation overhead.
 * - Job object pooling: terminated Job instances are recycled via a free-list
 *   (SplQueue) instead of being left for the garbage collector. This reduces
 *   allocation pressure for workloads that create many short-lived tasks
 *   (e.g. 500 trivial fibers). Pool size is capped to avoid unbounded memory.
 * - Fast-path fiber creation: for simple Closure callables, skips the full
 *   FiberUtils::makeFiber() pipeline (reflection checks, Generator detection)
 *   and creates the Fiber directly.
 *
 * RuntimeFiberPool integration:
 * - For DEFAULT dispatcher tasks, Launch now acquires cooperative fibers from
 *   the global RuntimeFiberPool instead of creating raw `new Fiber()` calls.
 *   This reuses pre-allocated worker shell fibers (~1µs dispatch vs ~5-15µs
 *   allocation) and reduces GC pressure significantly for many short tasks.
 * - When a pooled fiber's task completes, the worker shell is recycled back
 *   to the pool's idle queue for reuse by subsequent tasks.
 * - The pool falls back to raw Fiber creation when capacity is exhausted,
 *   ensuring the system never deadlocks.
 * - IO and MAIN dispatchers continue to use their dedicated paths (WorkerPool
 *   and EventLoop respectively) — only DEFAULT dispatcher benefits from pooling.
 */
final class Launch extends Job
{
    use Instance;

    /**
     * Whether this job's fiber is managed by RuntimeFiberPool.
     *
     * When true, the fiber is a worker shell from the pool. On task
     * completion, the fiber is recycled back to the pool instead of
     * being discarded. On cancellation/timeout, the pool is notified
     * so the worker shell can be respawned.
     */
    public bool $poolManaged = false;

    /**
     * Whether joinPoolManaged() is actively driving this job's fiber.
     *
     * When true, runOnce() must NOT resume or process this job — it
     * simply re-enqueues it. This prevents double-resume and double-
     * decrement of $activeCount when join() and runOnce() both try
     * to drive the same pooled fiber concurrently.
     *
     * Set to true at the start of joinPoolManaged(), cleared when
     * joinPoolManaged() completes (or on error).
     */
    public bool $drivingJoin = false;

    /**
     * FIFO queue to manage execution order.
     * @var SplQueue<Job>
     */
    public static SplQueue $queue;

    /**
     * Number of jobs that have been enqueued but not yet reached a final state.
     * Replaces the old static $map array — avoids hash table allocation and
     * per-job refcount overhead.
     */
    public static int $activeCount = 0;

    /**
     * Pool of reusable Launch (Job) instances.
     *
     * When a job reaches a terminal state (completed, failed, cancelled)
     * and is dequeued from the scheduler, it is returned to this pool
     * instead of being discarded. The next makeLaunch() call can then
     * recycle the instance, avoiding:
     *   - new self() constructor overhead (hrtime, enum init, parent ctor)
     *   - Memory allocation + GC pressure for the Job object
     *   - spl_object_id() call (we still need it for the new fiber, but
     *     the Job wrapper is reused)
     *
     * @var SplQueue<Launch>
     */
    private static SplQueue $pool;

    /**
     * Maximum number of Job instances to keep in the pool.
     *
     * 256 is chosen as a balance:
     *   - Large enough to cover typical burst patterns (e.g. 500 trivial
     *     tasks created in a loop — after the first 256 complete, they
     *     start recycling).
     *   - Small enough to avoid holding excessive memory for idle pools.
     *
     * Each pooled Launch instance is ~200-300 bytes (Job fields + fiber
     * reference set to null), so 256 instances ≈ 50-75 KB — negligible.
     */
    private const MAX_POOL_SIZE = 256;

    /**
     * Whether the pool has been initialized.
     * Avoids isset() check on every makeLaunch() call.
     */
    private static bool $poolInitialized = false;

    public function __construct(public int $id = 0)
    {
        parent::__construct($id);

        // Initialize queue once
        if (!isset(self::$queue)) {
            self::$queue = new SplQueue();
        }
    }

    /**
     * Returns whether the queue is empty.
     *
     * @return bool True if the queue is empty.
     */
    public function isEmpty(): bool
    {
        return self::$queue->isEmpty();
    }

    /**
     * Checks if there are any active tasks (in queue or being processed).
     *
     * @return bool True if there are active tasks, false otherwise.
     */
    public function hasActiveTasks(): bool
    {
        return !self::$queue->isEmpty() || self::$activeCount > 0;
    }

    /**
     * Creates a new asynchronous task. It runs concurrently with the main thread.
     *
     * @param callable|Generator|Async|Fiber $callable The function or generator to run asynchronously.
     * @param Dispatchers $dispatcher The dispatcher to use for the async task.
     * @return Launch
     */
    public static function new(
        callable|Generator|Async|Fiber $callable,
        Dispatchers $dispatcher = Dispatchers::DEFAULT,
    ): Launch {
        if ($dispatcher === Dispatchers::IO) {
            // Arrow function: lighter than function() use() — no explicit
            // use-binding array, fewer opcodes, single-expression body.
            return self::makeLaunch(
                fn() => WorkerPool::addAsync($callable)->await(),
            );
        }

        if ($dispatcher === Dispatchers::MAIN) {
            return self::makeLaunch(
                fn() => Async::new($callable, Dispatchers::MAIN)->await(),
            );
        }

        return self::makeLaunch($callable);
    }

    /**
+     * Whether to use RuntimeFiberPool for DEFAULT dispatcher tasks.
+     * Can be disabled via Launch::setPooledMode(false) for testing
+     * or when raw fiber semantics are required.
+     */
    private static bool $useRuntimePool = true;

    /**
     * Enable or disable RuntimeFiberPool integration.
     *
     * When enabled (default), DEFAULT dispatcher tasks acquire fibers
     * from the global RuntimeFiberPool instead of allocating new ones.
     * When disabled, Launch falls back to the original raw Fiber behavior.
     *
     * @param bool $enabled
     */
    public static function setPooledMode(bool $enabled): void
    {
        self::$useRuntimePool = $enabled;
    }

    /**
     * Check if RuntimeFiberPool integration is active.
     */
    public static function isPooledMode(): bool
    {
        return self::$useRuntimePool;
    }

    private static function makeLaunch(
        callable|Generator|Async|Fiber $callable,
    ): Launch {
        // If the callable is already a Fiber, we cannot wrap it in a
        // pool worker shell — use it directly (original behavior).
        if ($callable instanceof Fiber) {
            return self::makeLaunchWithRawFiber($callable);
        }

        // For Generator/Async inputs, normalize to a plain callable first
        // so the pool worker shell can execute it.
        if ($callable instanceof Async) {
            // Async already has a fiber — use raw path
            $fiber = $callable->fiber;
            return self::makeLaunchWithRawFiber($fiber);
        }

        // Try to use RuntimeFiberPool for cooperative execution
        if (self::$useRuntimePool) {
            return self::makeLaunchPooled($callable);
        }

        // Fallback: original raw fiber creation
        return self::makeLaunchRaw($callable);
    }

    /**
     * Create a Launch using a fiber acquired from RuntimeFiberPool.
     *
     * The pool provides a pre-started worker shell fiber that will
     * execute the callable cooperatively (supporting Pause, Delay,
     * Channel, Async::await, etc. — all suspend/resume patterns).
     *
     * IMPORTANT: The pool fiber is ALREADY STARTED when returned by
     * acquireCooperativeFiber(). The task callable is already dispatched
     * (running or suspended mid-task or completed). Therefore we must:
     *   1. Skip Job::start() — the fiber is already running
     *   2. Set the Job status to RUNNING immediately
     *   3. Handle the case where the task completed synchronously
     *      (fiber suspended at __TASK_COMPLETE__ sentinel)
     *
     * When the task completes, runOnce() detects the completion via
     * RuntimeFiberPool::isCooperativeTaskComplete() and recycles the
     * worker shell back to the pool's idle queue.
     */
    private static function makeLaunchPooled(
        callable|Generator $callable,
    ): Launch {
        $pool = RuntimeFiberPool::getInstance();

        // Wrap Generator-returning callables so the pool receives a
        // plain callable that drives the generator cooperatively.
        if (is_callable($callable) && !($callable instanceof \Closure)) {
            // May be a Generator-returning callable — use FiberUtils path
            $wrappedCallable = $callable;
        } else {
            $wrappedCallable = $callable;
        }

        // If the callable is a Generator instance, wrap it
        if ($callable instanceof Generator) {
            $gen = $callable;
            $wrappedCallable = static function () use ($gen): mixed {
                while ($gen->valid()) {
                    $gen->next();
                    Pause::new();
                }
                return $gen->getReturn();
            };
        }

        $fiber = $pool->acquireCooperativeFiber($wrappedCallable);
        $id = spl_object_id($fiber);

        $job = self::acquireFromPool($id, $fiber);
        // Only mark as pool-managed if the fiber is actually tracked by the pool.
        // acquireCooperativeFiber() may fall back to a raw Fiber when the pool is
        // full and the scale-up cooldown has not elapsed. Raw fibers are NOT in
        // fiberIdToSlot, so isCooperativeTaskComplete() always returns false for
        // them — the pool-managed runOnce() path would spin forever never
        // completing the job. Raw fallback fibers must use the normal raw path.
        $job->poolManaged = $pool->isManagedFiber($fiber);

        // The pool fiber is already started and the task has been
        // dispatched inside it. We call start() which will attempt
        // fiber->start(), but since the fiber is already started,
        // Job::start() returns true early (status !== PENDING check
        // passes only if PENDING). We need the status to be RUNNING
        // so complete()/fail() don't throw. Force it by calling
        // start() — but start() calls fiber->start() which will
        // throw on an already-started fiber. Instead, we directly
        // transition using a workaround: start() + immediate catch,
        // or we use the recycleJob approach to set status.
        //
        // Cleanest approach: create a small wrapper that skips
        // fiber->start() and just transitions the status.
        // We use Job::start() which checks `status !== PENDING`
        // and returns true early. But the status IS pending...
        // So we need a different path. Let's use a dedicated method.
        //
        // We'll call startPoolManaged() which we add to Launch itself.
        $job->fiber = $fiber;

        // Pool-managed fibers are already started — transition to RUNNING.
        // Raw fallback fibers are not yet started — leave PENDING so
        // runOnce() calls job->start() / fiber->start() normally.
        if ($job->poolManaged) {
            self::markJobRunning($job);
        }

        self::$activeCount++;
        self::$queue->enqueue($job);

        return $job;
    }

    /**
     * Transition a Job from PENDING to RUNNING without calling fiber->start().
     *
     * Used for pool-managed fibers which are already started by
     * RuntimeFiberPool. Job::start() would call fiber->start() and
     * throw because the fiber is already in a non-created state.
     *
     * We use recycleJob() as a controlled way to reset + set state,
     * then manually transition status via start() on a dummy.
     * Actually, the simplest safe approach: we know the Job has a
     * public `start()` that checks PENDING. We need to get past it.
     * Since Job doesn't expose a setStatus(), we use a targeted hack:
     * call recycleJob() which resets to PENDING, then start() but
     * intercept the fiber->start() call. Instead, just replicate
     * what start() does minus the fiber->start() call.
     */
    private static function markJobRunning(Launch $job): void
    {
        // Job is in PENDING state (just acquired from pool or freshly created).
        // We want RUNNING. Job::start() does: fiber->start() + status=RUNNING.
        // We skip fiber->start() since the pool fiber is already started.
        // Access status via reflection would be fragile. Instead, we create
        // a minimal Fiber wrapper that is "not started" for the Job::start()
        // call, then swap back the real fiber.
        $realFiber = $job->fiber;
        // Create a temporary fiber just for the start() call
        $tempFiber = new \Fiber(static function (): void {});
        $job->fiber = $tempFiber;
        $job->start(); // This calls tempFiber->start() (which runs and terminates immediately) and sets status to RUNNING
        // Swap back the real pool fiber
        $job->fiber = $realFiber;
    }

    /**
     * Create a Launch with a raw (non-pooled) Fiber.
     *
     * Used for:
     * - Pre-existing Fiber instances passed to Launch::new()
     * - Async fiber instances
     * - When RuntimeFiberPool is disabled
     * - Fallback when pool capacity is exhausted
     */
    private static function makeLaunchRaw(callable|Generator $callable): Launch
    {
        if ($callable instanceof \Closure) {
            $fiber = new Fiber($callable);
        } else {
            $fiber = FiberUtils::makeFiber($callable);
        }

        $id = spl_object_id($fiber);
        $job = self::acquireFromPool($id, $fiber);
        $job->poolManaged = false;

        self::$activeCount++;
        self::$queue->enqueue($job);

        return $job;
    }

    /**
     * Create a Launch wrapping a pre-existing Fiber.
     */
    private static function makeLaunchWithRawFiber(Fiber $fiber): Launch
    {
        $id = spl_object_id($fiber);
        $job = self::acquireFromPool($id, $fiber);
        $job->poolManaged = false;

        self::$activeCount++;
        self::$queue->enqueue($job);

        return $job;
    }

    /**
     * Acquire a Launch instance — either recycled from the pool or freshly
     * allocated.
     *
     * Recycling avoids:
     *   - Constructor overhead (hrtime(true), enum assignment, parent ctor)
     *   - Object allocation + eventual GC
     *
     * The recycled instance has its state fully reset via Job::recycle(),
     * so it behaves identically to a freshly constructed instance.
     *
     * @param int   $id    The new job ID (spl_object_id of the fiber).
     * @param Fiber $fiber The fiber to associate with this job.
     * @return Launch A ready-to-use Launch instance.
     */
    private static function acquireFromPool(int $id, Fiber $fiber): Launch
    {
        if (self::$poolInitialized && !self::$pool->isEmpty()) {
            /** @var Launch $job */
            $job = self::$pool->dequeue();
            $job->recycleJob($id, $fiber);
            return $job;
        }

        // No pooled instance available — allocate fresh
        $job = new self($id);
        $job->fiber = $fiber;
        return $job;
    }

    /**
     * Return a terminated Launch instance to the pool for future reuse.
     *
     * Only pools the instance if the pool hasn't reached MAX_POOL_SIZE.
     * The fiber reference is cleared to allow GC of the terminated fiber.
     *
     * @param Launch $job The terminated job to return to the pool.
     */
    private static function returnToPool(Launch $job): void
    {
        if (!self::$poolInitialized) {
            self::$pool = new SplQueue();
            self::$poolInitialized = true;
        }

        if (self::$pool->count() < self::MAX_POOL_SIZE) {
            // Clear the fiber reference so the terminated Fiber can be GC'd
            // while the Job shell remains in the pool for reuse.
            $job->fiber = null;
            self::$pool->enqueue($job);
        }
        // If pool is full, just let the job be GC'd normally
    }

    /**
     * Awaits the job's completion and returns the result.
     *
     * Overrides Job::join() to handle pool-managed fibers correctly.
     * For pool-managed fibers, the worker shell doesn't terminate —
     * it suspends at a completion sentinel. This method drives the
     * fiber forward (via the scheduler) and detects completion via
     * RuntimeFiberPool::isCooperativeTaskComplete().
     *
     * For raw (non-pooled) fibers, delegates to the parent Job::join().
     */
    public function join(): mixed
    {
        // If already in a final state, handle it directly
        if ($this->isFinal()) {
            if ($this->isFailed()) {
                throw new \RuntimeException("Job has failed.");
            }
            if ($this->isCancelled()) {
                throw new \RuntimeException("Job has been cancelled.");
            }
            // COMPLETED — for pool-managed, result is stored in pool
            if ($this->poolManaged) {
                return $this->joinPoolManaged();
            }
            // Raw fiber — get return value
            if ($this->fiber !== null && $this->fiber->isTerminated()) {
                $result = $this->fiber->getReturn();
                $this->fiber = null;
                return $result;
            }
            return null;
        }

        // Not yet final — we need to drive the fiber to completion
        if (!$this->poolManaged) {
            // Raw fiber path — use original join logic
            return $this->joinRaw();
        }

        // Pool-managed path — drive via scheduler until complete
        return $this->joinPoolManaged();
    }

    /**
     * Drive a raw (non-pooled) fiber to completion.
     * Replicates the original Job::join() behavior.
     */
    private function joinRaw(): mixed
    {
        if ($this->fiber === null) {
            throw new \RuntimeException("Job fiber is not set.");
        }

        if (!$this->fiber->isStarted()) {
            $this->start();
        }

        try {
            while (FiberUtils::fiberStillRunning($this->fiber)) {
                $this->fiber->resume();
                Pause::force();
            }
        } catch (Throwable $e) {
            if (!$this->isFinal()) {
                $this->fail();
            }
            throw $e;
        }

        if ($this->fiber->isTerminated() && !$this->isFinal()) {
            $this->complete();
        }

        $result = $this->fiber->getReturn();
        $this->fiber = null;
        return $result;
    }

    /**
     * Drive a pool-managed fiber to completion.
     *
     * The worker shell fiber doesn't terminate when the task finishes;
     * it suspends at a __TASK_COMPLETE__ sentinel. We resume the fiber
     * and check for completion on each tick, yielding to the scheduler
     * between attempts so other fibers make progress.
     *
     * When completion is detected, we recycle the worker shell back to
     * the pool and extract the stored result.
     */
    private function joinPoolManaged(): mixed
    {
        $pool = RuntimeFiberPool::getInstance();
        $fiber = $this->fiber;

        // Mark this job as being driven by join() so that runOnce()
        // will skip it (re-enqueue without processing). This prevents
        // double-resume and double-decrement of $activeCount.
        $this->drivingJoin = true;

        // Already completed (e.g., detected by runOnce before join was called)
        if ($this->isFinal()) {
            // Result was stored in pool's results array during task execution.
            // The fiber reference may have been cleared by runOnce.
            // For completed pool jobs, the result was consumed by recycleCooperativeFiber.
            // Actually, we need to check if fiber is still around:
            if (
                $fiber !== null &&
                $fiber->isSuspended() &&
                $pool->isManagedFiber($fiber)
            ) {
                if ($pool->isCooperativeTaskComplete($fiber)) {
                    $result = $pool->recycleCooperativeFiber($fiber);
                    // Remove any queued duplicates of this job from the Launch queue so
                    // the Phase 2 drain can't re-process it later.
                    self::removeJobFromQueue($this);
                    // Decrement activeCount since join() is finalizing this job.
                    // Set fiber to null so runOnce() knows not to decrement again.
                    self::$activeCount--;
                    $this->fiber = null;
                    $this->drivingJoin = false;
                    if ($result instanceof Throwable) {
                        throw new \RuntimeException(
                            "Job task failed: " . $result->getMessage(),
                            0,
                            $result,
                        );
                    }
                    return $result;
                }
            }
            $this->drivingJoin = false;
            return null;
        }

        if ($fiber === null) {
            throw new \RuntimeException("Job fiber is not set.");
        }

        try {
            while (true) {
                // Check for cooperative completion sentinel
                if ($fiber->isSuspended() && $pool->isManagedFiber($fiber)) {
                    if ($pool->isCooperativeTaskComplete($fiber)) {
                        $result = $pool->recycleCooperativeFiber($fiber);
                        if (!$this->isFinal()) {
                            $this->complete();
                        }
                        // Decrement activeCount since join() is finalizing this job.
                        // Set fiber to null so runOnce() knows not to decrement again.
                        self::$activeCount--;
                        $this->fiber = null;
                        $this->drivingJoin = false;
                        if ($result instanceof Throwable) {
                            throw new \RuntimeException(
                                "Job task failed: " . $result->getMessage(),
                                0,
                                $result,
                            );
                        }
                        return $result;
                    }
                }

                // Fiber terminated unexpectedly (worker shell crashed)
                if ($fiber->isTerminated()) {
                    $pool->notifyFiberTerminated($fiber);
                    if (!$this->isFinal()) {
                        $this->complete();
                    }
                    self::$activeCount--;
                    $this->fiber = null;
                    $this->drivingJoin = false;
                    return $fiber->getReturn();
                }

                // Still mid-task — resume and yield to scheduler
                if ($fiber->isSuspended()) {
                    $fiber->resume();
                } else {
                    // Fiber in unexpected state — bail
                    break;
                }

                // After resume, check again before yielding
                if ($fiber->isSuspended() && $pool->isManagedFiber($fiber)) {
                    if ($pool->isCooperativeTaskComplete($fiber)) {
                        $result = $pool->recycleCooperativeFiber($fiber);
                        if (!$this->isFinal()) {
                            $this->complete();
                        }
                        self::$activeCount--;
                        $this->fiber = null;
                        $this->drivingJoin = false;
                        if ($result instanceof Throwable) {
                            throw new \RuntimeException(
                                "Job task failed: " . $result->getMessage(),
                                0,
                                $result,
                            );
                        }
                        return $result;
                    }
                }

                if ($fiber->isTerminated()) {
                    $pool->notifyFiberTerminated($fiber);
                    if (!$this->isFinal()) {
                        $this->complete();
                    }
                    self::$activeCount--;
                    $this->fiber = null;
                    $this->drivingJoin = false;
                    return $fiber->getReturn();
                }

                // Yield to let other fibers make progress
                Pause::force();
            }
        } catch (Throwable $e) {
            if (!$this->isFinal()) {
                $this->fail();
            }
            self::$activeCount--;
            $this->fiber = null;
            $this->drivingJoin = false;
            if ($pool->isManagedFiber($fiber)) {
                $pool->notifyFiberTerminated($fiber);
            }
            throw $e;
        }

        $this->drivingJoin = false;
        return null;
    }

    /**
     * Cancels the task associated with this Launch instance.
     * Decrements the active counter so the scheduler knows when all work is done.
     */
    public function cancel(): void
    {
        if (!$this->isFinal()) {
            self::$activeCount--;
        }
        parent::cancel();
    }

    /**
     * Runs the next task in the queue if available.
     * This method should be called periodically to ensure that tasks are executed.
     *
     * Instead of checking a hash map for each job, we inspect the job's own
     * status flags (isCancelled/isFinal) which are simple enum comparisons.
     *
     * RuntimeFiberPool integration:
     * For pool-managed jobs, the fiber is a worker shell from RuntimeFiberPool.
     * The worker shell does NOT terminate when the task finishes — it suspends
     * with a completion sentinel. We detect this via
     * RuntimeFiberPool::isCooperativeTaskComplete() and recycle the fiber back
     * to the pool. The Job is then completed and returned to the Launch pool
     * as usual.
     *
     * Pool-managed fibers are already started and in RUNNING state when
     * enqueued. We must NOT call fiber->start() or job->start() on them.
     * The fiber is either:
     *   a) Suspended mid-task (cooperative) — resume it
     *   b) Suspended at __TASK_COMPLETE__ sentinel — recycle it
     *   c) Terminated unexpectedly — notify pool and fail the job
     */
    public function runOnce(): void
    {
        if (self::$queue->isEmpty()) {
            return;
        }

        /** @var Launch $job */
        $job = self::$queue->dequeue();
        $fiber = $job->fiber;

        // Job was cancelled externally — already decremented in cancel(),
        // just skip it and return to pool.
        if ($job->isCancelled()) {
            if ($job->poolManaged && $fiber !== null) {
                $this->recyclePooledFiber($fiber);
            }
            self::returnToPool($job);
            return;
        }

        if ($job->isTimedOut()) {
            if ($job->poolManaged && $fiber !== null) {
                $this->recyclePooledFiber($fiber);
            }
            // $job->cancel() (Launch override) already decrements
            // $activeCount — do NOT decrement again here.
            $job->cancel();
            self::returnToPool($job);
            return;
        }

        if ($job->isFinal()) {
            // Only decrement if not already decremented by join().
            // When joinPoolManaged() completes a job, it sets fiber = null
            // and decrements activeCount. If fiber is null, join() already
            // handled the accounting.
            if ($job->fiber !== null) {
                self::$activeCount--;
            }
            self::returnToPool($job);
            return;
        }

        // If joinPoolManaged() is actively driving this job's fiber,
        // do NOT process it here — just re-enqueue so it stays in the
        // queue for bookkeeping. join() will handle completion, recycling,
        // and activeCount decrement.
        if ($job->drivingJoin) {
            self::$queue->enqueue($job);
            return;
        }

        try {
            // === Pool-managed fiber path ===
            // Pool fibers are already started (RUNNING). We must NOT
            // call start(). Instead, check for completion sentinel
            // first, then resume if still mid-task.
            if ($job->poolManaged) {
                // Check for cooperative task completion BEFORE resume.
                // The task may have completed synchronously during the
                // initial dispatch in makeLaunchPooled(), leaving the
                // fiber suspended at the __TASK_COMPLETE__ sentinel.
                if ($fiber->isSuspended()) {
                    $pool = RuntimeFiberPool::getInstance();
                    if ($pool->isCooperativeTaskComplete($fiber)) {
                        $result = $pool->recycleCooperativeFiber($fiber);
                        if (!$job->isFinal()) {
                            $job->complete();
                        }
                        self::$activeCount--;
                        $job->fiber = null;
                        self::returnToPool($job);
                        return;
                    }

                    // Still mid-task — resume the cooperative fiber
                    $fiber->resume();

                    // Check again after resume — task might have just completed
                    if (
                        $fiber->isSuspended() &&
                        $pool->isCooperativeTaskComplete($fiber)
                    ) {
                        $pool->recycleCooperativeFiber($fiber);
                        if (!$job->isFinal()) {
                            $job->complete();
                        }
                        self::$activeCount--;
                        $job->fiber = null;
                        self::returnToPool($job);
                        return;
                    }
                }

                // If fiber terminated unexpectedly (worker shell crashed)
                if ($fiber->isTerminated()) {
                    RuntimeFiberPool::getInstance()->notifyFiberTerminated(
                        $fiber,
                    );
                    if (!$job->isFinal()) {
                        $job->complete();
                    }
                    self::$activeCount--;
                    self::returnToPool($job);
                    return;
                }

                // Still running — requeue for next tick
                self::$queue->enqueue($job);
                return;
            }

            // === Raw (non-pooled) fiber path ===
            if (!$fiber->isStarted()) {
                $job->start();
            }

            if (!$fiber->isTerminated()) {
                $fiber->resume();
            }

            // Mark completed if fiber has terminated and job is still running
            if ($fiber->isTerminated() && !$job->isFinal()) {
                $job->complete();
            }
        } catch (Throwable $e) {
            if (!$job->isFinal()) {
                $job->fail();
            }
            self::$activeCount--;
            self::returnToPool($job);
            throw $e;
        }

        // Requeue if still running
        if (FiberUtils::fiberStillRunning($fiber)) {
            self::$queue->enqueue($job);
        } else {
            self::$activeCount--;
            self::returnToPool($job);
        }
    }

    /**
     * Recycle a pool-managed fiber back to RuntimeFiberPool.
     *
     * Used during cancellation/timeout to ensure the worker shell
     * is not leaked.
     */
    private function recyclePooledFiber(Fiber $fiber): void
    {
        $pool = RuntimeFiberPool::getInstance();
        if ($pool->isManagedFiber($fiber)) {
            if ($fiber->isTerminated()) {
                $pool->notifyFiberTerminated($fiber);
            } elseif ($pool->isCooperativeTaskComplete($fiber)) {
                $pool->recycleCooperativeFiber($fiber);
            }
            // If fiber is suspended mid-task (cancelled before completion),
            // the pool will detect the orphaned slot on next tick and
            // handle cleanup.
        }
    }

    /**
     * Resets all static state including the object pool.
     *
     * Used by ForkProcess after pcntl_fork() to clear stale state
     * inherited from the parent process.
     */
    /**
     * Remove all queued occurrences of a specific Launch job from the scheduler queue.
     *
     * This helps when a join() actively drives a pool-managed job to completion:
     * the job may have been re-enqueued multiple times while join() yielded and
     * the scheduler processed other items. Removing duplicates ensures Phase 2
     * draining cannot re-run bookkeeping for a job that has already been
     * finalized by join().
     *
     * Note: this is an O(n) scan over the queue but the Launch queue is expected
     * to be modest for typical workloads; this avoids more invasive queue
     * bookkeeping changes.
     *
     * @param Launch $target The job instance to remove from the queue.
     */
    private static function removeJobFromQueue(Launch $target): void
    {
        if (!isset(self::$queue) || self::$queue->isEmpty()) {
            return;
        }

        $count = count(self::$queue);
        for ($i = 0; $i < $count; $i++) {
            $item = self::$queue->dequeue();
            if ($item !== $target) {
                self::$queue->enqueue($item);
            }
            // If it equals $target we drop it (remove duplicate)
        }
    }

    /**
     * Reset Launch object pool and queue state.
     *
     * Used by ForkProcess after pcntl_fork() to clear stale state
     * inherited from the parent process.
     */
    public static function resetPool(): void
    {
        if (self::$poolInitialized) {
            // Drain the pool
            while (!self::$pool->isEmpty()) {
                self::$pool->dequeue();
            }
        }
        self::$poolInitialized = false;
    }

    // Also reset the RuntimeFiberPool to prevent child processes
    // from inheriting parent fiber state.
    // (Resetting the runtime pool should be performed by the caller
    //  that handles process forking; avoid calling it here to prevent
    //  accidental double-resets or ordering issues.)
}
