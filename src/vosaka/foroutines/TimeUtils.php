<?php

declare(strict_types=1);

namespace vosaka\foroutines;

final class TimeUtils
{
    /**
     * Returns the current time in milliseconds using monotonic clock.
     *
     * Uses hrtime(true) which returns int nanoseconds — no float
     * boxing/unboxing overhead compared to microtime(true).
     *
     * @return int Current time in milliseconds
     */
    public static function currentTimeMillis(): int
    {
        return intdiv(hrtime(true), 1_000_000);
    }

    /**
     * Returns the elapsed time in milliseconds since the given start time.
     *
     * @param int $startTime Start time in milliseconds (from currentTimeMillis)
     * @return int Elapsed time in milliseconds
     */
    public static function elapsedTimeMillis(int $startTime): int
    {
        return intdiv(hrtime(true), 1_000_000) - $startTime;
    }
}
