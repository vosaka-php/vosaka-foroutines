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
     * @throws RuntimeException if called outside of a Foroutine scope.
     */
    public static function new(): void
    {
        if (Fiber::getCurrent() === null) {
            // Instead of error_log because if called in thread context, it will spam in log
            /* echo '[' . date('YmdH') . '] [Warning] Pause can only be called within a Foroutine context.' . PHP_EOL; */
            return;
        }

        Launch::getInstance()->runOnce();
        WorkerPool::run();
        Fiber::suspend();
    }
}
