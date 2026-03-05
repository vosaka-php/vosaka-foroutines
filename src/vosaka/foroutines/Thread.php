<?php

declare(strict_types=1);

namespace vosaka\foroutines;

final class Thread
{
    /**
     * Minimum idle sleep in microseconds to prevent 100% CPU spin
     * when the scheduler has no immediate work to do.
     * 500µs strikes a balance between responsiveness and CPU usage.
     */
    private const IDLE_SLEEP_US = 500;

    /**
     * Waits for all launched tasks, worker pool jobs, and async I/O
     * operations to complete.
     *
     * The loop drives three subsystems on every tick:
     *   1. AsyncIO::pollOnce()  — non-blocking stream_select() across all
     *      registered read/write watchers; resumes fibers whose streams
     *      are ready (true async I/O in Dispatchers::DEFAULT context).
     *   2. WorkerPool::run()    — spawns child processes for Dispatchers::IO
     *      tasks up to the pool size limit.
     *   3. Launch::runOnce()    — dequeues one fiber from the cooperative
     *      scheduler queue, resumes it, and re-enqueues if still running.
     *
     * A small usleep is added on idle iterations (where none of the three
     * subsystems had actionable work) to avoid burning 100% CPU in a tight
     * busy-wait loop. This gives the OS scheduler time to advance child
     * processes and keeps idle CPU usage near zero — similar to how libuv
     * uses epoll/kqueue to sleep until an event arrives.
     */
    public static function await(): void
    {
        $insideFiber = \Fiber::getCurrent() !== null;
        $poolBooted = RuntimeFiberPool::isBooted();

        while (
            !WorkerPool::isEmpty() ||
            Launch::getInstance()->hasActiveTasks() ||
            AsyncIO::hasPending() ||
            ($poolBooted &&
                RuntimeFiberPool::getInstance()->hasPendingTasks()) ||
            ($poolBooted &&
                RuntimeFiberPool::getInstance()->hasCooperativeTasks())
        ) {
            // When called inside a fiber (e.g. from within RunBlocking's main
            // fiber), we must suspend on every iteration so the outer
            // RunBlocking loop gets control and can call RuntimeFiberPool::tick()
            // to dispatch pending tasks to newly-idle worker shells.
            //
            // Without this suspend, Thread::await() spins forever holding the
            // fiber — RunBlocking's outer loop never resumes, tick() is never
            // called, and tasks queued beyond the pool's initial size (default 16)
            // are never dispatched, causing a permanent hang.
            if ($insideFiber) {
                Pause::force();
                $poolBooted = RuntimeFiberPool::isBooted();
                continue;
            }

            // Outside fiber: drive all subsystems manually.
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

            if (Launch::getInstance()->hasActiveTasks()) {
                Launch::getInstance()->runOnce();
                $didWork = true;
            }

            if ($poolBooted) {
                $pool = RuntimeFiberPool::getInstance();
                $pool->tick();
                if ($pool->hasPendingTasks() || $pool->hasCooperativeTasks()) {
                    $didWork = true;
                }
            }

            if (!$didWork) {
                usleep(self::IDLE_SLEEP_US);
            }
        }
    }
}
