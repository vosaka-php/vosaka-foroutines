<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Fiber;
use Generator;
use Throwable;
use SplQueue;

/**
 * Launches a new asynchronous task that runs concurrently with the main thread.
 * It manages a queue of child scopes, each containing a fiber that executes the task.
 */
final class Launch extends Job
{
    use Instance;

    /**
     * FIFO queue to manage execution order.
     * @var SplQueue<Job>
     */
    public static SplQueue $queue;

    /**
     * ID-based map for quick access to jobs.
     * @var array<int, Job>
     */
    public static array $map = [];

    public function __construct(public int $id = 0)
    {
        parent::__construct($id);

        // Initialize queue once
        if (!isset(self::$queue)) {
            self::$queue = new SplQueue();
        }
    }

    /**
     * Returns the number of tasks currently in the queue.
     *
     * @return int The number of tasks in the queue.
     */
    public function isEmpty(): bool
    {
        return self::$queue->isEmpty();
    }

    /**
     * Checks if there are any active tasks (in queue or being processed).
     *
     * @return bool True if there are active tasks, false otherwise.
     */
    public function hasActiveTasks(): bool
    {
        return !self::$queue->isEmpty() || !empty(self::$map);
    }

    /**
     * Creates a new asynchronous task. It runs concurrently with the main thread.
     *
     * @param callable|Generator|Async|Fiber $callable The function or generator to run asynchronously.
     * @param Dispatchers $dispatcher The dispatcher to use for the async task.
     * @return Launch
     */
    public static function new(
        callable|Generator|Async|Fiber $callable,
        Dispatchers $dispatcher = Dispatchers::DEFAULT,
    ): Launch {
        if ($dispatcher === Dispatchers::IO) {
            $callable = function () use ($callable) {
                $result = WorkerPool::addAsync($callable)->wait();
                return $result;
            };
            return self::makeLaunch($callable);
        }

        if ($dispatcher === Dispatchers::MAIN) {
            $callable = function () use ($callable) {
                $result = Async::new($callable, Dispatchers::MAIN)->wait();
                return $result;
            };
            return self::makeLaunch($callable);
        }

        return self::makeLaunch($callable);
    }

    private static function makeLaunch(
        callable|Generator|Async|Fiber $callable,
    ): Launch {
        $fiber = FiberUtils::makeFiber($callable);
        $id = spl_object_id($fiber);
        $job = new self($id);
        $job->fiber = $fiber;

        // Store in map and enqueue
        self::$map[$id] = $job;
        self::$queue->enqueue($job);

        return $job;
    }

    /**
     * Cancels the task associated with this Launch instance.
     * If the task is still running, it will be removed from the map.
     */
    public function cancel(): void
    {
        unset(self::$map[$this->id]);
        parent::cancel();
    }

    /**
     * Runs the next task in the queue if available.
     * This method should be called periodically to ensure that tasks are executed.
     */
    public function runOnce(): void
    {
        if (!self::$queue->isEmpty()) {
            /** @var Job $job */
            $job = self::$queue->dequeue();
            $fiber = $job->fiber;

            if (!isset(self::$map[$job->id])) {
                return;
            }

            if ($job->isTimedOut()) {
                $job->cancel();
                unset(self::$map[$job->id]);
                return;
            }

            if ($job->isFinal()) {
                unset(self::$map[$job->id]);
                return;
            }

            try {
                if (!$fiber->isStarted()) {
                    $job->start();
                }

                if (!$fiber->isTerminated()) {
                    $fiber->resume();
                }

                // Mark completed if fiber has terminated and job is still running
                if ($fiber->isTerminated() && !$job->isFinal()) {
                    $job->complete();
                }
            } catch (Throwable $e) {
                if (!$job->isFinal()) {
                    $job->fail();
                }
                unset(self::$map[$job->id]);
                throw $e;
            }

            // Requeue if still running
            if (FiberUtils::fiberStillRunning($fiber)) {
                self::$queue->enqueue($job);
            } else {
                unset(self::$map[$job->id]);
            }
        }
    }
}
