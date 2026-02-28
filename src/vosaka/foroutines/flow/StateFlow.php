<?php

declare(strict_types=1);

namespace vosaka\foroutines\flow;

use Fiber;
use RuntimeException;
use Throwable;
use vosaka\foroutines\Pause;

/**
 * StateFlow — A hot Flow that always holds a current value and emits
 * updates to registered collectors when the value changes.
 *
 * StateFlow now supports backpressure for slow collectors via a
 * configurable emission buffer. When collectors cannot keep up with
 * the rate of setValue() / emit() calls, the backpressure strategy
 * determines what happens:
 *
 *   - SUSPEND:     The emitter fiber yields until collectors catch up.
 *   - DROP_OLDEST: The oldest pending emission is evicted (ring buffer).
 *   - DROP_LATEST: The new emission is silently discarded.
 *   - ERROR:       A RuntimeException is thrown immediately.
 *
 * Buffer architecture:
 *
 *   StateFlow always maintains exactly ONE "current value" (the latest
 *   committed value). In addition, an optional emission buffer absorbs
 *   bursts when collectors are slow:
 *
 *   ┌──────────────────┬──────────────────────────────┐
 *   │  current value   │  emission buffer (N slots)   │
 *   │  (always 1)      │  (extraBufferCapacity)       │
 *   └──────────────────┴──────────────────────────────┘
 *         getValue()          absorbs bursts before
 *         returns this        backpressure kicks in
 *
 *   When extraBufferCapacity is 0 (default), every setValue() dispatches
 *   immediately to collectors (original behaviour, no backpressure).
 *
 * Usage:
 *
 *     // Simple — no backpressure (original behaviour)
 *     $state = StateFlow::new(0);
 *
 *     // With backpressure — buffer up to 16 pending emissions
 *     $state = StateFlow::new(
 *         initialValue: 0,
 *         extraBufferCapacity: 16,
 *         onBufferOverflow: BackpressureStrategy::DROP_OLDEST,
 *     );
 *
 *     $state->collect(function ($value) {
 *         // slow consumer — won't block the emitter
 *         usleep(10000);
 *         echo "Got: {$value}\n";
 *     });
 *
 *     $state->setValue(1);
 *     $state->setValue(2); // buffered if collector is slow
 */
class StateFlow extends BaseFlow
{
    /**
     * Registered collectors.
     *
     * @var array<string, array{
     *     callback: callable,
     *     emittedCount: int,
     *     skippedCount: int,
     *     operators: array,
     * }>
     */
    private array $collectors = [];

    /**
     * The current (latest committed) value.
     */
    private mixed $currentValue;

    /**
     * Whether the flow has been given an initial value.
     */
    private bool $hasValue = false;

    /**
     * Pending emission buffer for slow collectors.
     * @var array<int, mixed>
     */
    private array $emissionBuffer = [];

    /**
     * Number of values currently in the emission buffer.
     */
    private int $bufferedCount = 0;

    /**
     * Additional buffer slots that absorb emission bursts before the
     * backpressure strategy activates. 0 = no buffering (original behaviour).
     */
    private int $extraBufferCapacity;

    /**
     * Strategy applied when the emission buffer is full and a new value arrives.
     */
    private BackpressureStrategy $onBufferOverflow;

    /**
     * Fibers that are suspended waiting for buffer space (SUSPEND strategy).
     * Each entry is [fiber => Fiber, value => mixed].
     * @var array<int, array{fiber: Fiber, value: mixed}>
     */
    private array $suspendedEmitters = [];

    /**
     * Auto-increment key for suspended emitters.
     */
    private int $nextEmitterId = 0;

    // ─── Construction ────────────────────────────────────────────────

    /**
     * @param mixed $initialValue The initial value of the StateFlow.
     * @param int $extraBufferCapacity Additional buffer slots (≥ 0). 0 = no buffering.
     * @param BackpressureStrategy $onBufferOverflow Strategy when buffer is full.
     */
    protected function __construct(
        mixed $initialValue,
        int $extraBufferCapacity = 0,
        BackpressureStrategy $onBufferOverflow = BackpressureStrategy::SUSPEND,
    ) {
        if ($extraBufferCapacity < 0) {
            throw new RuntimeException("extraBufferCapacity must be >= 0");
        }

        $this->currentValue = $initialValue;
        $this->hasValue = true;
        $this->extraBufferCapacity = $extraBufferCapacity;
        $this->onBufferOverflow = $onBufferOverflow;
    }

    /**
     * Create a new StateFlow with the given initial value.
     *
     * @param mixed $initialValue The initial value.
     * @param int $extraBufferCapacity Additional buffer slots for slow collectors.
     * @param BackpressureStrategy $onBufferOverflow Strategy when buffer is full.
     * @return static
     */
    public static function new(
        mixed $initialValue,
        int $extraBufferCapacity = 0,
        BackpressureStrategy $onBufferOverflow = BackpressureStrategy::SUSPEND,
    ): static {
        return new static(
            $initialValue,
            $extraBufferCapacity,
            $onBufferOverflow,
        );
    }

    // ─── Value access ────────────────────────────────────────────────

    /**
     * Get the current (latest committed) value.
     *
     * @return mixed
     * @throws RuntimeException If the StateFlow has no value (should not happen
     *                          if constructed via new()).
     */
    public function getValue(): mixed
    {
        if (!$this->hasValue) {
            throw new RuntimeException("StateFlow has no value");
        }
        return $this->currentValue;
    }

    /**
     * Set a new value and emit to all collectors.
     *
     * If the new value is identical to the current value (===), no emission
     * occurs (conflation / distinctUntilChanged by default, matching Kotlin).
     *
     * If the emission buffer is full, the backpressure strategy determines
     * the outcome:
     *   - SUSPEND:     The emitter fiber yields until space is available.
     *   - DROP_OLDEST: The oldest buffered emission is evicted.
     *   - DROP_LATEST: This emission is silently discarded.
     *   - ERROR:       A RuntimeException is thrown.
     *
     * @param mixed $value The new value.
     */
    public function setValue(mixed $value): void
    {
        $oldValue = $this->currentValue;
        $this->currentValue = $value;
        $this->hasValue = true;

        // StateFlow conflates by default — only emit on actual change
        if ($oldValue === $value) {
            return;
        }

        // If no buffering configured, dispatch immediately (original behaviour)
        if ($this->extraBufferCapacity === 0) {
            $this->emitToCollectors($value);
            return;
        }

        // ── Backpressure handling ────────────────────────────────
        if ($this->isBufferFull()) {
            switch ($this->onBufferOverflow) {
                case BackpressureStrategy::SUSPEND:
                    $this->suspendUntilSpace($value);
                    return;

                case BackpressureStrategy::DROP_OLDEST:
                    $this->evictOldest();
                    break; // fall through to buffering below

                case BackpressureStrategy::DROP_LATEST:
                    // Silently discard the new emission
                    return;

                case BackpressureStrategy::ERROR:
                    throw new RuntimeException(
                        "StateFlow emission buffer overflow: buffer is full " .
                            "(capacity={$this->extraBufferCapacity}, " .
                            "strategy={$this->onBufferOverflow->value}). " .
                            "Consider increasing extraBufferCapacity or using a " .
                            "different BackpressureStrategy.",
                    );
            }
        }

        // Buffer the value
        $this->bufferValue($value);

        // Dispatch to collectors
        $this->emitToCollectors($value);
    }

    /**
     * Update the value using a transformation function.
     *
     * The updater receives the current value and must return the new value.
     * Equivalent to: setValue(updater(getValue()))
     *
     * @param callable $updater fn(mixed $currentValue): mixed
     */
    public function update(callable $updater): void
    {
        $newValue = $updater($this->currentValue);
        $this->setValue($newValue);
    }

    // ─── Collect ─────────────────────────────────────────────────────

    /**
     * Register a collector to receive state changes.
     *
     * The collector immediately receives the current value (if the StateFlow
     * has one), then continues to receive new values as they are set.
     *
     * Registering a collector also wakes any suspended emitters, since the
     * new collector may help drain the emission buffer.
     *
     * @param callable $collector Callback invoked with each state value.
     */
    public function collect(callable $collector): void
    {
        $collectorKey = uniqid("sc_", true);
        $emittedCount = 0;
        $skippedCount = 0;

        // Store collector
        $this->collectors[$collectorKey] = [
            "callback" => $collector,
            "emittedCount" => 0,
            "skippedCount" => 0,
            "operators" => $this->operators,
        ];

        // Immediately emit current value
        if ($this->hasValue) {
            try {
                $processedValue = $this->applyOperators(
                    $this->currentValue,
                    $emittedCount,
                    $skippedCount,
                );
                if ($processedValue !== null) {
                    $collector($processedValue);
                    $emittedCount++;
                }
            } catch (Throwable $e) {
                unset($this->collectors[$collectorKey]);
                throw $e;
            }
        }

        $this->collectors[$collectorKey]["emittedCount"] = $emittedCount;
        $this->collectors[$collectorKey]["skippedCount"] = $skippedCount;

        // New collector may help drain emission buffer — wake suspended emitters
        $this->resumeSuspendedEmitters();
    }

    /**
     * Collect only distinct values (skip if same as previous).
     *
     * Note: StateFlow already conflates by default (setValue only emits on
     * change). This operator adds an additional distinctUntilChanged filter
     * at the collector level with a custom comparator.
     *
     * @param callable|null $compareFunction fn(mixed $a, mixed $b): bool
     *                                       Returns true if values are "same".
     * @return static
     */
    public function distinctUntilChanged(
        ?callable $compareFunction = null,
    ): static {
        $newFlow = clone $this;
        $newFlow->operators[] = [
            "type" => "distinctUntilChanged",
            "compare" => $compareFunction ?? fn($a, $b) => $a === $b,
        ];
        return $newFlow;
    }

    // ─── Configuration accessors ─────────────────────────────────────

    /**
     * Get the extra buffer capacity.
     */
    public function getExtraBufferCapacity(): int
    {
        return $this->extraBufferCapacity;
    }

    /**
     * Get the current backpressure strategy.
     */
    public function getBackpressureStrategy(): BackpressureStrategy
    {
        return $this->onBufferOverflow;
    }

    /**
     * Get the number of values currently in the emission buffer.
     */
    public function getBufferedCount(): int
    {
        return $this->bufferedCount;
    }

    /**
     * Check whether the emission buffer is full.
     * Always returns false when extraBufferCapacity is 0 (no buffering).
     */
    public function isBufferFull(): bool
    {
        if ($this->extraBufferCapacity === 0) {
            return false;
        }
        return $this->bufferedCount >= $this->extraBufferCapacity;
    }

    /**
     * Get the number of emitters currently suspended waiting for buffer space.
     */
    public function getSuspendedEmitterCount(): int
    {
        return count($this->suspendedEmitters);
    }

    /**
     * Get current number of collectors.
     */
    public function getCollectorCount(): int
    {
        return count($this->collectors);
    }

    /**
     * Remove a collector by its key.
     */
    public function removeCollector(string $collectorKey): void
    {
        unset($this->collectors[$collectorKey]);
    }

    /**
     * Check if StateFlow has any collectors.
     */
    public function hasCollectors(): bool
    {
        return !empty($this->collectors);
    }

    // ─── Internals: buffer management ────────────────────────────────

    /**
     * Add a value to the emission buffer.
     */
    private function bufferValue(mixed $value): void
    {
        $this->emissionBuffer[] = $value;
        $this->bufferedCount++;
    }

    /**
     * Evict the oldest value from the emission buffer (DROP_OLDEST).
     */
    private function evictOldest(): void
    {
        if ($this->bufferedCount > 0) {
            array_shift($this->emissionBuffer);
            $this->bufferedCount--;
        }
    }

    /**
     * Called after collectors successfully consume a value.
     * Wakes suspended emitters if buffer space became available.
     */
    private function onCollectorConsumed(): void
    {
        if (!empty($this->suspendedEmitters)) {
            $this->resumeSuspendedEmitters();
        }
    }

    // ─── Internals: dispatch ─────────────────────────────────────────

    /**
     * Dispatch a value to all active collectors.
     */
    private function emitToCollectors(mixed $value): void
    {
        foreach ($this->collectors as $key => &$collectorInfo) {
            try {
                $processedValue = $this->applyOperatorsForCollector(
                    $value,
                    $collectorInfo,
                );
                if ($processedValue !== null) {
                    $collectorInfo["callback"]($processedValue);
                    $collectorInfo["emittedCount"]++;
                }
            } catch (Throwable) {
                // Remove failed collector
                unset($this->collectors[$key]);
            }
        }
        unset($collectorInfo);

        // Collectors consumed — check if we can wake suspended emitters
        $this->onCollectorConsumed();
    }

    /**
     * Apply operators specific to a collector's configuration.
     */
    private function applyOperatorsForCollector(
        mixed $value,
        array &$collectorInfo,
    ): mixed {
        return $this->applyOperators(
            $value,
            $collectorInfo["emittedCount"],
            $collectorInfo["skippedCount"],
        );
    }

    // ─── Internals: SUSPEND backpressure ─────────────────────────────

    /**
     * Suspend the current emitter fiber until buffer space is available.
     *
     * When resumed, the value is buffered and dispatched.
     *
     * If not inside a Fiber, falls back to a spin-loop with a small real-time
     * sleep to avoid hot spin, eventually falling back to DROP_OLDEST to
     * prevent deadlock.
     *
     * @param mixed $value The value waiting to be emitted.
     */
    private function suspendUntilSpace(mixed $value): void
    {
        $fiber = Fiber::getCurrent();

        if ($fiber !== null) {
            // Inside a Fiber — register and suspend
            $emitterId = $this->nextEmitterId++;
            $this->suspendedEmitters[$emitterId] = [
                "fiber" => $fiber,
                "value" => $value,
            ];

            // Suspend — we'll be resumed by resumeSuspendedEmitters()
            // when a collector consumes and frees buffer space.
            // Returns true if the value was accepted, false if the flow
            // was completed while we were waiting.
            Fiber::suspend();
            return;
        }

        // Not inside a Fiber — spin-wait with cooperative scheduling.
        $maxSpins = 10000;
        $spins = 0;

        while ($this->isBufferFull() && $spins < $maxSpins) {
            Pause::new(); // no-op outside Fiber, documents intent
            usleep(100); // small real-time sleep to avoid hot spin
            $spins++;
        }

        if ($this->isBufferFull()) {
            // Timed out — fall back to DROP_OLDEST to avoid deadlock
            $this->evictOldest();
        }

        $this->bufferValue($value);
        $this->emitToCollectors($value);
    }

    /**
     * Resume suspended emitters if buffer space is now available.
     *
     * For each suspended emitter (in FIFO order), if the buffer has space,
     * we buffer their value, dispatch it to collectors, and resume their fiber.
     */
    private function resumeSuspendedEmitters(): void
    {
        foreach ($this->suspendedEmitters as $id => $entry) {
            if ($this->isBufferFull()) {
                break; // No more space — remaining emitters stay suspended
            }

            $fiber = $entry["fiber"];
            $value = $entry["value"];

            // Remove from waiting list BEFORE resuming to prevent re-entrancy
            unset($this->suspendedEmitters[$id]);

            // Buffer and dispatch the waiting value
            $this->bufferValue($value);
            $this->emitToCollectors($value);

            // Resume the emitter fiber — signal success
            if ($fiber->isSuspended()) {
                $fiber->resume(true);
            }
        }
    }

    // ─── Cloning ─────────────────────────────────────────────────────

    public function __clone()
    {
        parent::__clone();
        $this->collectors = [];
        $this->suspendedEmitters = [];
        $this->emissionBuffer = [];
        $this->bufferedCount = 0;
        // currentValue and hasValue are preserved in the clone
    }
}
