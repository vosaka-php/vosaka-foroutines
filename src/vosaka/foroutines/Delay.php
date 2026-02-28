<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Fiber;
use InvalidArgumentException;

final class Delay
{
    /**
     * Minimum idle sleep in microseconds to prevent 100% CPU spin
     * when driving the scheduler from a non-Fiber context.
     */
    private const IDLE_SLEEP_US = 500;

    /**
     * Delays the execution of the current Foroutine for a specified number of milliseconds.
     * If called outside of a Foroutine, it will run the event loop until the delay is complete.
     *
     * When running inside a Fiber, each iteration suspends (via Pause::new())
     * so the outer scheduler can drive other fibers forward.
     *
     * When running outside a Fiber (top-level context), the method manually
     * drives AsyncIO, WorkerPool, and Launch queue each tick, with a small
     * usleep on idle iterations to avoid burning 100% CPU in a tight
     * busy-wait loop.
     *
     * @param int $ms The delay duration in milliseconds.
     * @throws InvalidArgumentException if $ms is less than or equal to 0.
     */
    public static function new(int $ms): void
    {
        $start = TimeUtils::currentTimeMillis();

        // Non-Fiber path: manually drive all three scheduler subsystems
        // (AsyncIO, WorkerPool, Launch) while waiting for the delay.
        if (Fiber::getCurrent() === null) {
            while (TimeUtils::elapsedTimeMillis($start) < $ms) {
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

                // Avoid hot spin when there's no actionable work —
                // give the OS scheduler time to advance child processes
                // and prevent 100% CPU usage on idle waits.
                if (!$didWork) {
                    usleep(self::IDLE_SLEEP_US);
                }
            }
            return;
        }

        // Fiber path: cooperatively yield on each tick so other fibers
        // can run while this one waits for the delay to elapse.
        while (TimeUtils::elapsedTimeMillis($start) < $ms) {
            Pause::new();
        }
    }
}
