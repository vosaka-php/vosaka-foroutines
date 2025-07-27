<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Throwable;
use InvalidArgumentException;

final class Repeat
{
    /**
     * Repeats a callable a specified number of times.
     *
     * @param int $count The number of times to repeat the callable.
     * @param callable $callable The callable to repeat.
     * @throws InvalidArgumentException If the count is less than or equal to zero.
     */
    public static function new(
        int $count = 0,
        callable $callable
    ): void {
        if ($count <= 0) {
            throw new InvalidArgumentException('Count must be greater than zero.');
        }

        for ($i = 0; $i < $count; $i++) {
            try {
                $callable();
            } catch (Throwable $e) {
                throw $e;
            }
        }
    }
}
