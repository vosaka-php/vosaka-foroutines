<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Fiber;
use Closure;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;

final class FiberUtils
{
    public static function fiberStillRunning(Fiber $fiber): bool
    {
        return $fiber->isStarted() && !$fiber->isTerminated();
    }

    /**
     * Creates a new Fiber instance from the provided callable, Async, Result, or existing Fiber.
     *
     * @param callable|Async|Fiber $callable The function to run in the fiber.
     * @return Fiber
     */
    public static function makeFiber(
        callable|Async|Fiber $callable,
    ): Fiber {

        if ($callable instanceof Async) {
            return $callable->fiber;
        } elseif ($callable instanceof Fiber) {
            return $callable;
        }

        return new Fiber($callable);
    }


}
