<?php

declare(strict_types=1);

namespace vosaka\foroutines\flow;

use Generator;
use Fiber;
use RuntimeException;
use Throwable;
use vosaka\foroutines\Async;
use vosaka\foroutines\FiberUtils;

/**
 * Cold Flow - Creates new stream for each collector
 */
final class Flow extends BaseFlow
{
    private ?Fiber $fiber = null;

    private function __construct(
        private readonly callable|Generator|Async|Fiber $source,
    ) {}

    /**
     * Create a new Flow from a callable, Generator, Async, or Fiber
     */
    public static function new(callable|Generator|Async|Fiber $source): Flow
    {
        return new self($source);
    }

    /**
     * Create a Flow that emits a sequence of values
     */
    public static function of(mixed ...$values): Flow
    {
        return self::new(function () use ($values) {
            foreach ($values as $value) {
                Flow::emit($value);
            }
        });
    }

    /**
     * Create an empty Flow
     */
    public static function empty(): Flow
    {
        return self::new(function () {
            // Empty flow - no emissions
        });
    }

    /**
     * Create a Flow from an array
     */
    public static function fromArray(array $array): Flow
    {
        return self::new(function () use ($array) {
            foreach ($array as $value) {
                Flow::emit($value);
            }
        });
    }

    /**
     * Emit a value in the current Flow context
     */
    public static function emit(mixed $value): void
    {
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            throw new RuntimeException(
                "Flow::emit can only be called within a Flow context.",
            );
        }
        Fiber::suspend($value);
    }

    /**
     * Collect all emitted values
     */
    public function collect(callable $collector): void
    {
        if ($this->isCompleted) {
            return;
        }

        try {
            $this->fiber = FiberUtils::makeFiber($this->source);

            if (!$this->fiber->isStarted()) {
                $this->fiber->start();
            }

            $emittedCount = 0;
            $skippedCount = 0;

            while (!$this->fiber->isTerminated()) {
                if ($this->fiber->isSuspended()) {
                    $value = $this->fiber->resume();

                    $processedValue = $this->applyOperators(
                        $value,
                        $emittedCount,
                        $skippedCount,
                    );

                    if ($processedValue !== null) {
                        $collector($processedValue);
                        $emittedCount++;
                    }

                    if ($this->isCompleted) {
                        break;
                    }
                } else {
                    break;
                }
            }

            $this->isCompleted = true;
            $this->executeOnCompletion(null);
        } catch (Throwable $e) {
            $this->exception = $e;
            $this->isCompleted = true;
            $this->executeOnCompletion($e);

            if (!$this->wasExceptionHandled($e)) {
                throw $e;
            }
        }
    }

    public function __clone()
    {
        parent::__clone();
        $this->fiber = null;
    }
}
