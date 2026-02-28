<?php

declare(strict_types=1);

namespace vosaka\foroutines\flow;

use Fiber;
use RuntimeException;
use Throwable;
use vosaka\foroutines\Pause;

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
        $newFlow->operators[] = ["type" => "map", "callback" => $transform];
        return $newFlow;
    }

    /**
     * Filter emitted values
     */
    public function filter(callable $predicate): FlowInterface
    {
        $newFlow = clone $this;
        $newFlow->operators[] = ["type" => "filter", "callback" => $predicate];
        return $newFlow;
    }

    /**
     * Take only the first n values
     */
    public function take(int $count): FlowInterface
    {
        $newFlow = clone $this;
        $newFlow->operators[] = ["type" => "take", "count" => $count];
        return $newFlow;
    }

    /**
     * Skip the first n values
     */
    public function skip(int $count): FlowInterface
    {
        $newFlow = clone $this;
        $newFlow->operators[] = ["type" => "skip", "count" => $count];
        return $newFlow;
    }

    /**
     * Transform each value to a Flow and flatten the result
     */
    public function flatMap(callable $transform): FlowInterface
    {
        $newFlow = clone $this;
        $newFlow->operators[] = ["type" => "flatMap", "callback" => $transform];
        return $newFlow;
    }

    /**
     * Perform an action for each emitted value without transforming it
     */
    public function onEach(callable $action): FlowInterface
    {
        $newFlow = clone $this;
        $newFlow->operators[] = ["type" => "onEach", "callback" => $action];
        return $newFlow;
    }

    /**
     * Catch exceptions and handle them
     */
    public function catch(callable $handler): FlowInterface
    {
        $newFlow = clone $this;
        $newFlow->operators[] = ["type" => "catch", "callback" => $handler];
        return $newFlow;
    }

    /**
     * Execute when the flow completes (successfully or with error)
     */
    public function onCompletion(callable $action): FlowInterface
    {
        $newFlow = clone $this;
        $newFlow->operators[] = [
            "type" => "onCompletion",
            "callback" => $action,
        ];
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
        $newFlow->operators[] = [
            "type" => "buffer",
            "capacity" => $capacity,
            "strategy" => $onOverflow,
        ];
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
     * Buffer operators are skipped here — they are handled separately by
     * the collect() implementation via applyBufferOperator() so that the
     * buffer can accumulate values and apply backpressure across multiple
     * emissions (not just a single value pass-through).
     */
    protected function applyOperators(
        mixed $value,
        int &$emittedCount,
        int &$skippedCount,
    ): mixed {
        $currentValue = $value;

        foreach ($this->operators as $operator) {
            switch ($operator["type"]) {
                case "map":
                    $currentValue = $operator["callback"]($currentValue);
                    break;

                case "filter":
                    if (!$operator["callback"]($currentValue)) {
                        return null; // Filter out this value
                    }
                    break;

                case "take":
                    if ($emittedCount >= $operator["count"]) {
                        $this->isCompleted = true;
                        return null;
                    }
                    break;

                case "skip":
                    if ($skippedCount < $operator["count"]) {
                        $skippedCount++;
                        return null;
                    }
                    break;

                case "onEach":
                    $operator["callback"]($currentValue);
                    break;

                case "flatMap":
                    $subFlow = $operator["callback"]($currentValue);
                    if ($subFlow instanceof BaseFlow) {
                        $currentValue = $subFlow->firstOrNull();
                    }
                    break;

                case "buffer":
                    // Buffer operators are handled at the collect() level,
                    // not per-value. Skip here — see hasBufferOperator()
                    // and applyBufferOperator().
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
            if ($op["type"] === "buffer") {
                return true;
            }
        }
        return false;
    }

    /**
     * Get the first buffer operator configuration from the pipeline.
     *
     * @return array{capacity: int, strategy: BackpressureStrategy}|null
     */
    protected function getBufferOperator(): ?array
    {
        foreach ($this->operators as $op) {
            if ($op["type"] === "buffer") {
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
                Pause::new();
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
            if ($operator["type"] === "onCompletion") {
                $operator["callback"]($exception);
            }
        }
    }

    /**
     * Check if exception was handled by catch operator
     */
    protected function wasExceptionHandled(Throwable $exception): bool
    {
        foreach ($this->operators as $operator) {
            if ($operator["type"] === "catch") {
                try {
                    $operator["callback"]($exception);
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
