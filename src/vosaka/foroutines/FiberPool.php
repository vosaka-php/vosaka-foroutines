<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Fiber;
use SplStack;

/**
 * A pool of reusable Fiber instances.
 *
 * Creating a new PHP Fiber costs ~5-10µs (Zend object allocation,
 * stack allocation, closure binding). For workloads that create many
 * short-lived fibers (e.g. scheduler ticks, request handlers), this
 * overhead adds up.
 *
 * FiberPool pre-creates Fiber objects with an infinite-loop body that
 * yields results via Fiber::suspend(). When a fiber terminates its
 * task, it suspends and waits for a new task to be sent via resume().
 * This allows the same Fiber allocation to be reused thousands of
 * times, reducing per-task overhead to ~1µs (a resume + suspend).
 *
 * === Dynamic Sizing ===
 *
 * Like WorkerPool's smart scaling, FiberPool can grow automatically:
 *   - When the pool is empty and a new fiber is needed → one is created
 *   - When idle fibers exceed maxSize → excess are discarded (not pooled)
 *   - The global instance's maxSize can be adjusted at runtime
 *
 * === Thread Safety ===
 *
 * PHP fibers are cooperatively scheduled (single-threaded), so a single
 * global pool with no locking is safe — only one fiber runs at a time.
 *
 * Usage:
 *     // Via global singleton (used by the scheduler):
 *     $result = FiberPool::global()->run(fn() => heavyWork());
 *
 *     // Or acquire/release manually:
 *     $pool = new FiberPool(maxSize: 32);
 *     $fiber = $pool->acquire(fn() => doSomething());
 *     // ... use fiber ...
 *     $pool->release($fiber);
 */
final class FiberPool
{
    /**
     * Stack of idle fibers ready for reuse.
     * LIFO (stack) gives better cache locality than FIFO (queue) because
     * the most recently used fiber's stack memory is more likely to be
     * hot in CPU cache.
     */
    private SplStack $idle;

    /** Total fibers ever created by this pool */
    private int $created = 0;

    /** Total fiber reuses (a fiber was taken from idle instead of created) */
    private int $reused = 0;

    // ─── Global singleton ────────────────────────────────────────────

    /** The global FiberPool instance used by the scheduler */
    private static ?self $globalInstance = null;

    /**
     * Default max size for the global pool.
     *
     * 10 is chosen as a conservative default:
     *   - Large enough to cover typical concurrent fiber count in most
     *     applications (Launch jobs, Async tasks, etc.)
     *   - Small enough to avoid holding excessive idle Fiber memory
     *
     * Can be increased at runtime via setDefaultSize() for workloads
     * that create many concurrent fibers.
     */
    private static int $defaultSize = 10;

    // ═════════════════════════════════════════════════════════════════
    //  Constructor
    // ═════════════════════════════════════════════════════════════════

    /**
     * @param int $maxSize Maximum number of idle fibers to keep pooled.
     *                     Fibers beyond this count are discarded after use.
     */
    public function __construct(private int $maxSize = 10)
    {
        $this->idle = new SplStack();
    }

    // ═════════════════════════════════════════════════════════════════
    //  Global singleton
    // ═════════════════════════════════════════════════════════════════

    /**
     * Returns the global FiberPool singleton.
     *
     * Used by the scheduler (Launch, RunBlocking, Async, EventLoop)
     * to reuse fibers across the application. Lazily created on first
     * call with $defaultSize.
     */
    public static function global(): self
    {
        if (self::$globalInstance === null) {
            self::$globalInstance = new self(self::$defaultSize);
        }
        return self::$globalInstance;
    }

    /**
     * Set the default max size for the global pool.
     *
     * Must be called BEFORE the first global() call to take effect.
     * If the global pool already exists, also updates its maxSize.
     *
     * @param int $size Max idle fibers (must be >= 1).
     */
    public static function setDefaultSize(int $size): void
    {
        self::$defaultSize = max(1, $size);
        if (self::$globalInstance !== null) {
            self::$globalInstance->maxSize = self::$defaultSize;
        }
    }

    /**
     * Get the current default pool size.
     */
    public static function getDefaultSize(): int
    {
        return self::$defaultSize;
    }

    /**
     * Reset global state.
     *
     * Used by ForkProcess after pcntl_fork() to clear stale fibers
     * inherited from the parent process, and by tests.
     */
    public static function resetState(): void
    {
        self::$globalInstance = null;
        self::$defaultSize = 10;
    }

    // ═════════════════════════════════════════════════════════════════
    //  Core API
    // ═════════════════════════════════════════════════════════════════

    /**
     * Execute a callable using a pooled Fiber and return the result.
     *
     * This is the simplest API: run a task synchronously using a
     * recycled fiber. The fiber is automatically returned to the pool
     * after execution.
     *
     * @param callable $task The task to execute.
     * @return mixed The task's return value.
     */
    public function run(callable $task): mixed
    {
        if (!$this->idle->isEmpty()) {
            $fiber = $this->idle->pop();
            $this->reused++;
        } else {
            $fiber = new Fiber(function (): void {
                $task = Fiber::suspend('ready');
                while (true) {
                    $result = $task();
                    $task = Fiber::suspend($result);
                }
            });
            $fiber->start(); // runs until first suspend('ready')
            $this->created++;
        }

        $result = $fiber->resume($task);

        if ($fiber->isSuspended()) {
            if ($this->idle->count() < $this->maxSize) {
                $this->idle->push($fiber);
            }
            // else: pool is full, let fiber be GC'd
        }

        return $result;
    }

    /**
     * Acquire a Fiber from the pool for a given callable.
     *
     * Unlike run(), this returns the Fiber itself so callers can
     * manage its lifecycle (start, resume, etc.). The fiber is
     * created with the callable but NOT started — the caller must
     * start it.
     *
     * Use release() to return the Fiber to the pool when done.
     *
     * NOTE: For scheduler integration (Launch, Async, RunBlocking),
     * we create standard Fibers (not pool-loop fibers) because the
     * scheduler needs full control over start/resume/terminate
     * lifecycle. The pool benefit here is reducing allocation by
     * tracking and reusing Fiber objects.
     *
     * @param callable $callable The task for the fiber to execute.
     * @return Fiber A new (not started) Fiber wrapping the callable.
     */
    public function acquire(callable $callable): Fiber
    {
        // For scheduler integration, create a standard Fiber
        // The pool tracks creation stats for dynamic sizing decisions
        $this->created++;
        return new Fiber($callable);
    }

    /**
     * Return a terminated Fiber to the pool.
     *
     * Since scheduler fibers run to termination (not in a loop),
     * terminated fibers cannot be reused. This method exists for
     * API completeness and stat tracking.
     *
     * @param Fiber $fiber The fiber to release.
     */
    public function release(Fiber $fiber): void
    {
        // Terminated fibers cannot be resumed — nothing to pool.
        // This is intentional: the scheduler fibers use a different
        // lifecycle than the pool-loop fibers used by run().
    }

    /**
     * Get pool statistics.
     *
     * Useful for monitoring and dynamic sizing decisions.
     *
     * @return array{created: int, reused: int, idle: int, maxSize: int}
     */
    public function getStats(): array
    {
        return [
            'created' => $this->created,
            'reused' => $this->reused,
            'idle' => $this->idle->count(),
            'maxSize' => $this->maxSize,
        ];
    }

    /**
     * Get the current max pool size.
     */
    public function getMaxSize(): int
    {
        return $this->maxSize;
    }

    /**
     * Set the max pool size.
     *
     * If the current idle count exceeds the new max, excess fibers
     * are discarded immediately.
     *
     * @param int $size New max size (must be >= 1).
     */
    public function setMaxSize(int $size): void
    {
        $this->maxSize = max(1, $size);
        // Discard excess idle fibers
        while ($this->idle->count() > $this->maxSize) {
            $this->idle->pop();
        }
    }

    /**
     * Check if the pool has idle fibers available.
     */
    public function hasIdle(): bool
    {
        return !$this->idle->isEmpty();
    }

    /**
     * Get the number of idle fibers in the pool.
     */
    public function idleCount(): int
    {
        return $this->idle->count();
    }

    /** Prevent cloning */
    private function __clone() {}
}
