<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Closure;
use Fiber;
use Generator;
use venndev\vosaka\core\Result;

final class CallableUtils
{
    public static function makeCallable(
        callable|Generator|Async|Result|Fiber $callable,
    ): callable {
        if ($callable instanceof Fiber) {
            return self::fiberToCallable($callable);
        }

        if ($callable instanceof Async) {
            return self::fiberToCallable($callable->fiber);
        }

        if ($callable instanceof Result) {
            return self::generatorToCallable($callable->unwrap());
        }

        if ($callable instanceof Generator) {
            return self::generatorToCallable($callable);
        }

        return $callable;
    }

    public static function fiberToCallable(Fiber $fiber): callable
    {
        return function () use ($fiber) {
            if (!$fiber->isStarted()) {
                $fiber->start();
            }

            while ($fiber->isStarted() && !$fiber->isTerminated()) {
                $fiber->resume();
            }

            return $fiber->getReturn();
        };
    }

    public static function generatorToCallable(Generator $generator): callable
    {
        return function () use ($generator) {
            if (!$generator->valid()) {
                return null;
            }

            $result = null;
            while ($generator->valid()) {
                $result = $generator->current();
                $generator->next();
            }

            return $result;
        };
    }

    public static function makeCallableForThread(callable $callable, array $includedFiles): Closure
    {
        return function () use ($callable, $includedFiles) {
            foreach ($includedFiles as $file) {
                if (file_exists($file) && !in_array($file, get_included_files())) {
                    require_once $file;
                }
            }
            return call_user_func($callable);
        };
    }
}
