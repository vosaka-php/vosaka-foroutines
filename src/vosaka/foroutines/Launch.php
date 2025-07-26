<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Fiber;
use Generator;
use venndev\vosaka\core\Result;

/**
 * Launches a new asynchronous task that runs concurrently with the main thread.
 * It manages a queue of child scopes, each containing a fiber that executes the task.
 */
final class Launch
{
    /**
     * @var array<int, ChildScope>
     */
    public static array $queue = [];

    private function __construct(private int $id) {}

    /**
     * Creates a new asynchronous task. But it run concurrently with the main thread.
     *
     * @param callable|Generator|Async|Result|Fiber $callable The function or generator to run asynchronously.
     * @param Dispatchers $dispatcher The dispatcher to use for the async task.
     * @return Launch
     */
    public static function new(
        callable|Generator|Async|Result|Fiber $callable,
        Dispatchers $dispatcher = Dispatchers::DEFAULT
    ): Launch {
        if ($dispatcher === Dispatchers::IO) {
            $callable = function () use ($callable) {
                $result = WorkerPool::addAsync($callable);
                return $result->wait();
            };
            return self::makeLaunch($callable);
        }
        return self::makeLaunch($callable);
    }

    private static function makeLaunch(callable|Generator|Async|Result|Fiber $callable): Launch
    {
        $fiber = FiberUtils::makeFiber($callable);
        $childScope = new ChildScope();
        $childScope->id = spl_object_id($fiber);
        $childScope->fiber = $fiber;
        self::$queue[$childScope->id] = $childScope;
        return new self($childScope->id);
    }

    /**
     * Cancels the task associated with this Launch instance.
     * If the task is still running, it will be removed from the queue.
     */
    public function cancel(): void
    {
        if (isset(self::$queue[$this->id])) {
            unset(self::$queue[$this->id]);
        }
    }

    /**
     * Runs the next task in the queue if available.
     * This method should be called periodically to ensure that tasks are executed.
     */
    public static function runOnce(): void
    {
        if (count(Launch::$queue) > 0) {
            $childScope = array_shift(Launch::$queue);
            $fiber = $childScope->fiber;

            if (!$fiber->isStarted()) {
                $fiber->start();
            }

            if (!$fiber->isTerminated()) {
                $fiber->resume();
            }

            if (FiberUtils::fiberStillRunning($fiber)) {
                Launch::$queue[$childScope->id] = $childScope;
            }
        }
    }
}
