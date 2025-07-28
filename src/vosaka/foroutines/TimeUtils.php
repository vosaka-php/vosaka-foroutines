<?php

declare(strict_types=1);

namespace vosaka\foroutines;

final class TimeUtils
{
    /**
     * Returns the current time in milliseconds.
     *
     * @return int Current time in milliseconds
     */
    public static function currentTimeMillis(): int
    {
        return (int) (microtime(true) * 1000);
    }

    /**
     * Returns the elapsed time in milliseconds since the given start time.
     *
     * @param int $startTime Start time in milliseconds
     * @return int Elapsed time in milliseconds
     */
    public static function elapsedTimeMillis(int $startTime): int
    {
        return self::currentTimeMillis() - $startTime;
    }
}
