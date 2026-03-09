<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Fiber;

/**
 * RunBlocking is a utility class that allows you to run multiple fibers synchronously
 * until all of them complete. It is useful for testing or when you need to block the
 * current thread until all asynchronous tasks are finished.
 */
final class RunBlocking
{
    /**
     * Minimum idle sleep in microseconds to prevent 100% CPU spin
     * when the scheduler has no immediate work to do.
     */
    private const IDLE_SLEEP_US = 500;

    /**
     * Runs multiple fibers synchronously and returns their results.
     *
     * The method drives three subsystems in a loop until the provided
     * callable's fiber terminates, then drains any remaining queued
     * Launch jobs and WorkerPool tasks:
     *
     *   1. AsyncIO::pollOnce()  — non-blocking stream_select() across all
     *      registered read/write watchers; resumes fibers whose streams
     *      are ready (true async I/O in Dispatchers::DEFAULT context).
     *   2. WorkerPool::run()    — spawns child processes for Dispatchers::IO
     *      tasks up to the pool size limit.
     *   3. Launch::runOnce()    — dequeues one fiber from the cooperative
     *      scheduler queue, resumes it, and re-enqueues if still running.
     *
     * A small usleep is inserted on idle iterations (where none of the
     * subsystems had actionable work) to prevent burning 100% CPU in a
     * tight busy-wait spin loop.
     *
     * @param callable|Async|Fiber $callable The fiber(s) to run.
     * @param Dispatchers $dispatchers The dispatcher context.
     * @return void
     */
    public static function new(
        callable|Async|Fiber $callable,
        Dispatchers $dispatchers = Dispatchers::DEFAULT,
    ): void {
        // NOTE: Dispatchers::IO on RunBlocking is treated the same as
        // DEFAULT. The closure typically contains scheduler primitives
        // (Launch, Delay, Repeat, WithTimeout, Async, Thread::await)
        // that depend on the parent process's event loop. Sending the
        // entire closure to a worker process would orphan those
        // primitives because workers have no scheduler.
        //
        // Individual heavy I/O operations inside the closure should
        // use Async::new(fn, Dispatchers::IO) or Launch::new(fn,
        // Dispatchers::IO) which correctly dispatch just that unit of
        // work to the WorkerPool while keeping the orchestration in
        // the parent.

        // Ensure Launch::$queue (SplQueue) is initialized before either
        // phase tries to access it.  Launch::$queue is only created inside
        // Launch's constructor, so calling getInstance() guarantees the
        // singleton (and therefore the queue) exists.  Without this, a
        // fiber that terminates without ever suspending would skip Phase 1
        // entirely, and Phase 2's `count(Launch::$queue)` would hit an
        // uninitialized typed static property.
        Launch::getInstance();

        if (!$callable instanceof Fiber) {
            // Fast-path: use FiberPool for Closure callables
            if ($callable instanceof \Closure) {
                $callable = FiberPool::global()->acquire($callable);
            } else {
                $callable = FiberUtils::makeFiber($callable);
            }
        }

        if ($dispatchers === Dispatchers::MAIN) {
            EventLoop::add($callable);
            return;
        }

        if (!$callable->isStarted()) {
            $callable->start();
        }

        // Phase 1: Drive the main fiber until it terminates.
        // Each iteration also ticks AsyncIO, WorkerPool, and Launch queue
        // so that child fibers / IO workers / stream watchers can make
        // progress concurrently.
        while (FiberUtils::fiberStillRunning($callable)) {
            $didWork = false;

            // Drive non-blocking stream I/O — poll all registered
            // read/write watchers via stream_select() and resume
            // fibers whose streams became ready.
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

            if ($callable->isSuspended()) {
                $callable->resume();
                $didWork = true;
            }

            // Avoid hot spin when waiting for child processes, stream
            // events, or other external activity — give the OS scheduler
            // time to advance them.
            if (!$didWork) {
                usleep(self::IDLE_SLEEP_US);
            }
        }

        // Phase 2: Drain any remaining Launch jobs and WorkerPool tasks
        // that were spawned by the main fiber before it terminated.
        while (
            count(Launch::$queue) > 0 ||
            !WorkerPool::isEmpty() ||
            AsyncIO::hasPending()
        ) {
            $didWork = false;

            if (AsyncIO::hasPending()) {
                if (AsyncIO::pollOnce()) {
                    $didWork = true;
                }
            }

            if (!WorkerPool::isEmpty()) {
                WorkerPool::run();
                $didWork = true;
            }

            if (!Launch::$queue->isEmpty()) {
                Launch::getInstance()->runOnce();
                $didWork = true;
            }

            if (!$didWork) {
                usleep(self::IDLE_SLEEP_US);
            }
        }
    }
}
