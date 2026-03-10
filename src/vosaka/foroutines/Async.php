<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Fiber;
use InvalidArgumentException;

/**
 * Class Async
 *
 * Represents an asynchronous task that can be executed in a separate Foroutine.
 * This class allows you to run a function asynchronously and wait for its result.
 */
final class Async implements Deferred
{
    public function __construct(public ?Fiber $fiber)
    {
    }

    /**
     * Creates a new asynchronous task.
     *
     * @param callable $callable The function to run asynchronously.
     * @param Dispatchers $dispatcher The dispatcher to use for the async task.
     * @return Async
     */
    public static function new(
        callable $callable,
        Dispatchers $dispatcher = Dispatchers::DEFAULT ,
    ): Async {
        if ($dispatcher === Dispatchers::IO) {
            if (WorkerPoolState::$isWorker) {
                $dispatcher = Dispatchers::DEFAULT;
            } else {
                return WorkerPool::addAsync($callable);
            }
        }

        if ($dispatcher === Dispatchers::MAIN) {
            $fiber = FiberUtils::makeFiber($callable);
            EventLoop::add($fiber);
            return new self($fiber);
        }

        // Fast-path: use FiberPool for Closure callables
        if ($callable instanceof \Closure) {
            $fiber = FiberPool::global()->acquire($callable);
        } else {
            $fiber = FiberUtils::makeFiber($callable);
        }
        return new self($fiber);
    }

    /**
     * Awaits multiple Async instances concurrently and returns all results.
     *
     * This method drives all provided Async tasks forward simultaneously
     * rather than awaiting them one-by-one sequentially. This is important
     * because sequential awaiting (e.g. $a->await(); $b->await();) means
     * the second task only starts making progress after the first completes,
     * whereas awaitAll() interleaves their execution on every tick.
     *
     * Returns an array of results in the same order as the input Async
     * instances. If called with named arguments or an explicit array,
     * the keys are preserved.
     *
     * Usage:
     *   [$a, $b, $c] = Async::awaitAll($asyncA, $asyncB, $asyncC);
     *
     *   // Or with an array:
     *   $results = Async::awaitAll(...$arrayOfAsyncs);
     *
     * @param Async ...$asyncs The Async instances to await concurrently.
     * @return array<int|string, mixed> Results in the same order/keys as input.
     * @throws InvalidArgumentException If no Async instances are provided.
     */
    public static function awaitAll(Async ...$asyncs): array
    {
        if (empty($asyncs)) {
            throw new InvalidArgumentException(
                "Async::awaitAll() requires at least one Async instance.",
            );
        }

        // Start all fibers that haven't been started yet
        foreach ($asyncs as $async) {
            if ($async->fiber !== null && !$async->fiber->isStarted()) {
                $async->fiber->start();
            }
        }

        if (Fiber::getCurrent() !== null) {
            return self::awaitAllInsideFiber($asyncs);
        }

        return self::awaitAllOutsideFiber($asyncs);
    }

    /**
     * Concurrent await of multiple Asyncs when called from inside a Fiber.
     *
     * On each tick, resumes every non-terminated fiber, then yields
     * control back to the scheduler via Pause::force() so that other
     * fibers (Launch jobs, etc.) can also make progress.
     *
     * @param array<int|string, Async> $asyncs
     * @return array<int|string, mixed>
     */
    private static function awaitAllInsideFiber(array $asyncs): array
    {
        $results = [];
        $pending = $asyncs; // copy — we'll remove completed entries

        while (!empty($pending)) {
            foreach ($pending as $key => $async) {
                $fiber = $async->fiber;

                if ($fiber === null || $fiber->isTerminated()) {
                    // Collect the result
                    $results[$key] =
                        $fiber !== null ? $fiber->getReturn() : null;
                    // Release fiber reference early
                    $async->fiber = null;
                    unset($pending[$key]);
                    continue;
                }

                if ($fiber->isSuspended()) {
                    $fiber->resume();
                }
            }

            if (!empty($pending)) {
                Pause::force();
            }
        }

        // Return results in the original key order
        ksort($results);
        return $results;
    }

    /**
     * Concurrent await of multiple Asyncs when called from outside a Fiber.
     *
     * Manually drives the scheduler (AsyncIO, WorkerPool, Launch) on
     * each tick, then resumes all non-terminated fibers. A small usleep
     * is added on idle ticks to avoid 100% CPU spin.
     *
     * @param array<int|string, Async> $asyncs
     * @return array<int|string, mixed>
     */
    private static function awaitAllOutsideFiber(array $asyncs): array
    {
        $results = [];
        $pending = $asyncs;

        while (!empty($pending)) {
            $didWork = false;

            // Drive subsystems
            if (AsyncIO::hasPending()) {
                if (AsyncIO::pollOnce()) {
                    $didWork = true;
                }
            }

            if (!WorkerPool::isEmpty()) {
                WorkerPool::run();
                $didWork = true;
            }

            if (Launch::getInstance()->hasActiveTasks()) {
                Launch::getInstance()->runOnce();
                $didWork = true;
            }

            // Resume all pending fibers
            foreach ($pending as $key => $async) {
                $fiber = $async->fiber;

                if ($fiber === null || $fiber->isTerminated()) {
                    $results[$key] =
                        $fiber !== null ? $fiber->getReturn() : null;
                    $async->fiber = null;
                    unset($pending[$key]);
                    $didWork = true;
                    continue;
                }

                if ($fiber->isSuspended()) {
                    $fiber->resume();
                    $didWork = true;
                }
            }

            if (!empty($pending) && !$didWork) {
                usleep(500);
            }
        }

        ksort($results);
        return $results;
    }

    /**
     * Awaits the asynchronous task to complete and returns its result.
     *
     * When called from within a Fiber context (e.g. inside a Launch job),
     * this method yields control back to the scheduler between resume attempts
     * so that other fibers/tasks can make progress concurrently.
     *
     * When called from a non-Fiber context (e.g. top-level code or inside a
     * child process), it runs the inner fiber in a tight blocking loop with
     * cooperative scheduling calls (AsyncIO::pollOnce + WorkerPool::run +
     * Launch::runOnce) to avoid deadlocking — Pause::new() would be a no-op
     * in non-Fiber context and Fiber::suspend() cannot be called outside a
     * Fiber.
     *
     * @return mixed The result of the asynchronous task.
     */
    public function join(): mixed
    {
        if (!$this->fiber->isStarted()) {
            $this->fiber->start();
        }

        if (Fiber::getCurrent() !== null) {
            // We are inside a Fiber — use Pause to cooperatively yield
            // so the outer scheduler (Thread::await / runOnce) can drive
            // other fibers forward between our resume attempts.
            return $this->waitInsideFiber();
        }

        // We are NOT inside a Fiber (top-level or child-process context).
        // Run a blocking loop that manually drives the scheduler so that
        // WorkerPool workers, Launch jobs, and AsyncIO watchers can make
        // progress.
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
                Pause::force();
            }
        }

        $result = $this->fiber->getReturn();
        // Release fiber reference early so its memory can be reclaimed
        // before the Async object itself is garbage-collected.
        $this->fiber = null;
        return $result;
    }

    /**
     * Blocking wait used when we are NOT inside a Fiber.
     *
     * Since Pause::new() is effectively a no-op outside a Fiber (it cannot
     * call Fiber::suspend()), we manually drive the scheduler by calling
     * AsyncIO::pollOnce(), WorkerPool::run(), and Launch::runOnce() in a
     * loop.
     *
     * A small usleep(500) is added on idle iterations (where none of the
     * three subsystems had actionable work) to:
     *   - Prevent 100% CPU spin
     *   - Give child processes real wall-clock time to advance
     *   - Allow the OS scheduler to run child processes
     *   - Let stream_select() in AsyncIO detect readiness
     */
    private function waitOutsideFiber(): mixed
    {
        while (!$this->fiber->isTerminated()) {
            $didWork = false;

            // Drive non-blocking stream I/O — poll all registered
            // read/write watchers via stream_select() and resume
            // fibers whose streams became ready.
            if (AsyncIO::hasPending()) {
                if (AsyncIO::pollOnce()) {
                    $didWork = true;
                }
            }

            // Drive the cooperative scheduler manually since we cannot
            // Fiber::suspend() from here.
            if (!WorkerPool::isEmpty()) {
                WorkerPool::run();
                $didWork = true;
            }

            if (Launch::getInstance()->hasActiveTasks()) {
                Launch::getInstance()->runOnce();
                $didWork = true;
            }

            if ($this->fiber->isSuspended()) {
                $this->fiber->resume();
                $didWork = true;
            }

            if (!$this->fiber->isTerminated()) {
                // Only sleep when no subsystem had actionable work this
                // tick. When work IS happening, fibers yield naturally,
                // so we don't need extra delay. When idle (e.g. waiting
                // for a child process to finish or a stream to become
                // ready), the sleep prevents a hot spin loop.
                if (!$didWork) {
                    usleep(500);
                }
            }
        }

        $result = $this->fiber->getReturn();
        // Release fiber reference early so its memory can be reclaimed
        // before the Async object itself is garbage-collected.
        $this->fiber = null;
        return $result;
    }

    /**
     * Alias for join() to implement the Deferred interface.
     * 
     * @return mixed The result of the asynchronous task.
     */
    public function await(): mixed
    {
        return $this->join();
    }
}
