<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Fiber;
use RuntimeException;

/**
 * Pause the current Foroutine execution and yield control back to the event loop.
 * This allows other Foroutines to run while the current foroutine is paused.
 *
 * @throws RuntimeException if called outside of a Foroutine scope.
 */
final class Pause
{
    /**
     * Pauses the current Foroutine execution.
     *
     * This method should be called within a Foroutine context to yield control
     * back to the event loop, allowing other Foroutines to run concurrently.
     *
     * Pause is intentionally minimal — it ONLY suspends the current Fiber.
     * Driving the scheduler (Launch::runOnce, WorkerPool::run) is the
     * responsibility of the top-level event loop (Thread::wait) or
     * Async::waitOutsideFiber. Mixing scheduler calls inside Pause caused
     * nested-runOnce re-entrancy and cross-resuming of fibers from the
     * shared Launch queue, leading to deadlocks in nested Async contexts.
     */
    public static function new(): void
    {
        if (Fiber::getCurrent() === null) {
            return;
        }

        Fiber::suspend();
    }
}
