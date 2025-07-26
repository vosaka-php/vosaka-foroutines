<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Fiber;
use InvalidArgumentException;

/**
 * Sleep for a specified number of seconds.
 * This function is designed to be used within a Foroutine context.
 *
 * @param float $seconds The number of seconds to sleep. Must be a non-negative integer.
 * @throws InvalidArgumentException if the sleep duration is negative.
 */
final class Sleep
{
    /**
     * Sleeps for the specified number of seconds.
     *
     * This method will yield control back to the event loop while sleeping,
     * allowing other Foroutines to run concurrently.
     *
     * @param float $seconds The number of seconds to sleep. Must be a non-negative integer.
     * @throws InvalidArgumentException if the sleep duration is negative.
     */
    public static function new(float $seconds): void
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException('Sleep duration must be a non-negative integer.');
        }

        $start = microtime(true);
        if (Fiber::getCurrent() === null) {
            while ((microtime(true) - $start) < $seconds) {
                Launch::runOnce();
            }
        }

        while ((microtime(true) - $start) < $seconds) {
            Pause::new();
        }
    }
}
