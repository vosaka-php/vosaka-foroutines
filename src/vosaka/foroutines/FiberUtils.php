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

final class FiberUtils
{
    public static function fiberStillRunning(Fiber $fiber): bool
    {
        return $fiber->isStarted() && !$fiber->isTerminated();
    }

    /**
     * Creates a new Fiber instance from the provided callable, generator, Async, Result, or existing Fiber.
     *
     * @param callable|Generator|Async|Fiber $callable The function or generator to run in the fiber.
     * @return Fiber
     */
    public static function makeFiber(
        callable|Generator|Async|Fiber $callable,
    ): Fiber {
        if (
            is_callable($callable) &&
            self::isFiberCallbackGenerator($callable)
        ) {
            $callable = $callable();
        }

        if ($callable instanceof Async) {
            return $callable->fiber;
        } elseif ($callable instanceof Fiber) {
            return $callable;
        } elseif ($callable instanceof Generator) {
            // Arrow function: lighter than function() use() — no explicit
            // use-binding array allocation, single-expression body delegates
            // to a static helper method (fewer opcodes in the closure body).
            return new Fiber(fn() => self::runGenerator($callable));
        }

        return new Fiber($callable);
    }

    /**
     * Drive a Generator to completion, cooperatively yielding between steps.
     *
     * Extracted as a static method so the Fiber closure body is a single
     * fn() => self::runGenerator($gen) call — minimal opcodes, and the
     * actual loop logic lives in a regular method (no per-call allocation).
     *
     * @param Generator $generator The generator to run.
     * @return mixed The generator's return value.
     */
    private static function runGenerator(Generator $generator): mixed
    {
        while ($generator->valid()) {
            $generator->next();
            Pause::new();
        }
        return $generator->getReturn();
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
                $reflection = new ReflectionMethod($callback, "__invoke");
            }

            $returnType = $reflection->getReturnType();
            if ($returnType instanceof ReflectionNamedType) {
                return $returnType->getName() === "Generator";
            }

            if ($returnType instanceof ReflectionUnionType) {
                foreach ($returnType->getTypes() as $type) {
                    if ($type->getName() === "Generator") {
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
