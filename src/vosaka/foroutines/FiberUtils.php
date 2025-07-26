<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Fiber;
use Generator;
use Closure;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;
use venndev\vosaka\core\Result;

final class FiberUtils
{
    public static function fiberStillRunning(Fiber $fiber): bool
    {
        return $fiber->isStarted() && !$fiber->isTerminated();
    }

    /**
     * Creates a new Fiber instance from the provided callable, generator, Async, Result, or existing Fiber.
     *
     * @param callable|Generator|Async|Result|Fiber $callable The function or generator to run in the fiber.
     * @return Fiber
     */
    public static function makeFiber(callable|Generator|Async|Result|Fiber $callable): Fiber
    {
        if ($callable instanceof Result) {
            $callable = $callable->unwrap();
        }

        if (is_callable($callable) && self::isFiberCallbackGenerator($callable)) {
            $callable = $callable();
        }

        if ($callable instanceof Async) {
            return $callable->fiber;
        } elseif ($callable instanceof Fiber) {
            return $callable;
        } elseif ($callable instanceof Generator) {
            return new Fiber(function () use ($callable) {
                while ($callable->valid()) {
                    $callable->next();
                    Pause::new();
                }
                return $callable->getReturn();
            });
        }

        return new Fiber($callable);
    }

    private static function isFiberCallbackGenerator(callable $callback): bool
    {
        try {
            if (is_array($callback)) {
                $reflection = new ReflectionMethod($callback[0], $callback[1]);
            } elseif (is_string($callback)) {
                $reflection = new ReflectionFunction($callback);
            } elseif ($callback instanceof Closure) {
                $reflection = new ReflectionFunction($callback);
            } else {
                $reflection = new ReflectionMethod($callback, '__invoke');
            }

            $returnType = $reflection->getReturnType();
            if ($returnType instanceof ReflectionNamedType) {
                return $returnType->getName() === 'Generator';
            }

            if ($returnType instanceof ReflectionUnionType) {
                foreach ($returnType->getTypes() as $type) {
                    if ($type->getName() === 'Generator') {
                        return true;
                    }
                }
            }

            return false;
        } catch (ReflectionException) {
            return false;
        }
    }
}
