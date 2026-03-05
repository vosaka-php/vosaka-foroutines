<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Fiber;
use SplQueue;
use Throwable;
use RuntimeException;

/**
 * FiberPool — A pool of reusable Fiber instances for zero-allocation task submission.
 *
 * Instead of creating a new Fiber for every task (which costs ~5-15µs per
 * allocation + GC pressure), a FiberPool pre-creates a fixed number of
 * Fiber "worker shells" that loop internally, waiting for tasks to be
 * submitted. Each submit() call simply sends a callable to an idle Fiber
 * (~1µs) with zero additional memory allocation.
 *
 * === RuntimeFiberPool integration ===
 *
 * FiberPool now delegates to the global RuntimeFiberPool singleton for
 * fiber management. This means:
 *   - All FiberPool instances share the same underlying worker shells
 *     with Launch, Async, and other scheduler primitives.
 *   - Auto-scaling, lifecycle management, and fiber recycling are handled
 *     centrally by RuntimeFiberPool.
 *   - Creating a FiberPool with a custom size will ensure the global pool
 *     has at least that many fibers available (it may grow the pool).
 *   - The public API (submit, getResult, drainAll, awaitAll, close) remains
 *     identical — existing code does not need to change.
 *
 * Tasks submitted via FiberPool use SYNC mode by default — the callable
 * executes to completion within a single resume() call (no internal
 * suspends). For cooperative tasks that may Pause/Delay/etc., use
 * Launch::new() or Async::new() instead.
 *
 * Usage:
 *   $pool = FiberPool::new(size: 10);
 *   $ticket1 = $pool->submit(fn() => heavyComputation(1));
 *   $ticket2 = $pool->submit(fn() => heavyComputation(2));
 *   $result1 = $pool->getResult($ticket1);
 *   $result2 = $pool->getResult($ticket2);
 *
 *   // Or fluent chaining:
 *   FiberPool::new(10)
 *       ->submit(fn() => handleRequest(1))
 *       ->submit(fn() => handleRequest(2))
 *       ->submit(fn() => handleRequest(3))
 *       ->drainAll();
 *
 * Integration with the scheduler:
 *   FiberPool works with the existing Pause / Launch / RunBlocking
 *   infrastructure. When called from within a Fiber context, submit()
 *   cooperatively yields while waiting for an idle fiber. When called
 *   from outside a Fiber, it drives the scheduler manually.
 */
final class FiberPool
{
    // ─── Instance state ──────────────────────────────────────────────

    /**
     * Task IDs managed by THIS FiberPool instance.
     * Each FiberPool tracks its own submitted tasks independently,
     * even though they share the global RuntimeFiberPool worker shells.
     * @var array<int, bool>
     */
    private array $managedTasks = [];

    /**
     * Per-ticket results cached locally after retrieval from RuntimeFiberPool.
     * @var array<int, mixed>
     */
    private array $localResults = [];

    /**
     * Per-ticket errors cached locally.
     * @var array<int, Throwable>
     */
    private array $localErrors = [];

    /**
     * Per-ticket completion flags.
     * @var array<int, bool>
     */
    private array $localCompleted = [];

    /**
     * Whether the pool has been shut down.
     */
    private bool $shutdown = false;

    // ─── Configuration ───────────────────────────────────────────────

    /**
     * Initial pool size requested by this instance.
     */
    private int $initialSize;

    /**
     * Maximum pool size (auto-scaling cap).
     */
    private int $maxSize;

    /**
     * Whether smart auto-scaling is enabled.
     */
    private bool $autoScaling;

    /**
     * Scale-up cooldown in seconds.
     */
    private float $scaleUpCooldown;

    /**
     * Scale-down cooldown in seconds.
     */
    private float $scaleDownCooldown;

    /**
     * Idle timeout before scale-down in seconds.
     */
    private float $idleTimeout;

    // ═════════════════════════════════════════════════════════════════
    //  Constructor & Factory
    // ═════════════════════════════════════════════════════════════════

    /**
     * @param int   $size             Initial number of pooled fibers (default: 5).
     * @param int   $maxSize          Maximum fibers when auto-scaling (0 = 4× initial).
     * @param bool  $autoScaling      Enable smart auto-scaling.
     * @param float $scaleUpCooldown  Seconds between scale-up events.
     * @param float $scaleDownCooldown Seconds between scale-down events.
     * @param float $idleTimeout      Seconds a fiber must be idle before scale-down.
     */
    private function __construct(
        int $size = 5,
        int $maxSize = 0,
        bool $autoScaling = true,
        float $scaleUpCooldown = 0.3,
        float $scaleDownCooldown = 5.0,
        float $idleTimeout = 10.0,
    ) {
        $this->initialSize = max(1, $size);
        $this->maxSize =
            $maxSize > 0
                ? max($maxSize, $this->initialSize)
                : $this->initialSize * 4;
        $this->autoScaling = $autoScaling;
        $this->scaleUpCooldown = $scaleUpCooldown;
        $this->scaleDownCooldown = $scaleDownCooldown;
        $this->idleTimeout = $idleTimeout;

        // Ensure the global RuntimeFiberPool has at least $size fibers.
        // If it's already booted with more, this is a no-op for the excess.
        RuntimeFiberPool::configure(
            initialSize: $this->initialSize,
            maxSize: $this->maxSize,
            autoScaling: $this->autoScaling,
        );
    }

    /**
     * Create a new FiberPool.
     *
     * @param int   $size        Initial number of pooled fibers (default: 10).
     * @param int   $maxSize     Maximum fibers when auto-scaling (0 = 4× initial size).
     * @param bool  $autoScaling Enable smart auto-scaling (default: true).
     * @return self
     */
    public static function new(
        int $size = 10,
        int $maxSize = 0,
        bool $autoScaling = true,
    ): self {
        return new self(
            size: $size,
            maxSize: $maxSize,
            autoScaling: $autoScaling,
        );
    }

    // ═════════════════════════════════════════════════════════════════
    //  Public API
    // ═════════════════════════════════════════════════════════════════

    /**
     * Submit a task to the pool for execution and return a ticket ID.
     *
     * If an idle fiber is available, the task is dispatched immediately
     * and executes synchronously within the resume() call (~1µs overhead).
     * By the time submit() returns, the result is already stored and
     * available via getResult().
     *
     * If no idle fiber is available, the task is queued. When auto-scaling
     * is enabled, a new fiber may be spawned to handle it immediately.
     * Otherwise the task waits until a fiber becomes available.
     *
     * @param callable $callable The task to execute.
     * @return int A ticket ID that can be used to retrieve the result.
     */
    public function submit(callable $callable): int
    {
        if ($this->shutdown) {
            throw new RuntimeException("FiberPool has been shut down.");
        }

        $pool = RuntimeFiberPool::getInstance();
        $taskId = $pool->submitSync($callable);

        // Track this task as belonging to this FiberPool instance
        $this->managedTasks[$taskId] = true;

        // Sync mode: result is usually available immediately after submitSync.
        // Check and cache it locally.
        if ($pool->isTaskCompleted($taskId)) {
            $this->transferResult($taskId);
        }

        return $taskId;
    }

    /**
     * Submit a task and return $this for fluent chaining.
     *
     * Usage:
     *   $pool->submitChain(fn() => task1())
     *        ->submitChain(fn() => task2())
     *        ->drainAll();
     *
     * @param callable $callable The task to execute.
     * @return self
     */
    public function submitChain(callable $callable): self
    {
        $this->submit($callable);
        return $this;
    }

    /**
     * Get the result for a submitted task by its ticket ID.
     *
     * Because tasks execute synchronously inside the pooled Fiber during
     * dispatch, results are usually available immediately. If the ticket
     * corresponds to a pending (queued) task, this method will wait until
     * a fiber becomes available and the task completes.
     *
     * @param int $ticketId The ticket ID returned by submit().
     * @return mixed The task's return value.
     * @throws RuntimeException If the task threw an exception.
     */
    public function getResult(int $ticketId): mixed
    {
        // Check local cache first (already transferred)
        if (isset($this->localCompleted[$ticketId])) {
            return $this->extractLocalResult($ticketId);
        }

        $pool = RuntimeFiberPool::getInstance();

        // Wait for the result if not yet ready
        while (!$pool->isTaskCompleted($ticketId)) {
            // The pool needs ticking to dispatch pending tasks
            $pool->tick();

            if ($pool->isTaskCompleted($ticketId)) {
                break;
            }

            // Yield to let other fibers run — they might free up capacity
            if (Fiber::getCurrent() !== null) {
                Pause::force();
            } else {
                $this->driveScheduler();
                usleep(100);
            }
        }

        // Transfer from RuntimeFiberPool's internal storage to local cache
        $this->transferResult($ticketId);

        return $this->extractLocalResult($ticketId);
    }

    /**
     * Drive the pool: dispatch any pending tasks to idle fibers, and
     * run auto-scaling evaluation.
     *
     * In the simple (synchronous) case this is mostly a no-op because
     * tasks complete during dispatch. It is needed when tasks are queued
     * because all fibers were busy.
     */
    public function tick(): void
    {
        if (RuntimeFiberPool::isBooted()) {
            RuntimeFiberPool::getInstance()->tick();
        }
    }

    /**
     * Drive the pool until all submitted tasks (including pending) complete.
     *
     * @return self
     */
    public function drainAll(): self
    {
        $pool = RuntimeFiberPool::getInstance();

        // Wait until all tasks managed by this FiberPool instance are done
        while ($this->hasUnfinishedTasks()) {
            $pool->tick();

            if ($this->hasUnfinishedTasks()) {
                if (Fiber::getCurrent() !== null) {
                    Pause::force();
                } else {
                    $this->driveScheduler();
                    usleep(100);
                }
            }
        }

        return $this;
    }

    /**
     * Collect all completed results as an array indexed by ticket ID.
     *
     * Drains all pending tasks first, then returns every result that
     * has been stored. Results are removed from the pool after retrieval.
     *
     * @return array<int, mixed>
     */
    public function awaitAll(): array
    {
        $this->drainAll();

        $pool = RuntimeFiberPool::getInstance();
        $allResults = [];

        foreach ($this->managedTasks as $taskId => $_) {
            // Transfer from RuntimeFiberPool if not already local
            if (!isset($this->localCompleted[$taskId])) {
                $this->transferResult($taskId);
            }

            if (isset($this->localErrors[$taskId])) {
                $allResults[$taskId] = $this->localErrors[$taskId];
            } else {
                $allResults[$taskId] = $this->localResults[$taskId] ?? null;
            }

            unset($this->localResults[$taskId]);
            unset($this->localErrors[$taskId]);
            unset($this->localCompleted[$taskId]);
        }

        $this->managedTasks = [];

        return $allResults;
    }

    /**
     * Gracefully shut down this FiberPool instance.
     *
     * Drains all pending tasks, then marks this instance as shut down.
     * The underlying RuntimeFiberPool is NOT shut down — it continues
     * to serve other parts of the system (Launch, Async, other FiberPool
     * instances).
     *
     * After shutdown, no further tasks can be submitted via this instance.
     */
    public function close(): void
    {
        if ($this->shutdown) {
            return;
        }

        // Drain remaining work
        $this->drainAll();

        $this->shutdown = true;

        // Clean up local state
        $this->managedTasks = [];
        $this->localResults = [];
        $this->localErrors = [];
        $this->localCompleted = [];
    }

    // ═════════════════════════════════════════════════════════════════
    //  Introspection
    // ═════════════════════════════════════════════════════════════════

    /**
     * Returns the current total number of fibers in the global pool.
     *
     * Note: this reflects the GLOBAL pool size, not just fibers used by
     * this FiberPool instance. All FiberPool instances and scheduler
     * primitives share the same worker shells.
     */
    public function size(): int
    {
        return RuntimeFiberPool::isBooted()
            ? RuntimeFiberPool::getInstance()->size()
            : 0;
    }

    /**
     * Returns the number of currently idle fibers in the global pool.
     */
    public function idleCount(): int
    {
        return RuntimeFiberPool::isBooted()
            ? RuntimeFiberPool::getInstance()->idleCount()
            : 0;
    }

    /**
     * Returns the number of pending tasks submitted by this instance
     * that are waiting for a fiber.
     */
    public function pendingCount(): int
    {
        if (!RuntimeFiberPool::isBooted()) {
            return 0;
        }

        $pool = RuntimeFiberPool::getInstance();
        $pending = 0;
        foreach ($this->managedTasks as $taskId => $_) {
            if (
                !isset($this->localCompleted[$taskId]) &&
                !$pool->isTaskCompleted($taskId)
            ) {
                $pending++;
            }
        }
        return $pending;
    }

    /**
     * Returns the number of fibers actively executing a task.
     *
     * Note: because sync tasks execute within a single resume() call,
     * this will typically be 0 unless called from within a task or
     * when tasks are queued.
     */
    public function activeCount(): int
    {
        return RuntimeFiberPool::isBooted()
            ? RuntimeFiberPool::getInstance()->size() -
                    RuntimeFiberPool::getInstance()->idleCount()
            : 0;
    }

    /**
     * Returns the maximum pool size (auto-scaling cap).
     */
    public function getMaxSize(): int
    {
        return RuntimeFiberPool::isBooted()
            ? RuntimeFiberPool::getInstance()->getMaxSize()
            : $this->maxSize;
    }

    /**
     * Returns whether this FiberPool instance has been shut down.
     */
    public function isShutdown(): bool
    {
        return $this->shutdown;
    }

    // ═════════════════════════════════════════════════════════════════
    //  Auto-scaling configuration
    // ═════════════════════════════════════════════════════════════════

    /**
     * Set the maximum pool size for auto-scaling.
     *
     * Updates the global RuntimeFiberPool's max size.
     *
     * @param int $max Must be >= current size.
     * @return self
     */
    public function setMaxSize(int $max): self
    {
        $this->maxSize = max($max, $this->initialSize);
        if (RuntimeFiberPool::isBooted()) {
            $pool = RuntimeFiberPool::getInstance();
            RuntimeFiberPool::configure(
                initialSize: $this->initialSize,
                maxSize: $this->maxSize,
                autoScaling: $this->autoScaling,
            );
        }
        return $this;
    }

    /**
     * Enable or disable auto-scaling.
     *
     * @param bool $enabled
     * @return self
     */
    public function setAutoScaling(bool $enabled): self
    {
        $this->autoScaling = $enabled;
        if (RuntimeFiberPool::isBooted()) {
            RuntimeFiberPool::configure(
                initialSize: $this->initialSize,
                maxSize: $this->maxSize,
                autoScaling: $this->autoScaling,
            );
        }
        return $this;
    }

    /**
     * Set the scale-up cooldown period.
     *
     * Note: This is stored locally for API compatibility but the global
     * RuntimeFiberPool uses its own fixed cooldown constants. Future
     * versions may expose per-pool cooldown configuration.
     *
     * @param float $seconds
     * @return self
     */
    public function setScaleUpCooldown(float $seconds): self
    {
        $this->scaleUpCooldown = max(0.0, $seconds);
        return $this;
    }

    /**
     * Set the scale-down cooldown period.
     *
     * @param float $seconds
     * @return self
     */
    public function setScaleDownCooldown(float $seconds): self
    {
        $this->scaleDownCooldown = max(0.0, $seconds);
        return $this;
    }

    /**
     * Set how long a fiber must be idle before scale-down.
     *
     * @param float $seconds
     * @return self
     */
    public function setIdleTimeout(float $seconds): self
    {
        $this->idleTimeout = max(0.0, $seconds);
        return $this;
    }

    // ═════════════════════════════════════════════════════════════════
    //  Internal: Result transfer & lifecycle
    // ═════════════════════════════════════════════════════════════════

    /**
     * Transfer a completed task's result from RuntimeFiberPool to local cache.
     *
     * RuntimeFiberPool stores results internally keyed by task ID. We
     * retrieve and cache them locally so that:
     *   - The global pool can free its storage for that task
     *   - Multiple getResult() calls on the same ticket work correctly
     *   - Close/awaitAll can collect results without re-querying the pool
     */
    private function transferResult(int $taskId): void
    {
        if (isset($this->localCompleted[$taskId])) {
            return; // Already transferred
        }

        $pool = RuntimeFiberPool::getInstance();
        if (!$pool->isTaskCompleted($taskId)) {
            return; // Not ready yet
        }

        // Use getSyncResult which handles error extraction internally.
        // We wrap it in try/catch to separate results from errors.
        try {
            $result = $pool->getSyncResult($taskId);
            $this->localResults[$taskId] = $result;
            $this->localCompleted[$taskId] = true;
        } catch (RuntimeException $e) {
            // getSyncResult wraps errors in RuntimeException with the
            // original exception as the previous. Store the previous
            // if available, otherwise the wrapper.
            $this->localErrors[$taskId] = $e->getPrevious() ?? $e;
            $this->localCompleted[$taskId] = true;
        }
    }

    /**
     * Extract a result from local cache, cleaning up storage.
     *
     * @throws RuntimeException If the task threw an exception.
     */
    private function extractLocalResult(int $ticketId): mixed
    {
        if (isset($this->localErrors[$ticketId])) {
            $error = $this->localErrors[$ticketId];
            unset($this->localErrors[$ticketId]);
            unset($this->localCompleted[$ticketId]);
            unset($this->managedTasks[$ticketId]);
            throw new RuntimeException(
                "FiberPool task (ticket #{$ticketId}) failed: " .
                    $error->getMessage(),
                0,
                $error,
            );
        }

        $result = $this->localResults[$ticketId] ?? null;
        unset($this->localResults[$ticketId]);
        unset($this->localCompleted[$ticketId]);
        unset($this->managedTasks[$ticketId]);

        return $result;
    }

    /**
     * Check if this FiberPool instance has unfinished tasks.
     */
    private function hasUnfinishedTasks(): bool
    {
        if (empty($this->managedTasks)) {
            return false;
        }

        $pool = RuntimeFiberPool::getInstance();

        foreach ($this->managedTasks as $taskId => $_) {
            // If not locally completed and not completed in the pool, still running
            if (
                !isset($this->localCompleted[$taskId]) &&
                !$pool->isTaskCompleted($taskId)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Drive the VOsaka scheduler manually (for use outside Fiber context).
     *
     * When getResult() or drainAll() is called from outside a Fiber,
     * we need to manually tick the scheduler subsystems so that other
     * fibers (which might free up pool capacity) can make progress.
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

        if (RuntimeFiberPool::isBooted()) {
            RuntimeFiberPool::getInstance()->tick();
        }
    }
}
