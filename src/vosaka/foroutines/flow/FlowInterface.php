<?php

declare(strict_types=1);

namespace vosaka\foroutines\flow;

/**
 * Base interface for all Flow types.
 *
 * Defines the core operators available on every Flow implementation
 * (cold Flow, SharedFlow, StateFlow, MutableStateFlow).
 *
 * Operator categories:
 *   - Transformation: map, filter, onEach, flatMap (in BaseFlow)
 *   - Error handling: catch, onCompletion (in BaseFlow)
 *   - Terminal:       collect, first, firstOrNull, toArray, count, reduce (in BaseFlow)
 *   - Backpressure:   buffer — configures an intermediate buffer with a
 *                     BackpressureStrategy so that slow collectors don't
 *                     block fast producers in cold Flow pipelines.
 */
interface FlowInterface
{
    /**
     * Terminal operator — collect all emitted values.
     *
     * @param callable $collector Callback invoked with each emitted value.
     */
    public function collect(callable $collector): void;

    /**
     * Intermediate operator — transform each emitted value.
     *
     * @param callable $transform fn(mixed $value): mixed
     * @return FlowInterface A new Flow with the transformation applied.
     */
    public function map(callable $transform): FlowInterface;

    /**
     * Intermediate operator — filter emitted values.
     *
     * Values for which the predicate returns false are dropped.
     *
     * @param callable $predicate fn(mixed $value): bool
     * @return FlowInterface A new Flow with the filter applied.
     */
    public function filter(callable $predicate): FlowInterface;

    /**
     * Intermediate operator — perform a side-effect for each emitted value
     * without transforming it.
     *
     * @param callable $action fn(mixed $value): void
     * @return FlowInterface A new Flow with the side-effect applied.
     */
    public function onEach(callable $action): FlowInterface;

    /**
     * Intermediate operator — take only the first N emitted values.
     *
     * After N values have been emitted, the flow completes early.
     *
     * @param int $count Maximum number of values to emit.
     * @return FlowInterface A new Flow limited to $count values.
     */
    public function take(int $count): FlowInterface;

    /**
     * Intermediate operator — skip the first N emitted values.
     *
     * The first $count values are dropped; subsequent values pass through.
     *
     * @param int $count Number of values to skip.
     * @return FlowInterface A new Flow that skips the first $count values.
     */
    public function skip(int $count): FlowInterface;

    /**
     * Intermediate operator — transform each value to a Flow and flatten.
     *
     * Each emitted value is passed to $transform which should return a Flow.
     * The first value of each sub-flow is taken and emitted downstream.
     *
     * @param callable $transform fn(mixed $value): FlowInterface
     * @return FlowInterface A new Flow with flatMap applied.
     */
    public function flatMap(callable $transform): FlowInterface;

    /**
     * Intermediate operator — catch and handle upstream exceptions.
     *
     * If the handler does not re-throw, the exception is considered handled
     * and collection continues (or completes, depending on implementation).
     *
     * @param callable $handler fn(Throwable $e): void
     * @return FlowInterface A new Flow with the error handler applied.
     */
    public function catch(callable $handler): FlowInterface;

    /**
     * Intermediate operator — execute a callback when the flow completes,
     * either successfully or with an error.
     *
     * The callback receives the Throwable if the flow completed with an
     * error, or null if it completed normally.
     *
     * @param callable $action fn(?Throwable $exception): void
     * @return FlowInterface A new Flow with the completion handler applied.
     */
    public function onCompletion(callable $action): FlowInterface;

    /**
     * Terminal operator — collect and return the first emitted value.
     *
     * @return mixed The first emitted value.
     * @throws \RuntimeException If the flow is empty.
     */
    public function first(): mixed;

    /**
     * Terminal operator — collect and return the first emitted value, or null.
     *
     * @return mixed The first emitted value, or null if the flow is empty.
     */
    public function firstOrNull(): mixed;

    /**
     * Terminal operator — collect all emitted values into an array.
     *
     * @return array All emitted values.
     */
    public function toArray(): array;

    /**
     * Terminal operator — count the number of emitted values.
     *
     * @return int The number of values emitted by the flow.
     */
    public function count(): int;

    /**
     * Terminal operator — reduce the flow to a single value.
     *
     * @param mixed $initial The initial accumulator value.
     * @param callable $operation fn(mixed $accumulator, mixed $value): mixed
     * @return mixed The final accumulated value.
     */
    public function reduce(mixed $initial, callable $operation): mixed;

    /**
     * Intermediate operator — add an intermediate backpressure buffer
     * between the upstream producer and downstream collector.
     *
     * This is primarily useful for cold Flows where a fast producer emits
     * values faster than a slow collector can consume them. The buffer
     * absorbs bursts and applies the chosen BackpressureStrategy when full:
     *
     *   - SUSPEND:     The producer fiber yields until buffer space is
     *                  available (cooperative, no data loss).
     *   - DROP_OLDEST: The oldest buffered value is evicted to make room.
     *   - DROP_LATEST: The newest emission is silently discarded.
     *   - ERROR:       A RuntimeException is thrown on overflow.
     *
     * Usage:
     *
     *     Flow::of(1, 2, 3, 4, 5)
     *         ->map(fn($v) => $v * 10)
     *         ->buffer(
     *             capacity: 3,
     *             onOverflow: BackpressureStrategy::DROP_OLDEST,
     *         )
     *         ->collect(function ($v) {
     *             usleep(10000); // slow consumer
     *             echo $v . "\n";
     *         });
     *
     * When called without arguments, a default capacity of 64 and the
     * SUSPEND strategy are used.
     *
     * @param int $capacity Maximum number of values the buffer can hold (> 0).
     * @param BackpressureStrategy $onOverflow Strategy when the buffer is full.
     * @return FlowInterface A new Flow with the buffer operator applied.
     */
    public function buffer(
        int $capacity = 64,
        BackpressureStrategy $onOverflow = BackpressureStrategy::SUSPEND,
    ): FlowInterface;
}
