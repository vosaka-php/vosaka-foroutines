<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Fiber;

/**
 * Pause the current Foroutine execution and yield control back to the event loop.
 * This allows other Foroutines to run while the current foroutine is paused.
 *
 * === Batch Yielding Optimization ===
 *
 * Instead of calling Fiber::suspend() on every Pause::new() invocation
 * (which costs ~12µs per suspend/resume round-trip on typical hardware),
 * we maintain a global "yield budget" counter. When the budget has
 * remaining credits, Pause::new() returns immediately without suspending,
 * allowing the fiber to continue executing. Only when the budget is
 * exhausted does a real Fiber::suspend() occur.
 *
 * Because PHP's cooperative scheduler is single-threaded and only one
 * fiber runs at a time (the scheduler resumes one fiber, that fiber
 * runs until it suspends, then the scheduler picks the next one), a
 * single global counter is sufficient — there is no concurrent access.
 * When a fiber suspends (budget exhausted) and another fiber is resumed,
 * the budget is already reset to $batchSize, giving the newly resumed
 * fiber a fresh batch. This is both simpler and faster than per-fiber
 * tracking via SplObjectStorage or arrays.
 *
 * This amortizes the context-switch cost across many iterations:
 *   - Before: 1000 Pause::new() calls = 1000 × suspend/resume = ~12ms
 *   - After:  1000 Pause::new() calls = 1000/64 = ~16 suspends  = ~0.19ms
 *
 * The batch size is configurable at runtime via setBatchSize().
 *
 * For call sites that MUST suspend immediately (e.g. Delay waiting for
 * a timer, I/O watchers, channel rendezvous), use Pause::force() which
 * bypasses the budget and always calls Fiber::suspend().
 */
final class Pause
{
    /**
     * Default number of Pause::new() calls to skip between real suspends.
     *
     * 64 is chosen as a balance:
     *   - High enough to amortize suspend/resume overhead (~12µs each)
     *     for tight CPU loops that yield frequently.
     *   - Low enough to maintain reasonable scheduler fairness — other
     *     fibers won't starve for more than ~64 iterations of any
     *     single fiber's work.
     *
     * For I/O-heavy workloads where responsiveness matters more than
     * throughput, use a smaller value (e.g. 8-16).
     * For CPU-heavy workloads, use a larger value (e.g. 128-512).
     */
    private const DEFAULT_BATCH_SIZE = 64;

    /**
     * Number of Pause::new() calls allowed before a real suspend.
     * Configurable at runtime via setBatchSize().
     */
    private static int $batchSize = self::DEFAULT_BATCH_SIZE;

    /**
     * Global remaining budget counter.
     *
     * Because PHP fibers are cooperatively scheduled (only one fiber
     * runs at a time), a single global counter works correctly:
     *   1. Fiber A runs, decrements $remaining on each Pause::new()
     *   2. When $remaining hits 0, Fiber A suspends, $remaining resets
     *   3. Scheduler resumes Fiber B — it gets a fresh $remaining = $batchSize
     *   4. Fiber B runs until its budget exhausts, suspends, etc.
     *
     * No per-fiber tracking, no SplObjectStorage, no hash lookups —
     * just a single int decrement on the hot path.
     */
    private static int $remaining = self::DEFAULT_BATCH_SIZE;

    /**
     * Pauses the current Foroutine execution with batch yielding.
     *
     * If the current fiber still has yield budget remaining, this method
     * returns immediately WITHOUT calling Fiber::suspend(), allowing the
     * fiber to continue executing. This dramatically reduces context-switch
     * overhead for CPU-bound loops that call Pause::new() frequently.
     *
     * When the budget is exhausted, a real Fiber::suspend() is performed,
     * the budget is reset to $batchSize, and control returns to the
     * scheduler so other fibers can run.
     *
     * If called outside of a Fiber context (e.g. top-level code), this
     * is a no-op — consistent with the original behavior.
     *
     * Usage patterns:
     *   - CPU-bound loop with periodic yield: Pause::new() — batched
     *   - Waiting for I/O / timer / channel:  Pause::force() — immediate
     */
    public static function new(): void
    {
        if (Fiber::getCurrent() === null) {
            return;
        }

        // Fast path: budget available — skip suspend, just decrement.
        // This is a single int comparison + decrement — the absolute
        // minimum overhead possible (~0.01µs vs ~12µs for Fiber::suspend).
        if (--self::$remaining > 0) {
            return;
        }

        // Budget exhausted — reset and perform real suspend
        self::$remaining = self::$batchSize;

        Fiber::suspend();
    }

    /**
     * Forces an immediate Fiber::suspend(), bypassing the batch budget.
     *
     * Use this when the fiber MUST yield control to the scheduler
     * immediately, regardless of remaining budget. Critical for:
     *
     *   - Delay::new()          — timer-based waiting
     *   - Async::waitInsideFiber() — awaiting another fiber's completion
     *   - Channel send/receive  — rendezvous synchronization
     *   - Flow backpressure     — buffer-full suspension
     *   - I/O polling           — waiting for stream readiness
     *   - ForkProcess::waitForChild() — polling child process status
     *   - WithTimeout           — timeout checking loop
     *   - Job::join()           — waiting for job completion
     *   - WorkerPool::addAsync  — polling for worker result
     *
     * Also resets the budget so that subsequent Pause::new() calls start
     * with a fresh batch, preventing stale budget from causing unexpected
     * behavior after a forced yield.
     */
    public static function force(): void
    {
        if (Fiber::getCurrent() === null) {
            return;
        }

        // Reset budget so next Pause::new() starts fresh after resume
        self::$remaining = self::$batchSize;

        Fiber::suspend();
    }

    /**
     * Set the batch size (number of Pause::new() calls to skip).
     *
     * A larger value means fewer real suspends (better throughput for
     * CPU-bound work) but worse scheduler fairness (other fibers wait
     * longer between turns).
     *
     * Recommended values:
     *   - I/O-heavy:  8-16   (responsive scheduling)
     *   - Mixed:      32-64  (balanced — default)
     *   - CPU-heavy:  128-512 (maximum throughput)
     *
     * Takes effect on the next budget reset (i.e. when the current
     * budget is exhausted or after the next force() call).
     *
     * @param int $size Number of calls to skip. Must be >= 1.
     *                  A value of 1 means every Pause::new() suspends
     *                  (equivalent to the original behavior).
     */
    public static function setBatchSize(int $size): void
    {
        self::$batchSize = max(1, $size);
        // Also update remaining if it exceeds the new batch size,
        // so the change takes effect sooner rather than waiting for
        // the current oversized budget to exhaust.
        if (self::$remaining > self::$batchSize) {
            self::$remaining = self::$batchSize;
        }
    }

    /**
     * Returns the current batch size.
     *
     * @return int Current batch size.
     */
    public static function getBatchSize(): int
    {
        return self::$batchSize;
    }

    /**
     * Resets all state to defaults.
     *
     * Used by ForkProcess after pcntl_fork() to clear stale budget state
     * inherited from the parent process, and by tests to ensure clean state.
     */
    public static function resetState(): void
    {
        self::$batchSize = self::DEFAULT_BATCH_SIZE;
        self::$remaining = self::DEFAULT_BATCH_SIZE;
    }
}
