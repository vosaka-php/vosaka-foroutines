<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Fiber;
use InvalidArgumentException;

final class Delay
{
    /**
     * Delays the execution of the current Foroutine for a specified number of milliseconds.
     * If called outside of a Foroutine, it will run the event loop until the delay is complete.
     *
     * @param int $ms The delay duration in milliseconds.
     * @throws InvalidArgumentException if $ms is less than or equal to 0.
     */
    public static function new(int $ms): void
    {
        $start = TimeUtils::currentTimeMillis();
        if (Fiber::getCurrent() === null) {
            while (TimeUtils::elapsedTimeMillis($start) < $ms) {
                Launch::getInstance()->runOnce();
            }
        }

        while (TimeUtils::elapsedTimeMillis($start) < $ms) {
            Pause::new();
        }
    }
}
