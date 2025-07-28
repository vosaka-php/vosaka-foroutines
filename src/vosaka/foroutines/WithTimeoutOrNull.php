<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use RuntimeException;

final class WithTimeoutOrNull
{
    /**
     * Runs a Foroutine with specified timeout in milliseconds.
     * Returns null if the timeout is exceeded.
     *
     * @param int $timeoutMs Timeout in milliseconds
     * @param callable $callable The function to execute in Foroutine
     * @return mixed|null Result of the block execution or null if timed out
     */
    public static function new(int $timeoutMs, callable $callable): mixed
    {
        try {
            return WithTimeout::new($timeoutMs, $callable);
        } catch (RuntimeException) {
            return null;
        }
    }
}
