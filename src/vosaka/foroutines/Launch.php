<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Fiber;
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
 *   FiberUtils::makeFiber() pipeline (reflection checks)
 *   and creates the Fiber directly.
 */
final class Launch extends Job
{
    use Instance;

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
     * @param callable|Async|Fiber $callable The function to run asynchronously.
     * @param Dispatchers $dispatcher The dispatcher to use for the async task.
     * @return Launch
     */
    public static function new(
        callable|Async|Fiber $callable,
        Dispatchers $dispatcher = Dispatchers::DEFAULT ,
    ): Launch {
        if ($dispatcher === Dispatchers::IO) {
            if (WorkerPoolState::$isWorker) {
                $dispatcher = Dispatchers::DEFAULT;
            } else {
                // Arrow function: lighter than function() use() — no explicit
                // use-binding array, fewer opcodes, single-expression body.
                return self::makeLaunch(
                    fn() => WorkerPool::addAsync($callable)->await(),
                );
            }
        }

        if ($dispatcher === Dispatchers::MAIN) {
            return self::makeLaunch(
                fn() => Async::new($callable, Dispatchers::MAIN)->await(),
            );
        }

        return self::makeLaunch($callable);
    }

    private static function makeLaunch(
        callable|Async|Fiber $callable,
    ): Launch {
        // Fast-path: for plain Closure/callable, create Fiber directly
        // without going through FiberUtils::makeFiber() which performs
        // reflection-based checks. This saves ~2-5µs per
        // task for simple callables (the common case).
        //
        // FiberPool::global() is used to track creation statistics for
        // dynamic sizing decisions. The acquire() method creates a
        // standard Fiber (not a pool-loop fiber) because the scheduler
        // needs full lifecycle control.
        if ($callable instanceof \Closure) {
            $fiber = FiberPool::global()->acquire($callable);
        } else {
            $fiber = FiberUtils::makeFiber($callable);
        }

        $id = spl_object_id($fiber);

        // Try to recycle a Job from the pool
        $job = self::acquireFromPool($id, $fiber);

        // Track active count and enqueue
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
            self::returnToPool($job);
            return;
        }

        if ($job->isTimedOut()) {
            // $job->cancel() (Launch override) already decrements
            // $activeCount — do NOT decrement again here.
            $job->cancel();
            self::returnToPool($job);
            return;
        }

        if ($job->isFinal()) {
            self::$activeCount--;
            self::returnToPool($job);
            return;
        }

        try {
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
     * Resets all static state including the object pool.
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
        FiberPool::resetState();
    }
}
