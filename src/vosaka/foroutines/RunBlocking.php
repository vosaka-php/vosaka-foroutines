<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Fiber;
use Generator;
use venndev\vosaka\core\Result;

/**
 * RunBlocking is a utility class that allows you to run multiple fibers synchronously
 * until all of them complete. It is useful for testing or when you need to block the
 * current thread until all asynchronous tasks are finished.
 */
final class RunBlocking
{
    /**
     * Runs multiple fibers synchronously and returns their results.
     *
     * @param callable|Generator|Async|Fiber ...$fiber The fibers to run.
     * @return array The results of the completed fibers.
     */
    public static function new(
        callable|Generator|Async|Result|Fiber $callable,
        Dispatchers $dispatchers = Dispatchers::DEFAULT
    ): void {
        if ($dispatchers === Dispatchers::IO) {
            WorkerPool::addAsync($callable);
            return;
        }

        if (!$callable instanceof Fiber) {
            $callable = FiberUtils::makeFiber($callable);
        }

        if ($dispatchers === Dispatchers::MAIN) {
            EventLoop::add($callable);
            return;
        }

        if (!$callable->isStarted()) {
            $callable->start();
        }

        while (FiberUtils::fiberStillRunning($callable)) {
            $callable->resume();
        }
    }
}
