<?php

declare(strict_types=1);

namespace vosaka\foroutines;

final class Thread
{
    /**
     * Waits for the thread to complete execution.
     */
    public static function wait(): void
    {
        while (!WorkerPool::isEmpty() || !Launch::getInstance()->isEmpty()) {
            WorkerPool::run();
            Launch::getInstance()->runOnce();
        }
    }
}
