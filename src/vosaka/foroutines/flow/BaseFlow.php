<?php

declare(strict_types=1);

namespace vosaka\foroutines\flow;

use Fiber;
use RuntimeException;
use Throwable;
use vosaka\foroutines\Pause;

/**
 * Abstract base class for Flow implementations.
 *
 * Optimization notes:
 *   - Operator entries use indexed arrays with int type constants instead of
 *     associative arrays with string keys ("type" => "map", "callback" => ...).
 *     This avoids hash table allocation per operator entry and enables faster
 *     int-based switch matching in the hot applyOperators() path.
 *   - Operator structure: [int $type, mixed ...$args]
 *       [OP_MAP,            callable $transform]
 *       [OP_FILTER,         callable $predicate]
 *       [OP_TAKE,           int $count]
 *       [OP_SKIP,           int $count]
 *       [OP_FLAT_MAP,       callable $transform]
 *       [OP_ON_EACH,        callable $action]
 *       [OP_CATCH,          callable $handler]
 *       [OP_ON_COMPLETION,  callable $action]
 *       [OP_BUFFER,         int $capacity, BackpressureStrategy $strategy]
 *       [OP_DISTINCT,       callable $compare]
 */
abstract class BaseFlow implements FlowInterface
{
    // ─── Operator type constants ─────────────────────────────────────
    // Using int constants instead of string keys for operator type
    // identification. switch/match on int is compiled to a jump table
    // by the PHP engine — O(1) dispatch vs O(n) string comparison.

    public const OP_MAP = 0;
    public const OP_FILTER = 1;
    public const OP_TAKE = 2;
    public const OP_SKIP = 3;
    public const OP_FLAT_MAP = 4;
    public const OP_ON_EACH = 5;
    public const OP_CATCH = 6;
    public const OP_ON_COMPLETION = 7;
    public const OP_BUFFER = 8;
    public const OP_DISTINCT = 9;

    protected array $operators = [];
    protected bool $isCompleted = false;
    protected ?Throwable $exception = null;

    /**
     * Transform each emitted value
     */
    public function map(callable $transform): FlowInterface
    {
        $newFlow = clone $this;
        $newFlow->operators[] = [self::OP_MAP, $transform];
        return $newFlow;
    }

    /**
     * Filter emitted values
     */
    public function filter(callable $predicate): FlowInterface
    {
        $newFlow = clone $this;
        $newFlow->operators[] = [self::OP_FILTER, $predicate];
        return $newFlow;
    }

    /**
     * Take only the first n values
     */
    public function take(int $count): FlowInterface
    {
        $newFlow = clone $this;
        $newFlow->operators[] = [self::OP_TAKE, $count];
        return $newFlow;
    }

    /**
     * Skip the first n values
     */
    public function skip(int $count): FlowInterface
    {
        $newFlow = clone $this;
        $newFlow->operators[] = [self::OP_SKIP, $count];
        return $newFlow;
    }

    /**
     * Transform each value to a Flow and flatten the result
     */
    public function flatMap(callable $transform): FlowInterface
    {
        $newFlow = clone $this;
        $newFlow->operators[] = [self::OP_FLAT_MAP, $transform];
        return $newFlow;
    }

    /**
     * Perform an action for each emitted value without transforming it
     */
    public function onEach(callable $action): FlowInterface
    {
        $newFlow = clone $this;
        $newFlow->operators[] = [self::OP_ON_EACH, $action];
        return $newFlow;
    }

    /**
     * Catch exceptions and handle them
     */
    public function catch(callable $handler): FlowInterface
    {
        $newFlow = clone $this;
        $newFlow->operators[] = [self::OP_CATCH, $handler];
        return $newFlow;
    }

    /**
     * Execute when the flow completes (successfully or with error)
     */
    public function onCompletion(callable $action): FlowInterface
    {
        $newFlow = clone $this;
        $newFlow->operators[] = [self::OP_ON_COMPLETION, $action];
        return $newFlow;
    }

    /**
     * Add an intermediate backpressure buffer between the upstream producer
     * and the downstream collector.
     *
     * When the buffer is full and the upstream emits a new value, the chosen
     * BackpressureStrategy determines the outcome:
     *
     *   - SUSPEND:     The producer fiber yields (Fiber::suspend) until the
     *                  downstream collector consumes at least one value and
     *                  frees buffer space. Other fibers can run in the meantime.
     *   - DROP_OLDEST: The oldest value in the buffer is evicted to make room.
     *   - DROP_LATEST: The incoming emission is silently discarded.
     *   - ERROR:       A RuntimeException is thrown immediately.
     *
     * The buffer operator is recorded as a special entry in the operators
     * pipeline and is applied during collect() by BaseFlow subclasses that
     * support it (Flow, SharedFlow). For subclasses that dispatch
     * synchronously (e.g. Flow::of()), the buffer accumulates values and
     * drains them to the downstream collector in FIFO order, applying the
     * overflow strategy when capacity is exceeded.
     *
     * Usage:
     *
     *     Flow::of(1, 2, 3, 4, 5)
     *         ->map(fn($v) => $v * 10)
     *         ->buffer(capacity: 3, onOverflow: BackpressureStrategy::DROP_OLDEST)
     *         ->collect(function ($v) {
     *             usleep(10000); // slow consumer
     *             echo $v . "\n";
     *         });
     *
     * @param int $capacity Maximum number of values the buffer can hold (> 0).
     * @param BackpressureStrategy $onOverflow Strategy when the buffer is full.
     * @return FlowInterface A new Flow with the buffer operator applied.
     */
    public function buffer(
        int $capacity = 64,
        BackpressureStrategy $onOverflow = BackpressureStrategy::SUSPEND,
    ): FlowInterface {
        if ($capacity <= 0) {
            throw new RuntimeException(
                "Buffer capacity must be > 0, got {$capacity}",
            );
        }

        $newFlow = clone $this;
        $newFlow->operators[] = [self::OP_BUFFER, $capacity, $onOverflow];
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
            throw new RuntimeException("Flow is empty");
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
     * Apply operators to a value.
     *
     * Uses int-based switch on operator type constants for O(1) dispatch
     * instead of string comparison. Buffer operators are skipped here —
     * they are handled separately by the collect() implementation via
     * getBufferOperator() so that the buffer can accumulate values and
     * apply backpressure across multiple emissions.
     *
     * Operator layout (indexed array):
     *   [0] = int type constant (OP_MAP, OP_FILTER, etc.)
     *   [1] = callable/int (callback or count, depending on type)
     *   [2] = (optional) extra argument (e.g. BackpressureStrategy for OP_BUFFER)
     */
    protected function applyOperators(
        mixed $value,
        int &$emittedCount,
        int &$skippedCount,
    ): mixed {
        $currentValue = $value;

        foreach ($this->operators as $operator) {
            switch ($operator[0]) {
                case self::OP_MAP:
                    $currentValue = $operator[1]($currentValue);
                    break;

                case self::OP_FILTER:
                    if (!$operator[1]($currentValue)) {
                        return null; // Filter out this value
                    }
                    break;

                case self::OP_TAKE:
                    if ($emittedCount >= $operator[1]) {
                        $this->isCompleted = true;
                        return null;
                    }
                    break;

                case self::OP_SKIP:
                    if ($skippedCount < $operator[1]) {
                        $skippedCount++;
                        return null;
                    }
                    break;

                case self::OP_ON_EACH:
                    $operator[1]($currentValue);
                    break;

                case self::OP_FLAT_MAP:
                    $subFlow = $operator[1]($currentValue);
                    if ($subFlow instanceof BaseFlow) {
                        $currentValue = $subFlow->firstOrNull();
                    }
                    break;

                case self::OP_BUFFER:
                    // Buffer operators are handled at the collect() level,
                    // not per-value. Skip here — see hasBufferOperator()
                    // and getBufferOperator().
                    break;

                case self::OP_DISTINCT:
                    // Handled by subclass-specific collect() logic
                    break;
            }
        }

        return $currentValue;
    }

    // ─── Buffer operator helpers ─────────────────────────────────────

    /**
     * Check whether the operator pipeline contains a buffer() operator.
     */
    protected function hasBufferOperator(): bool
    {
        foreach ($this->operators as $op) {
            if ($op[0] === self::OP_BUFFER) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get the first buffer operator configuration from the pipeline.
     *
     * Returns an indexed array [OP_BUFFER, int capacity, BackpressureStrategy]
     * or null if no buffer operator is present.
     *
     * @return array{0: int, 1: int, 2: BackpressureStrategy}|null
     */
    protected function getBufferOperator(): ?array
    {
        foreach ($this->operators as $op) {
            if ($op[0] === self::OP_BUFFER) {
                return $op;
            }
        }
        return null;
    }

    /**
     * Apply backpressure buffering to a value being emitted to a collector.
     *
     * This method manages an internal ring buffer between the upstream
     * producer and the downstream $collector callback. When the buffer
     * is full, the chosen BackpressureStrategy determines the outcome.
     *
     * @param mixed $value The value to buffer.
     * @param array &$buffer The buffer array (passed by reference, managed by caller).
     * @param int &$bufferCount Current number of items in the buffer.
     * @param int $capacity Maximum buffer size.
     * @param BackpressureStrategy $strategy Overflow strategy.
     * @param callable $collector The downstream collector callback.
     * @return bool True if the value was accepted (buffered or delivered),
     *              false if it was dropped (DROP_LATEST when full).
     * @throws RuntimeException When strategy is ERROR and buffer is full.
     */
    protected function applyBufferOperator(
        mixed $value,
        array &$buffer,
        int &$bufferCount,
        int $capacity,
        BackpressureStrategy $strategy,
        callable $collector,
    ): bool {
        // Try to drain the buffer first — deliver as many values as possible
        // to the collector before deciding whether we need backpressure.
        $this->drainBuffer($buffer, $bufferCount, $collector);

        // If there's space now, just add the new value
        if ($bufferCount < $capacity) {
            $buffer[] = $value;
            $bufferCount++;
            return true;
        }

        // Buffer is full — apply backpressure strategy
        switch ($strategy) {
            case BackpressureStrategy::SUSPEND:
                return $this->bufferSuspend(
                    $value,
                    $buffer,
                    $bufferCount,
                    $capacity,
                    $collector,
                );

            case BackpressureStrategy::DROP_OLDEST:
                // Evict oldest, append new
                if ($bufferCount > 0) {
                    array_shift($buffer);
                    $bufferCount--;
                }
                $buffer[] = $value;
                $bufferCount++;
                return true;

            case BackpressureStrategy::DROP_LATEST:
                // Silently discard the new value
                return false;

            case BackpressureStrategy::ERROR:
                throw new RuntimeException(
                    "Flow buffer overflow: buffer is full " .
                        "(capacity={$capacity}, strategy={$strategy->value}). " .
                        "Consider increasing buffer capacity or using a " .
                        "different BackpressureStrategy.",
                );
        }

        return false;
    }

    /**
     * Drain buffered values to the collector.
     *
     * Delivers all currently buffered values to the downstream collector
     * in FIFO order, freeing buffer space for new emissions.
     *
     * @param array &$buffer The buffer to drain.
     * @param int &$bufferCount Current buffer size (updated in place).
     * @param callable $collector The downstream collector callback.
     */
    protected function drainBuffer(
        array &$buffer,
        int &$bufferCount,
        callable $collector,
    ): void {
        while ($bufferCount > 0) {
            $item = array_shift($buffer);
            $bufferCount--;
            $collector($item);
        }
    }

    /**
     * SUSPEND strategy: yield the current fiber until buffer space opens up,
     * then buffer the value.
     *
     * If called outside a Fiber context, falls back to a spin-loop with a
     * small real-time sleep, eventually dropping the oldest value to avoid
     * deadlock.
     */
    private function bufferSuspend(
        mixed $value,
        array &$buffer,
        int &$bufferCount,
        int $capacity,
        callable $collector,
    ): bool {
        $fiber = Fiber::getCurrent();

        if ($fiber !== null) {
            // Inside a Fiber — yield and retry until space is available
            $maxYields = 100000;
            $yields = 0;

            while ($bufferCount >= $capacity && $yields < $maxYields) {
                // Try to drain first
                $this->drainBuffer($buffer, $bufferCount, $collector);

                if ($bufferCount < $capacity) {
                    break;
                }

                // Yield to let collector fibers run
                Pause::force();
                $yields++;
            }
        } else {
            // Outside Fiber — spin-wait with small sleep
            $maxSpins = 10000;
            $spins = 0;

            while ($bufferCount >= $capacity && $spins < $maxSpins) {
                $this->drainBuffer($buffer, $bufferCount, $collector);

                if ($bufferCount < $capacity) {
                    break;
                }

                usleep(100);
                $spins++;
            }
        }

        // If still full after waiting, evict oldest to prevent deadlock
        if ($bufferCount >= $capacity) {
            if ($bufferCount > 0) {
                array_shift($buffer);
                $bufferCount--;
            }
        }

        $buffer[] = $value;
        $bufferCount++;
        return true;
    }

    /**
     * Execute completion callbacks
     */
    protected function executeOnCompletion(?Throwable $exception): void
    {
        foreach ($this->operators as $operator) {
            if ($operator[0] === self::OP_ON_COMPLETION) {
                $operator[1]($exception);
            }
        }
    }

    /**
     * Check if exception was handled by catch operator
     */
    protected function wasExceptionHandled(Throwable $exception): bool
    {
        foreach ($this->operators as $operator) {
            if ($operator[0] === self::OP_CATCH) {
                try {
                    $operator[1]($exception);
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
