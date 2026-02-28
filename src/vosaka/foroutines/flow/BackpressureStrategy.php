<?php

declare(strict_types=1);

namespace vosaka\foroutines\flow;

/**
 * BackpressureStrategy — Defines how hot flows (SharedFlow, StateFlow,
 * MutableStateFlow) handle the situation where emissions outpace collection.
 *
 * When a producer emits values faster than collectors can consume them, the
 * internal buffer fills up. The backpressure strategy determines what happens
 * next:
 *
 *   - SUSPEND:      The emitter cooperatively yields (via Fiber::suspend)
 *                    until buffer space becomes available. This is the safest
 *                    default — no data is lost, and other fibers can run while
 *                    the emitter waits. Analogous to Kotlin's BufferOverflow.SUSPEND.
 *
 *   - DROP_OLDEST:  The oldest value in the buffer is evicted to make room for
 *                    the new emission. Fast producers won't block, but slow
 *                    consumers may miss intermediate values. Analogous to
 *                    Kotlin's BufferOverflow.DROP_OLDEST.
 *
 *   - DROP_LATEST:  The newest emission is silently discarded when the buffer
 *                    is full. The buffer retains its current contents unchanged.
 *                    Useful when only the "most recent acknowledged" value
 *                    matters. Analogous to Kotlin's BufferOverflow.DROP_LATEST.
 *
 *   - ERROR:        A RuntimeException is thrown immediately when the buffer
 *                    overflows. Useful for debugging or when overflow is
 *                    considered a programming error that should be caught early.
 *
 * Usage:
 *
 *     // SharedFlow with bounded buffer and DROP_OLDEST backpressure
 *     $flow = SharedFlow::new(
 *         replay: 3,
 *         extraBufferCapacity: 10,
 *         onBufferOverflow: BackpressureStrategy::DROP_OLDEST,
 *     );
 *
 *     // MutableStateFlow always uses DROP_OLDEST by nature (it only
 *     // keeps the latest value), but you can configure the extra
 *     // emission buffer for slow collectors.
 *     $state = MutableStateFlow::new(
 *         initialValue: 0,
 *     );
 *
 * Buffer size semantics:
 *
 *   Total buffer capacity = replay + extraBufferCapacity
 *
 *   - replay:               Number of past values replayed to new collectors.
 *   - extraBufferCapacity:  Additional slots beyond replay that absorb bursts
 *                            before the backpressure strategy kicks in.
 *
 *   When the total buffer is full and a new value is emitted, the chosen
 *   BackpressureStrategy determines the outcome.
 */
enum BackpressureStrategy: string
{
    /**
     * Suspend the emitter fiber until buffer space becomes available.
     *
     * The emitter calls Fiber::suspend() and is resumed by the scheduler
     * once a collector has consumed at least one value, freeing buffer space.
     *
     * Guarantees: No data loss. All emitted values are eventually delivered.
     * Trade-off:  The emitter is blocked (cooperatively) and cannot proceed
     *             until a collector catches up. Risk of deadlock if no
     *             collector is active.
     *
     * This is the default strategy, matching Kotlin's default behavior.
     */
    case SUSPEND = 'suspend';

    /**
     * Drop the oldest value in the buffer to make room for the new emission.
     *
     * The buffer operates as a fixed-size ring buffer. When full, the head
     * (oldest entry) is evicted and the new value is appended at the tail.
     *
     * Guarantees: The emitter never blocks.
     * Trade-off:  Slow collectors lose intermediate values. The most recent
     *             N values (where N = buffer capacity) are always available.
     *
     * Best for: Real-time data streams (sensor readings, stock tickers)
     *           where only the latest window of values matters.
     */
    case DROP_OLDEST = 'drop_oldest';

    /**
     * Drop the latest (incoming) emission when the buffer is full.
     *
     * The new value is silently discarded. The buffer contents remain
     * unchanged. The emitter is NOT blocked and receives no error.
     *
     * Guarantees: The emitter never blocks. The buffer always contains
     *             the earliest N values until a collector drains them.
     * Trade-off:  New emissions are lost when the buffer is full.
     *
     * Best for: Scenarios where the "first seen" values are more important
     *           than the latest, or when the producer can safely retry.
     */
    case DROP_LATEST = 'drop_latest';

    /**
     * Throw a RuntimeException when the buffer overflows.
     *
     * This is a strict policy that treats buffer overflow as a programming
     * error. It forces the developer to either:
     *   - Increase the buffer capacity
     *   - Use a different strategy
     *   - Ensure collectors keep up with producers
     *
     * Guarantees: No silent data loss — overflow is always reported.
     * Trade-off:  The emitter must handle (or propagate) the exception.
     *
     * Best for: Development/testing, or production systems where overflow
     *           indicates a critical design flaw that must not go unnoticed.
     */
    case ERROR = 'error';

    /**
     * Returns a human-readable description of this strategy.
     */
    public function description(): string
    {
        return match ($this) {
            self::SUSPEND => 'Suspend the emitter until buffer space is available (no data loss)',
            self::DROP_OLDEST => 'Drop the oldest buffered value to make room (emitter never blocks)',
            self::DROP_LATEST => 'Discard the new emission when the buffer is full (emitter never blocks)',
            self::ERROR => 'Throw a RuntimeException on buffer overflow (strict, no silent loss)',
        };
    }

    /**
     * Returns true if this strategy may cause the emitting fiber to suspend.
     */
    public function maySuspend(): bool
    {
        return $this === self::SUSPEND;
    }

    /**
     * Returns true if this strategy may cause data loss.
     */
    public function mayLoseData(): bool
    {
        return $this === self::DROP_OLDEST || $this === self::DROP_LATEST;
    }

    /**
     * Returns true if this strategy may throw on overflow.
     */
    public function mayThrow(): bool
    {
        return $this === self::ERROR;
    }
}
