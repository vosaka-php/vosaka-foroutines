<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Fiber;
use Generator;
use InvalidArgumentException;

/**
 * Class Async
 *
 * Represents an asynchronous task that can be executed in a separate Foroutine.
 * This class allows you to run a function or generator asynchronously and wait for its result.
 *
 * RuntimeFiberPool integration:
 * For DEFAULT dispatcher tasks, Async now acquires cooperative fibers from
 * the global RuntimeFiberPool instead of allocating raw Fiber instances.
 * Pooled worker shell fibers do NOT terminate when the task completes —
 * they suspend with a completion sentinel. The await() methods detect this
 * via RuntimeFiberPool::isCooperativeTaskComplete() and recycle the fiber
 * back to the pool, retrieving the stored result.
 */
final class Async
{
    /**
     * Whether this Async's fiber is managed by RuntimeFiberPool.
     * When true, completion is detected via the pool's sentinel
     * mechanism rather than Fiber::isTerminated().
     */
    public bool $poolManaged = false;

    /**
     * Stored result from a pool-managed fiber's completed task.
     * Set when the pool recycles the fiber and hands us the result.
     */
    private mixed $poolResult = null;

    /**
     * Whether a pool result has been collected.
     */
    private bool $poolResultCollected = false;

    public function __construct(public ?Fiber $fiber) {}

    /**
     * Creates a new asynchronous task.
     *
     * For DEFAULT dispatcher, acquires a cooperative fiber from RuntimeFiberPool
     * instead of allocating a raw Fiber. This reuses pre-started worker shell
     * fibers (~1µs dispatch vs ~5-15µs allocation) and reduces GC pressure.
     *
     * @param callable|Generator $callable The function or generator to run asynchronously.
     * @param Dispatchers $dispatcher The dispatcher to use for the async task.
     * @return Async
     */
    public static function new(
        callable|Generator $callable,
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

        // DEFAULT dispatcher — use RuntimeFiberPool if available
        if (Launch::isPooledMode()) {
            return self::newPooled($callable);
        }

        $fiber = FiberUtils::makeFiber($callable);
        return new self($fiber);
    }

    /**
     * Create a pool-backed Async for the DEFAULT dispatcher.
     *
     * Wraps Generator callables into plain callables, acquires a
     * cooperative fiber from RuntimeFiberPool, and marks the Async
     * as pool-managed so await() uses the correct completion detection.
     */
    private static function newPooled(callable|Generator $callable): Async
    {
        $pool = RuntimeFiberPool::getInstance();

        // Wrap Generator instances into a plain callable
        if ($callable instanceof Generator) {
            $gen = $callable;
            $callable = static function () use ($gen): mixed {
                while ($gen->valid()) {
                    $gen->next();
                    Pause::new();
                }
                return $gen->getReturn();
            };
        }

        $fiber = $pool->acquireCooperativeFiber($callable);

        $async = new self($fiber);
        $async->poolManaged = $pool->isManagedFiber($fiber);
        return $async;
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
     * RuntimeFiberPool integration: for pool-managed asyncs, checks
     * isCooperativeTaskComplete() instead of isTerminated() and recycles
     * the fiber back to the pool upon completion.
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

                // Already collected pool result
                if ($async->poolResultCollected) {
                    $results[$key] = $async->poolResult;
                    $async->fiber = null;
                    unset($pending[$key]);
                    continue;
                }

                if ($fiber === null || $fiber->isTerminated()) {
                    // Collect the result
                    if ($async->poolManaged && $fiber !== null) {
                        RuntimeFiberPool::getInstance()->notifyFiberTerminated(
                            $fiber,
                        );
                    }
                    $results[$key] =
                        $fiber !== null ? $fiber->getReturn() : null;
                    // Release fiber reference early
                    $async->fiber = null;
                    unset($pending[$key]);
                    continue;
                }

                // Pool-managed: check for cooperative completion sentinel
                if ($async->poolManaged && $fiber->isSuspended()) {
                    $pool = RuntimeFiberPool::getInstance();
                    if ($pool->isCooperativeTaskComplete($fiber)) {
                        $result = $pool->recycleCooperativeFiber($fiber);
                        $async->fiber = null;
                        if ($result instanceof \Throwable) {
                            throw $result;
                        }
                        $results[$key] = $result;
                        unset($pending[$key]);
                        continue;
                    }
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
     * RuntimeFiberPool integration: also ticks the pool and checks for
     * cooperative task completion on pool-managed asyncs.
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

            // Tick the RuntimeFiberPool to dispatch pending tasks and
            // handle auto-scaling.
            if (RuntimeFiberPool::isBooted()) {
                RuntimeFiberPool::getInstance()->tick();
            }

            // Resume all pending fibers
            foreach ($pending as $key => $async) {
                $fiber = $async->fiber;

                // Already collected pool result
                if ($async->poolResultCollected) {
                    $results[$key] = $async->poolResult;
                    $async->fiber = null;
                    unset($pending[$key]);
                    $didWork = true;
                    continue;
                }

                if ($fiber === null || $fiber->isTerminated()) {
                    if ($async->poolManaged && $fiber !== null) {
                        RuntimeFiberPool::getInstance()->notifyFiberTerminated(
                            $fiber,
                        );
                    }
                    $results[$key] =
                        $fiber !== null ? $fiber->getReturn() : null;
                    $async->fiber = null;
                    unset($pending[$key]);
                    $didWork = true;
                    continue;
                }

                // Pool-managed: check for cooperative completion
                if ($async->poolManaged && $fiber->isSuspended()) {
                    $pool = RuntimeFiberPool::getInstance();
                    if ($pool->isCooperativeTaskComplete($fiber)) {
                        $result = $pool->recycleCooperativeFiber($fiber);
                        $async->fiber = null;
                        if ($result instanceof \Throwable) {
                            throw $result;
                        }
                        $results[$key] = $result;
                        unset($pending[$key]);
                        $didWork = true;
                        continue;
                    }
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
     * RuntimeFiberPool integration: for pool-managed fibers, completion is
     * detected via isCooperativeTaskComplete() and the worker shell is
     * recycled back to the pool. The stored result is returned directly
     * instead of calling fiber->getReturn().
     *
     * @return mixed The result of the asynchronous task.
     */
    public function await(): mixed
    {
        // If pool result was already collected (e.g. by awaitAll)
        if ($this->poolResultCollected) {
            $result = $this->poolResult;
            $this->poolResult = null;
            $this->poolResultCollected = false;
            $this->fiber = null;
            return $result;
        }

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
     *
     * RuntimeFiberPool: for pool-managed fibers, detects the cooperative
     * task completion sentinel and recycles the worker shell to the pool.
     */
    private function waitInsideFiber(): mixed
    {
        while (!$this->fiber->isTerminated()) {
            // Pool-managed: check for cooperative completion sentinel
            if ($this->poolManaged && $this->fiber->isSuspended()) {
                $pool = RuntimeFiberPool::getInstance();
                if ($pool->isCooperativeTaskComplete($this->fiber)) {
                    $result = $pool->recycleCooperativeFiber($this->fiber);
                    $this->fiber = null;
                    if ($result instanceof \Throwable) {
                        throw $result;
                    }
                    return $result;
                }
            }

            if ($this->fiber->isSuspended()) {
                $this->fiber->resume();
            }

            if (!$this->fiber->isTerminated()) {
                // Check again after resume — the task might have just completed
                if ($this->poolManaged && $this->fiber->isSuspended()) {
                    $pool = RuntimeFiberPool::getInstance();
                    if ($pool->isCooperativeTaskComplete($this->fiber)) {
                        $result = $pool->recycleCooperativeFiber($this->fiber);
                        $this->fiber = null;
                        if ($result instanceof \Throwable) {
                            throw $result;
                        }
                        return $result;
                    }
                }

                Pause::force();
            }
        }

        // Non-pooled fiber terminated — get result directly
        if ($this->poolManaged) {
            // Pool-managed fiber terminated unexpectedly — notify pool
            RuntimeFiberPool::getInstance()->notifyFiberTerminated(
                $this->fiber,
            );
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
     *
     * RuntimeFiberPool: also ticks the pool and checks for cooperative
     * task completion on pool-managed fibers.
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

            // Tick the RuntimeFiberPool for pending dispatch & scaling
            if (RuntimeFiberPool::isBooted()) {
                RuntimeFiberPool::getInstance()->tick();
            }

            // Pool-managed: check for cooperative completion sentinel
            if ($this->poolManaged && $this->fiber->isSuspended()) {
                $pool = RuntimeFiberPool::getInstance();
                if ($pool->isCooperativeTaskComplete($this->fiber)) {
                    $result = $pool->recycleCooperativeFiber($this->fiber);
                    $this->fiber = null;
                    if ($result instanceof \Throwable) {
                        throw $result;
                    }
                    return $result;
                }
            }

            if ($this->fiber->isSuspended()) {
                $this->fiber->resume();
                $didWork = true;
            }

            // Check again after resume
            if (
                $this->poolManaged &&
                $this->fiber !== null &&
                $this->fiber->isSuspended()
            ) {
                $pool = RuntimeFiberPool::getInstance();
                if ($pool->isCooperativeTaskComplete($this->fiber)) {
                    $result = $pool->recycleCooperativeFiber($this->fiber);
                    $this->fiber = null;
                    if ($result instanceof \Throwable) {
                        throw $result;
                    }
                    return $result;
                }
            }

            if ($this->fiber !== null && !$this->fiber->isTerminated()) {
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

        // Non-pooled fiber terminated — get result directly
        if ($this->poolManaged && $this->fiber !== null) {
            RuntimeFiberPool::getInstance()->notifyFiberTerminated(
                $this->fiber,
            );
        }
        $result = $this->fiber !== null ? $this->fiber->getReturn() : null;
        // Release fiber reference early so its memory can be reclaimed
        // before the Async object itself is garbage-collected.
        $this->fiber = null;
        return $result;
    }
}
