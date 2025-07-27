<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Fiber;
use InvalidArgumentException;

final class Delay
{
    /**
     * Delays the execution of the current fiber for a specified number of seconds.
     *
     * @param float $seconds The number of seconds to delay. Must be a non-negative float.
     * @throws InvalidArgumentException If the provided duration is negative.
     */
    public static function new(float $seconds): void
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException('Delay duration must be a non-negative integer.');
        }

        $start = microtime(true);
        if (Fiber::getCurrent() === null) {
            while ((microtime(true) - $start) < $seconds) {
                Launch::getInstance()->runOnce();
            }
        }

        while ((microtime(true) - $start) < $seconds) {
            Pause::new();
        }
    }
}
