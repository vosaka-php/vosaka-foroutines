<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Fiber;
use Generator;
use venndev\vosaka\core\Result;

/**
 * Class Async
 *
 * Represents an asynchronous task that can be executed in a separate Foroutine.
 * This class allows you to run a function or generator asynchronously and wait for its result.
 */
final class Async
{
    public function __construct(
        public Fiber $fiber
    ) {}

    /**
     * Creates a new asynchronous task.
     *
     * @param callable|Generator|Result $callable The function or generator to run asynchronously.
     * @param Dispatchers $dispatcher The dispatcher to use for the async task.
     * @return Async
     */
    public static function new(
        callable|Generator|Result $callable,
        Dispatchers $dispatcher = Dispatchers::DEFAULT
    ): Async {
        if ($dispatcher === Dispatchers::IO) {
            return WorkerPool::addAsync($callable);
        }

        if ($dispatcher === Dispatchers::MAIN) {
            $fiber = FiberUtils::makeFiber($callable);
            EventLoop::add($fiber);
            return new self($fiber);
        }

        $fiber = FiberUtils::makeFiber($callable);
        return new self($fiber);
    }

    /**
     * Waits for the asynchronous task to complete and returns its result.
     *
     * @return mixed The result of the asynchronous task.
     */
    public function wait(): mixed
    {
        if (!$this->fiber->isStarted()) {
            $this->fiber->start();
        }

        while ($this->fiber->isStarted() && !$this->fiber->isTerminated()) {
            $this->fiber->resume();
            Pause::new();
        }

        return $this->fiber->getReturn();
    }
}
