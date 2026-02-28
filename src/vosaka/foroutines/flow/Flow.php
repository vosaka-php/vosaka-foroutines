<?php

declare(strict_types=1);

namespace vosaka\foroutines\flow;

use Generator;
use Fiber;
use RuntimeException;
use Throwable;
use vosaka\foroutines\Async;
use vosaka\foroutines\FiberUtils;
use vosaka\foroutines\Pause;

/**
 * Cold Flow — Creates a new stream for each collector.
 *
 * Each call to collect() starts a fresh execution of the source callable,
 * and emitted values are delivered to the collector through the operator
 * pipeline (map, filter, take, skip, buffer, etc.).
 *
 * Buffer operator support:
 *
 *   When a buffer() operator is present in the pipeline, collect() inserts
 *   an intermediate ring buffer between the upstream producer (the source
 *   fiber) and the downstream collector callback. Values emitted by the
 *   source are first processed through all non-buffer operators, then
 *   placed into the buffer. When the buffer is full, the configured
 *   BackpressureStrategy determines the outcome:
 *
 *     - SUSPEND:     The source fiber yields until the collector drains
 *                    at least one value, freeing buffer space.
 *     - DROP_OLDEST: The oldest buffered value is evicted (ring buffer).
 *     - DROP_LATEST: The incoming emission is silently discarded.
 *     - ERROR:       A RuntimeException is thrown immediately.
 *
 *   This allows fast-producing cold flows to cooperate with slow collectors
 *   without losing data (SUSPEND) or gracefully degrading (DROP_*).
 *
 * Usage:
 *
 *     // Basic cold flow
 *     Flow::of(1, 2, 3, 4, 5)
 *         ->filter(fn($v) => $v % 2 === 0)
 *         ->map(fn($v) => $v * 10)
 *         ->collect(fn($v) => var_dump($v)); // 20, 40
 *
 *     // With backpressure buffer
 *     Flow::of(1, 2, 3, 4, 5)
 *         ->map(fn($v) => $v * 10)
 *         ->buffer(capacity: 3, onOverflow: BackpressureStrategy::DROP_OLDEST)
 *         ->collect(function ($v) {
 *             usleep(10000); // slow consumer
 *             echo $v . "\n";
 *         });
 *
 *     // Custom emitter
 *     Flow::new(function () {
 *         for ($i = 0; $i < 100; $i++) {
 *             Flow::emit($i);
 *         }
 *     })->buffer(16, BackpressureStrategy::SUSPEND)
 *       ->collect(fn($v) => process($v));
 */
final class Flow extends BaseFlow
{
    private ?Fiber $fiber = null;

    /**
     * The source callable/generator/async/fiber that produces values.
     * Uses `mixed` because `callable` cannot appear in a union type
     * for typed properties in PHP 8.1+.
     */
    private readonly mixed $source;

    private function __construct(callable|Generator|Async|Fiber $source)
    {
        $this->source = $source;
    }

    /**
     * Create a new Flow from a callable, Generator, Async, or Fiber.
     *
     * The callable should use Flow::emit() to produce values. Generators
     * and Fibers are also supported as source types.
     *
     * @param callable|Generator|Async|Fiber $source The value source.
     * @return Flow
     */
    public static function new(callable|Generator|Async|Fiber $source): Flow
    {
        return new self($source);
    }

    /**
     * Create a Flow that emits a fixed sequence of values.
     *
     * @param mixed ...$values The values to emit.
     * @return Flow
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
     * Create an empty Flow (no emissions).
     *
     * @return Flow
     */
    public static function empty(): Flow
    {
        return self::new(function () {
            // Empty flow — no emissions
        });
    }

    /**
     * Create a Flow from an array.
     *
     * @param array $array The array of values to emit.
     * @return Flow
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
     * Emit a value in the current Flow context.
     *
     * This method can only be called from within a Fiber that is executing
     * a Flow's source callable. It suspends the fiber with the given value,
     * which is then picked up by collect().
     *
     * @param mixed $value The value to emit.
     * @throws RuntimeException If called outside a Flow/Fiber context.
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
     * Collect all emitted values, applying the full operator pipeline.
     *
     * When a buffer() operator is present in the pipeline, an intermediate
     * ring buffer is inserted between the source fiber and the downstream
     * collector. This enables backpressure handling for slow consumers.
     *
     * Without a buffer operator, values flow directly from the source fiber
     * through the operator pipeline to the collector (original behaviour).
     *
     * @param callable $collector Callback invoked with each processed value.
     */
    public function collect(callable $collector): void
    {
        if ($this->isCompleted) {
            return;
        }

        // Check if the pipeline includes a buffer() operator
        $bufferOp = $this->getBufferOperator();

        if ($bufferOp !== null) {
            $this->collectWithBuffer($collector, $bufferOp);
        } else {
            $this->collectDirect($collector);
        }
    }

    /**
     * Direct collection — no intermediate buffer.
     *
     * Values flow from the source fiber through the operator pipeline
     * directly to the collector. This is the original collect() behaviour
     * and is used when no buffer() operator is in the pipeline.
     *
     * @param callable $collector The downstream collector callback.
     */
    private function collectDirect(callable $collector): void
    {
        try {
            $this->fiber = FiberUtils::makeFiber($this->source);

            $emittedCount = 0;
            $skippedCount = 0;

            // start() runs the fiber until its first Fiber::suspend($value).
            // The return value of start() IS that first suspended value.
            // We must process it — otherwise the first emission is lost.
            if (!$this->fiber->isStarted()) {
                $firstValue = $this->fiber->start();

                // If the fiber suspended (emitted a value), process it
                if ($this->fiber->isSuspended()) {
                    $processedValue = $this->applyOperators(
                        $firstValue,
                        $emittedCount,
                        $skippedCount,
                    );

                    if ($processedValue !== null) {
                        $collector($processedValue);
                        $emittedCount++;
                    }
                }
            }

            while (!$this->fiber->isTerminated()) {
                if ($this->fiber->isSuspended()) {
                    $value = $this->fiber->resume();

                    // After resume(), the fiber may have terminated (returned)
                    // instead of suspending again. In that case, resume()
                    // returns null (not a real emitted value) and we must NOT
                    // feed it through the operator pipeline.
                    if ($this->fiber->isTerminated()) {
                        break;
                    }

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

    /**
     * Buffered collection — uses an intermediate ring buffer with backpressure.
     *
     * Architecture:
     *
     *   Source Fiber  ──emit──>  Operator Pipeline  ──>  Buffer  ──>  Collector
     *                                                     ↑
     *                                              BackpressureStrategy
     *                                              applied here when full
     *
     * The buffer sits between the operator pipeline output and the collector.
     * Values emitted by the source are first processed through all non-buffer
     * operators (map, filter, take, skip, etc.), then offered to the buffer.
     *
     * When the buffer is full, the BackpressureStrategy determines the outcome:
     *
     *   - SUSPEND:     The source fiber cooperatively yields (via Pause::new)
     *                  while the collector is given a chance to drain buffered
     *                  values. This is cooperative — it requires the collector
     *                  to run in a context where the scheduler can round-robin
     *                  (e.g. inside Launch/Thread::wait).
     *
     *   - DROP_OLDEST: The oldest value in the buffer is evicted and the new
     *                  value is appended. The source fiber never blocks.
     *
     *   - DROP_LATEST: The new value is silently discarded. The buffer and
     *                  source fiber are unaffected.
     *
     *   - ERROR:       A RuntimeException is thrown, terminating the flow.
     *
     * After the source fiber terminates, any remaining buffered values are
     * drained to the collector in FIFO order.
     *
     * @param callable $collector The downstream collector callback.
     * @param array $bufferOp The buffer operator configuration from the pipeline.
     */
    private function collectWithBuffer(
        callable $collector,
        array $bufferOp,
    ): void {
        $capacity = $bufferOp["capacity"];
        $strategy = $bufferOp["strategy"];

        /** @var array<int, mixed> $buffer Ring buffer */
        $buffer = [];
        $bufferCount = 0;

        try {
            $this->fiber = FiberUtils::makeFiber($this->source);

            $emittedCount = 0;
            $skippedCount = 0;

            // start() runs the fiber until its first Fiber::suspend($value).
            // The return value of start() IS that first suspended value.
            // We must process it — otherwise the first emission is lost.
            if (!$this->fiber->isStarted()) {
                $firstValue = $this->fiber->start();

                // If the fiber suspended (emitted a value), process it
                if ($this->fiber->isSuspended()) {
                    $processedValue = $this->applyOperators(
                        $firstValue,
                        $emittedCount,
                        $skippedCount,
                    );

                    if ($processedValue !== null && !$this->isCompleted) {
                        $accepted = $this->offerToBuffer(
                            $processedValue,
                            $buffer,
                            $bufferCount,
                            $capacity,
                            $strategy,
                            $collector,
                        );

                        if ($accepted) {
                            $emittedCount++;
                        }
                    }
                }
            }

            while (!$this->fiber->isTerminated()) {
                if (!$this->fiber->isSuspended()) {
                    break;
                }

                $value = $this->fiber->resume();

                // After resume(), the fiber may have terminated (returned)
                // instead of suspending again. In that case, resume()
                // returns null (not a real emitted value) and we must NOT
                // feed it through the operator pipeline.
                if ($this->fiber->isTerminated()) {
                    break;
                }

                // Apply all non-buffer operators first
                $processedValue = $this->applyOperators(
                    $value,
                    $emittedCount,
                    $skippedCount,
                );

                if ($this->isCompleted) {
                    break;
                }

                if ($processedValue === null) {
                    // Value was filtered out or take/skip suppressed it
                    continue;
                }

                // Offer the processed value to the backpressure buffer.
                // This may block (SUSPEND), evict (DROP_OLDEST), discard
                // (DROP_LATEST), or throw (ERROR) depending on the strategy.
                $accepted = $this->offerToBuffer(
                    $processedValue,
                    $buffer,
                    $bufferCount,
                    $capacity,
                    $strategy,
                    $collector,
                );

                if ($accepted) {
                    $emittedCount++;
                }
            }

            // Drain any remaining buffered values to the collector
            $this->flushBuffer($buffer, $bufferCount, $collector);

            $this->isCompleted = true;
            $this->executeOnCompletion(null);
        } catch (Throwable $e) {
            // On error, still try to drain the buffer so partial results
            // are delivered before the exception propagates.
            if ($bufferCount > 0) {
                try {
                    $this->flushBuffer($buffer, $bufferCount, $collector);
                } catch (Throwable) {
                    // Ignore errors during error-path drain
                }
            }

            $this->exception = $e;
            $this->isCompleted = true;
            $this->executeOnCompletion($e);

            if (!$this->wasExceptionHandled($e)) {
                throw $e;
            }
        }
    }

    /**
     * Offer a value to the backpressure buffer.
     *
     * If the buffer has space, the value is simply appended.
     * If the buffer is full, the BackpressureStrategy determines the outcome.
     *
     * Before applying backpressure, this method attempts to drain buffered
     * values to the collector — this frees space without needing to drop
     * data or suspend.
     *
     * @param mixed $value The value to buffer.
     * @param array &$buffer The ring buffer (passed by reference).
     * @param int &$bufferCount Current number of items in the buffer.
     * @param int $capacity Maximum buffer size.
     * @param BackpressureStrategy $strategy Overflow strategy.
     * @param callable $collector The downstream collector callback.
     * @return bool True if the value was accepted (buffered or delivered),
     *              false if it was dropped (DROP_LATEST when full).
     * @throws RuntimeException When strategy is ERROR and buffer is full.
     */
    private function offerToBuffer(
        mixed $value,
        array &$buffer,
        int &$bufferCount,
        int $capacity,
        BackpressureStrategy $strategy,
        callable $collector,
    ): bool {
        // Attempt to drain buffered values to the collector first.
        // This may free space without needing backpressure at all.
        $this->flushBuffer($buffer, $bufferCount, $collector);

        // If there's space now, just append
        if ($bufferCount < $capacity) {
            $buffer[] = $value;
            $bufferCount++;
            return true;
        }

        // Buffer is full — apply the backpressure strategy
        switch ($strategy) {
            case BackpressureStrategy::SUSPEND:
                return $this->offerWithSuspend(
                    $value,
                    $buffer,
                    $bufferCount,
                    $capacity,
                    $collector,
                );

            case BackpressureStrategy::DROP_OLDEST:
                // Evict the oldest value, then append the new one
                if ($bufferCount > 0) {
                    array_shift($buffer);
                    $bufferCount--;
                }
                $buffer[] = $value;
                $bufferCount++;
                return true;

            case BackpressureStrategy::DROP_LATEST:
                // Silently discard the incoming value
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
     * SUSPEND strategy: cooperatively yield until buffer space is available.
     *
     * Each iteration:
     *   1. Try to drain buffered values to the collector (frees space).
     *   2. If space is available, buffer the value and return.
     *   3. If still full, yield via Pause::new() so the scheduler can
     *      run collector fibers or other work.
     *   4. If not inside a Fiber, use usleep() to give the OS scheduler
     *      time to advance.
     *
     * After a maximum number of yields without progress, falls back to
     * DROP_OLDEST to prevent infinite deadlock (e.g. when the collector
     * runs synchronously in the same fiber).
     *
     * @param mixed $value The value waiting to be buffered.
     * @param array &$buffer The ring buffer.
     * @param int &$bufferCount Current buffer count.
     * @param int $capacity Maximum buffer size.
     * @param callable $collector The downstream collector callback.
     * @return bool Always true (the value is eventually accepted).
     */
    private function offerWithSuspend(
        mixed $value,
        array &$buffer,
        int &$bufferCount,
        int $capacity,
        callable $collector,
    ): bool {
        $maxYields = 100000;
        $yields = 0;
        $insideFiber = Fiber::getCurrent() !== null;

        while ($bufferCount >= $capacity && $yields < $maxYields) {
            // Try to drain
            $this->flushBuffer($buffer, $bufferCount, $collector);

            if ($bufferCount < $capacity) {
                break; // Space freed by draining
            }

            // Yield to the scheduler
            if ($insideFiber) {
                Pause::new();
            } else {
                // Outside Fiber — small sleep to avoid hot CPU spin
                usleep(100);
            }

            $yields++;
        }

        // If still full after all yields, fall back to DROP_OLDEST
        // to prevent infinite deadlock.
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
     * Drain (flush) all buffered values to the collector in FIFO order.
     *
     * This is called:
     *   - Before each buffer-offer attempt (to free space proactively)
     *   - After the source fiber terminates (to deliver remaining values)
     *   - During error handling (best-effort partial result delivery)
     *
     * @param array &$buffer The buffer to drain.
     * @param int &$bufferCount Current buffer size (updated in place).
     * @param callable $collector The downstream collector callback.
     */
    private function flushBuffer(
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

    public function __clone()
    {
        parent::__clone();
        $this->fiber = null;
    }
}
