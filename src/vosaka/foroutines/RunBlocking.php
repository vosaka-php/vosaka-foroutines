<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Fiber;
use Generator;

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
    public static function new(callable|Generator|Async|Fiber ...$fiber): array
    {
        $results = [];
        while (count($fiber) > 0) {
            $item = array_shift($fiber);
            if (!$item instanceof Fiber) {
                $item = FiberUtils::makeFiber($item);
            }

            if (!$item->isStarted()) {
                $item->start();
            }

            if (!FiberUtils::fiberStillRunning($item)) {
                $results[] = $item->getReturn();
            } else {
                $item->resume();
                $fiber[] = $item; // Re-add the fiber to the end of the queue
            }
        }

        return $results;
    }
}
