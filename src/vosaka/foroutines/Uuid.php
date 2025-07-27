<?php

declare(strict_types=1);

namespace vosaka\foroutines;

final class Uuid
{
    /**
     * Generates a unique identifier.
     *
     * This method generates a unique identifier using the `uniqid` function,
     * which is suitable for use as a job ID or other unique identifiers in the
     * Foroutines framework.
     *
     * @return string A unique identifier.
     */
    public static function new(): string
    {
        return uniqid('', true);
    }
}
