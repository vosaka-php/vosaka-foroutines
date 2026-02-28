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
    public static function wait(): void
    {
        while (
            !WorkerPool::isEmpty() ||
            Launch::getInstance()->hasActiveTasks() ||
            AsyncIO::hasPending()
        ) {
            $didWork = false;

            // Drive non-blocking stream I/O — poll all registered
            // read/write watchers via stream_select() and resume
            // fibers whose streams became ready.
            if (AsyncIO::hasPending()) {
                if (AsyncIO::pollOnce()) {
                    $didWork = true;
                }
            }

            // Drive IO worker pool — may spawn new child processes
            if (!WorkerPool::isEmpty()) {
                WorkerPool::run();
                $didWork = true;
            }

            // Drive cooperative fiber scheduler — resume one queued fiber
            if (Launch::getInstance()->hasActiveTasks()) {
                Launch::getInstance()->runOnce();
                $didWork = true;
            }

            // Only sleep when none of the subsystems had actionable work
            // this tick. When work IS happening, fibers yield naturally
            // via Pause::new() / Fiber::suspend(), so we don't need
            // extra delay. When idle (e.g. waiting for a child process
            // to finish or a stream to become ready), the sleep prevents
            // a hot spin loop.
            if (!$didWork) {
                usleep(self::IDLE_SLEEP_US);
            }
        }
    }
}
