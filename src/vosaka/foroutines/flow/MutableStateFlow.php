<?php

declare(strict_types=1);

namespace vosaka\foroutines\flow;

use Throwable;

/**
 * MutableStateFlow — A mutable variant of StateFlow that exposes
 * setValue() / emit() / update() / compareAndSet() for external mutation.
 *
 * This class inherits all backpressure support from StateFlow:
 *   - Configurable extraBufferCapacity for slow collectors
 *   - Configurable BackpressureStrategy (SUSPEND, DROP_OLDEST, DROP_LATEST, ERROR)
 *
 * Usage:
 *
 *     // Simple — no backpressure (original behaviour)
 *     $state = MutableStateFlow::new(0);
 *     $state->emit(1);
 *     $state->emit(2);
 *
 *     // With backpressure — buffer up to 16 pending emissions
 *     $state = MutableStateFlow::new(
 *         initialValue: 0,
 *         extraBufferCapacity: 16,
 *         onBufferOverflow: BackpressureStrategy::DROP_OLDEST,
 *     );
 *
 *     $state->collect(fn($v) => var_dump("State: $v"));
 *     $state->emit(1);
 *     $state->emit(2);
 */
final class MutableStateFlow extends StateFlow
{
    /**
     * Create a new MutableStateFlow with the given initial value and
     * optional backpressure configuration.
     *
     * @param mixed $initialValue The initial value of the StateFlow.
     * @param int $extraBufferCapacity Additional buffer slots for slow collectors (≥ 0).
     *                                  0 = no buffering (original behaviour).
     * @param BackpressureStrategy $onBufferOverflow Strategy when the emission buffer is full.
     * @return MutableStateFlow
     */
    public static function new(
        mixed $initialValue,
        int $extraBufferCapacity = 0,
        BackpressureStrategy $onBufferOverflow = BackpressureStrategy::SUSPEND,
    ): static {
        return new self($initialValue, $extraBufferCapacity, $onBufferOverflow);
    }

    /**
     * Emit a new value to all collectors.
     *
     * This is an alias for setValue() that provides a more intuitive API
     * when thinking in terms of reactive streams / Flow emissions.
     *
     * If the new value is identical (===) to the current value, no emission
     * occurs (StateFlow conflates by default, matching Kotlin behaviour).
     *
     * If the emission buffer is full, the backpressure strategy determines
     * the outcome (see BackpressureStrategy for details).
     *
     * @param mixed $value The new value to emit.
     */
    public function emit(mixed $value): void
    {
        $this->setValue($value);
    }

    /**
     * Compare and set — atomically set the value only if the current value
     * matches the expected value (using strict === comparison).
     *
     * This is useful for lock-free optimistic updates:
     *
     *     do {
     *         $current = $state->getValue();
     *         $next = $current + 1;
     *     } while (!$state->compareAndSet($current, $next));
     *
     * If the current value does NOT match $expected, the state is unchanged
     * and no emission occurs.
     *
     * @param mixed $expected The expected current value.
     * @param mixed $newValue The new value to set if current === expected.
     * @return bool True if the value was updated, false if current !== expected.
     */
    public function compareAndSet(mixed $expected, mixed $newValue): bool
    {
        if ($this->getValue() === $expected) {
            $this->setValue($newValue);
            return true;
        }
        return false;
    }

    /**
     * Try to emit a value without suspending or throwing.
     *
     * Returns true if the value was successfully set and emitted.
     * Returns false if:
     *   - The emission buffer is full and the strategy would have caused
     *     a suspend or throw (SUSPEND / ERROR strategies).
     *   - The StateFlow is in an error state.
     *
     * For DROP_OLDEST and DROP_LATEST strategies, this method behaves the
     * same as emit() (since those strategies never block or throw).
     *
     * This is a non-blocking, non-throwing alternative to emit().
     *
     * @param mixed $value The value to emit.
     * @return bool True if emitted successfully, false otherwise.
     */
    public function tryEmit(mixed $value): bool
    {
        try {
            // If the value hasn't changed, setValue() is a no-op — still "success"
            if ($this->getValue() === $value) {
                return true;
            }

            // If no buffer configured or buffer has space, always succeeds
            if (!$this->isBufferFull()) {
                $this->setValue($value);
                return true;
            }

            // Buffer is full — behaviour depends on strategy
            $strategy = $this->getBackpressureStrategy();

            if ($strategy === BackpressureStrategy::DROP_OLDEST) {
                // DROP_OLDEST always accepts — setValue handles eviction
                $this->setValue($value);
                return true;
            }

            if ($strategy === BackpressureStrategy::DROP_LATEST) {
                // DROP_LATEST discards silently — still "success" from caller's perspective
                // The current value IS updated (StateFlow contract), but the emission
                // to slow collectors may be dropped.
                $this->setValue($value);
                return true;
            }

            // SUSPEND or ERROR — would block or throw, so return false
            return false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Get a read-only snapshot of this MutableStateFlow as a StateFlow.
     *
     * The returned StateFlow shares the same current value at the time of
     * the call but is a separate instance — subsequent mutations to the
     * MutableStateFlow will NOT be reflected in the snapshot.
     *
     * For a live read-only view, register a collector instead.
     *
     * @return StateFlow A read-only StateFlow with the current value.
     */
    public function asStateFlow(): StateFlow
    {
        return StateFlow::new(
            $this->getValue(),
            $this->getExtraBufferCapacity(),
            $this->getBackpressureStrategy(),
        );
    }
}
