<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Fiber;
use Generator;
use Throwable;
use venndev\vosaka\core\Result;

/**
 * Launches a new asynchronous task that runs concurrently with the main thread.
 * It manages a queue of child scopes, each containing a fiber that executes the task.
 */
final class Launch extends Job
{
    use Instance;

    /**
     * @var array<int, Job>
     */
    public static array $queue = [];

    private function __construct(private int $id)
    {
        parent::__construct($id);
    }

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
        $id = spl_object_id($fiber);
        $job = new self($id);
        $job->fiber = $fiber;
        self::$queue[$job->id] = $job;
        return $job;
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
        parent::cancel();
    }

    /**
     * Runs the next task in the queue if available.
     * This method should be called periodically to ensure that tasks are executed.
     */
    public function runOnce(): void
    {
        if (count(Launch::$queue) > 0) {
            $job = array_shift(Launch::$queue);
            $fiber = $job->fiber;

            if ($job->isFinal()) {
                unset(Launch::$queue[$job->id]);
                return;
            }

            try {
                if (!$fiber->isStarted()) {
                    $job->start();
                }

                if (!$fiber->isTerminated()) {
                    $fiber->resume();
                } else {
                    $job->complete();
                }
            } catch (Throwable $e) {
                $job->fail();
                throw $e;
            }

            if (FiberUtils::fiberStillRunning($fiber)) {
                Launch::$queue[$job->id] = $job;
            }
        }
    }
}
