<?php

declare(strict_types=1);

namespace vosaka\foroutines\flow;

use RuntimeException;
use Throwable;

/**
 * Abstract base class for Flow implementations
 */
abstract class BaseFlow implements FlowInterface
{
    protected array $operators = [];
    protected bool $isCompleted = false;
    protected ?Throwable $exception = null;

    /**
     * Transform each emitted value
     */
    public function map(callable $transform): FlowInterface
    {
        $newFlow = clone $this;
        $newFlow->operators[] = ['type' => 'map', 'callback' => $transform];
        return $newFlow;
    }

    /**
     * Filter emitted values
     */
    public function filter(callable $predicate): FlowInterface
    {
        $newFlow = clone $this;
        $newFlow->operators[] = ['type' => 'filter', 'callback' => $predicate];
        return $newFlow;
    }

    /**
     * Take only the first n values
     */
    public function take(int $count): FlowInterface
    {
        $newFlow = clone $this;
        $newFlow->operators[] = ['type' => 'take', 'count' => $count];
        return $newFlow;
    }

    /**
     * Skip the first n values
     */
    public function skip(int $count): FlowInterface
    {
        $newFlow = clone $this;
        $newFlow->operators[] = ['type' => 'skip', 'count' => $count];
        return $newFlow;
    }

    /**
     * Transform each value to a Flow and flatten the result
     */
    public function flatMap(callable $transform): FlowInterface
    {
        $newFlow = clone $this;
        $newFlow->operators[] = ['type' => 'flatMap', 'callback' => $transform];
        return $newFlow;
    }

    /**
     * Perform an action for each emitted value without transforming it
     */
    public function onEach(callable $action): FlowInterface
    {
        $newFlow = clone $this;
        $newFlow->operators[] = ['type' => 'onEach', 'callback' => $action];
        return $newFlow;
    }

    /**
     * Catch exceptions and handle them
     */
    public function catch(callable $handler): FlowInterface
    {
        $newFlow = clone $this;
        $newFlow->operators[] = ['type' => 'catch', 'callback' => $handler];
        return $newFlow;
    }

    /**
     * Execute when the flow completes (successfully or with error)
     */
    public function onCompletion(callable $action): FlowInterface
    {
        $newFlow = clone $this;
        $newFlow->operators[] = ['type' => 'onCompletion', 'callback' => $action];
        return $newFlow;
    }

    /**
     * Collect and return the first emitted value
     */
    public function first(): mixed
    {
        $result = null;
        $found = false;

        $this->take(1)->collect(function ($value) use (&$result, &$found) {
            $result = $value;
            $found = true;
        });

        if (!$found) {
            throw new RuntimeException('Flow is empty');
        }

        return $result;
    }

    /**
     * Collect and return the first emitted value or null if empty
     */
    public function firstOrNull(): mixed
    {
        $result = null;

        $this->take(1)->collect(function ($value) use (&$result) {
            $result = $value;
        });

        return $result;
    }

    /**
     * Collect all values into an array
     */
    public function toArray(): array
    {
        $result = [];

        $this->collect(function ($value) use (&$result) {
            $result[] = $value;
        });

        return $result;
    }

    /**
     * Count the number of emitted values
     */
    public function count(): int
    {
        $count = 0;

        $this->collect(function () use (&$count) {
            $count++;
        });

        return $count;
    }

    /**
     * Reduce the flow to a single value
     */
    public function reduce(mixed $initial, callable $operation): mixed
    {
        $accumulator = $initial;

        $this->collect(function ($value) use (&$accumulator, $operation) {
            $accumulator = $operation($accumulator, $value);
        });

        return $accumulator;
    }

    /**
     * Apply operators to a value
     */
    protected function applyOperators(mixed $value, int &$emittedCount, int &$skippedCount): mixed
    {
        $currentValue = $value;

        foreach ($this->operators as $operator) {
            switch ($operator['type']) {
                case 'map':
                    $currentValue = $operator['callback']($currentValue);
                    break;

                case 'filter':
                    if (!$operator['callback']($currentValue)) {
                        return null; // Filter out this value
                    }
                    break;

                case 'take':
                    if ($emittedCount >= $operator['count']) {
                        $this->isCompleted = true;
                        return null;
                    }
                    break;

                case 'skip':
                    if ($skippedCount < $operator['count']) {
                        $skippedCount++;
                        return null;
                    }
                    break;

                case 'onEach':
                    $operator['callback']($currentValue);
                    break;

                case 'flatMap':
                    $subFlow = $operator['callback']($currentValue);
                    if ($subFlow instanceof BaseFlow) {
                        $currentValue = $subFlow->firstOrNull();
                    }
                    break;
            }
        }

        return $currentValue;
    }

    /**
     * Execute completion callbacks
     */
    protected function executeOnCompletion(?Throwable $exception): void
    {
        foreach ($this->operators as $operator) {
            if ($operator['type'] === 'onCompletion') {
                $operator['callback']($exception);
            }
        }
    }

    /**
     * Check if exception was handled by catch operator
     */
    protected function wasExceptionHandled(Throwable $exception): bool
    {
        foreach ($this->operators as $operator) {
            if ($operator['type'] === 'catch') {
                try {
                    $operator['callback']($exception);
                    return true;
                } catch (Throwable) {
                    continue;
                }
            }
        }
        return false;
    }

    public function __clone()
    {
        $this->isCompleted = false;
        $this->exception = null;
    }
}
