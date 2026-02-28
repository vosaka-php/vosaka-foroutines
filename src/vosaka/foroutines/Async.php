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
    public function __construct(public Fiber $fiber) {}

    /**
     * Creates a new asynchronous task.
     *
     * @param callable|Generator|Result $callable The function or generator to run asynchronously.
     * @param Dispatchers $dispatcher The dispatcher to use for the async task.
     * @return Async
     */
    public static function new(
        callable|Generator|Result $callable,
        Dispatchers $dispatcher = Dispatchers::DEFAULT,
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
     * When called from within a Fiber context (e.g. inside a Launch job),
     * this method yields control back to the scheduler between resume attempts
     * so that other fibers/tasks can make progress concurrently.
     *
     * When called from a non-Fiber context (e.g. top-level code or inside a
     * child process), it runs the inner fiber in a tight blocking loop with
     * cooperative scheduling calls (WorkerPool::run + Launch::runOnce) to
     * avoid deadlocking — Pause::new() would be a no-op in non-Fiber context
     * and Fiber::suspend() cannot be called outside a Fiber.
     *
     * @return mixed The result of the asynchronous task.
     */
    public function wait(): mixed
    {
        if (!$this->fiber->isStarted()) {
            $this->fiber->start();
        }

        if (Fiber::getCurrent() !== null) {
            // We are inside a Fiber — use Pause to cooperatively yield
            // so the outer scheduler (Thread::wait / runOnce) can drive
            // other fibers forward between our resume attempts.
            return $this->waitInsideFiber();
        }

        // We are NOT inside a Fiber (top-level or child-process context).
        // Run a blocking loop that manually drives the scheduler so that
        // WorkerPool workers and Launch jobs can make progress.
        return $this->waitOutsideFiber();
    }

    /**
     * Blocking wait used when we are already inside a Fiber.
     *
     * Each iteration: resume the inner fiber (if it suspended), then
     * call Pause::new() which will:
     *   1. Run one Launch::runOnce() tick (drives other queued fibers)
     *   2. Run WorkerPool::run() (starts pending workers)
     *   3. Fiber::suspend() — yields control back to whoever is driving us
     *
     * This ensures the outer scheduler can round-robin between all active
     * fibers including this one.
     */
    private function waitInsideFiber(): mixed
    {
        while (!$this->fiber->isTerminated()) {
            if ($this->fiber->isSuspended()) {
                $this->fiber->resume();
            }

            if (!$this->fiber->isTerminated()) {
                Pause::new();
            }
        }

        return $this->fiber->getReturn();
    }

    /**
     * Blocking wait used when we are NOT inside a Fiber.
     *
     * Since Pause::new() is effectively a no-op outside a Fiber (it cannot
     * call Fiber::suspend()), we manually drive the scheduler by calling
     * WorkerPool::run() and Launch::runOnce() in a tight loop.
     *
     * A small usleep(1000) (1 ms) is added per iteration to:
     *   - Prevent 100% CPU spin
     *   - Give child processes real wall-clock time to advance
     *   - Allow the OS scheduler to run child processes
     */
    private function waitOutsideFiber(): mixed
    {
        while (!$this->fiber->isTerminated()) {
            // Drive the cooperative scheduler manually since we cannot
            // Fiber::suspend() from here.
            WorkerPool::run();
            Launch::getInstance()->runOnce();

            if ($this->fiber->isSuspended()) {
                $this->fiber->resume();
            }

            if (!$this->fiber->isTerminated()) {
                // Small sleep to avoid CPU spin and give child processes time
                usleep(1000);
            }
        }

        return $this->fiber->getReturn();
    }
}
