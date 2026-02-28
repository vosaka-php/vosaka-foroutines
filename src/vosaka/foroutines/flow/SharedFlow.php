<?php

declare(strict_types=1);

namespace vosaka\foroutines\flow;

use Fiber;
use RuntimeException;
use Throwable;
use vosaka\foroutines\Pause;

/**
 * Hot Flow that shares emissions among multiple collectors.
 *
 * SharedFlow now supports backpressure via a configurable internal buffer
 * composed of two regions:
 *
 *   ┌────────────────────────┬──────────────────────────┐
 *   │   replay (N slots)     │  extraBuffer (M slots)   │
 *   └────────────────────────┴──────────────────────────┘
 *         replayed to new            absorbs bursts
 *         collectors                 before backpressure
 *
 *   Total buffer capacity = replay + extraBufferCapacity
 *
 * When the total buffer is full and a new value is emitted, the chosen
 * BackpressureStrategy determines the outcome:
 *
 *   - SUSPEND:     The emitter fiber yields (Fiber::suspend) until a
 *                  collector consumes at least one value, freeing space.
 *   - DROP_OLDEST: The oldest buffered value is evicted (ring buffer).
 *   - DROP_LATEST: The incoming emission is silently discarded.
 *   - ERROR:       A RuntimeException is thrown immediately.
 *
 * When no extraBufferCapacity is set and replay is 0, the flow is
 * effectively unbounded (matching the original behaviour).
 */
final class SharedFlow extends BaseFlow
{
    /**
     * @var array<string, array{
     *     callback: callable,
     *     emittedCount: int,
     *     skippedCount: int,
     *     operators: array,
     *     consumedIndex: int,
     * }>
     */
    private array $collectors = [];

    /**
     * Ring buffer of emitted values.
     * @var array<int, mixed>
     */
    private array $buffer = [];

    /**
     * Monotonically increasing index of the next write position.
     * The actual buffer slot is $writeIndex % $totalCapacity (when bounded).
     */
    private int $writeIndex = 0;

    /**
     * Number of values currently in the buffer (≤ totalCapacity when bounded).
     */
    private int $bufferedCount = 0;

    /**
     * Total buffer capacity (replay + extraBufferCapacity).
     * This controls the maximum number of values kept in the buffer.
     * Backpressure is only triggered when extraBufferCapacity > 0 and
     * the buffer exceeds replay + extraBufferCapacity.
     */
    private int $totalCapacity;

    /**
     * Number of past values replayed to new collectors.
     */
    private int $replay;

    /**
     * Additional buffer slots beyond replay that absorb emission bursts
     * before the backpressure strategy activates.
     */
    private int $extraBufferCapacity;

    /**
     * Strategy applied when the buffer is full and a new value is emitted.
     */
    private BackpressureStrategy $onBufferOverflow;

    /**
     * Whether the flow is still accepting emissions.
     */
    private bool $isActive = true;

    /**
     * Fibers that are suspended waiting for buffer space (SUSPEND strategy).
     * Each entry is [Fiber, mixed value].
     * @var array<int, array{fiber: Fiber, value: mixed}>
     */
    private array $suspendedEmitters = [];

    /**
     * Auto-increment key for suspended emitters.
     */
    private int $nextEmitterId = 0;

    // ─── Construction ────────────────────────────────────────────────

    /**
     * @param int $replay Number of past values replayed to new collectors (≥ 0).
     * @param int $extraBufferCapacity Additional buffer slots beyond replay (≥ 0).
     * @param BackpressureStrategy $onBufferOverflow Strategy when buffer is full.
     */
    private function __construct(
        int $replay = 0,
        int $extraBufferCapacity = 0,
        BackpressureStrategy $onBufferOverflow = BackpressureStrategy::SUSPEND,
    ) {
        if ($replay < 0) {
            throw new RuntimeException("replay must be >= 0");
        }
        if ($extraBufferCapacity < 0) {
            throw new RuntimeException("extraBufferCapacity must be >= 0");
        }

        $this->replay = $replay;
        $this->extraBufferCapacity = $extraBufferCapacity;
        $this->onBufferOverflow = $onBufferOverflow;

        // Total buffer capacity = replay + extraBufferCapacity.
        // However, backpressure is ONLY triggered by extraBufferCapacity.
        // The replay region always operates as a self-managing ring buffer
        // (auto-evicts oldest), matching Kotlin SharedFlow semantics.
        $this->totalCapacity = $replay + $extraBufferCapacity;
    }

    /**
     * Create a new SharedFlow.
     *
     * @param int $replay Number of past values replayed to new collectors.
     * @param int $extraBufferCapacity Additional buffer slots beyond replay.
     * @param BackpressureStrategy $onBufferOverflow Strategy when buffer is full.
     * @return SharedFlow
     */
    public static function new(
        int $replay = 0,
        int $extraBufferCapacity = 0,
        BackpressureStrategy $onBufferOverflow = BackpressureStrategy::SUSPEND,
    ): SharedFlow {
        return new self($replay, $extraBufferCapacity, $onBufferOverflow);
    }

    // ─── Configuration accessors ─────────────────────────────────────

    /**
     * Get the replay count.
     */
    public function getReplay(): int
    {
        return $this->replay;
    }

    /**
     * Get the extra buffer capacity.
     */
    public function getExtraBufferCapacity(): int
    {
        return $this->extraBufferCapacity;
    }

    /**
     * Get the total buffer capacity (replay + extraBufferCapacity).
     * 0 means unbounded.
     */
    public function getTotalCapacity(): int
    {
        return $this->totalCapacity;
    }

    /**
     * Get the current backpressure strategy.
     */
    public function getBackpressureStrategy(): BackpressureStrategy
    {
        return $this->onBufferOverflow;
    }

    /**
     * Get the number of values currently in the buffer.
     */
    public function getBufferedCount(): int
    {
        return $this->bufferedCount;
    }

    /**
     * Check whether the buffer is full from a backpressure perspective.
     *
     * Backpressure is ONLY triggered by extraBufferCapacity. The replay
     * region always operates as a self-managing ring buffer that auto-evicts
     * the oldest value — it never causes backpressure. This matches Kotlin
     * SharedFlow semantics where:
     *   - replay buffer: always accepts, oldest values are evicted
     *   - extraBufferCapacity: absorbs bursts, triggers backpressure when full
     *
     * When extraBufferCapacity is 0, the flow NEVER triggers backpressure
     * regardless of how many values are in the replay buffer.
     */
    public function isBufferFull(): bool
    {
        if ($this->extraBufferCapacity === 0) {
            return false; // replay-only — never triggers backpressure
        }
        return $this->bufferedCount >= $this->totalCapacity;
    }

    /**
     * Get the number of emitters currently suspended waiting for buffer space.
     */
    public function getSuspendedEmitterCount(): int
    {
        return count($this->suspendedEmitters);
    }

    // ─── Emit ────────────────────────────────────────────────────────

    /**
     * Emit a value to all collectors.
     *
     * If the buffer is full, the backpressure strategy determines the outcome:
     *   - SUSPEND:     Fiber yields until space is available (cooperative).
     *   - DROP_OLDEST: Oldest buffered value is evicted to make room.
     *   - DROP_LATEST: This emission is silently discarded.
     *   - ERROR:       A RuntimeException is thrown.
     *
     * @param mixed $value The value to emit.
     * @throws RuntimeException When strategy is ERROR and buffer is full,
     *                          or when SUSPEND is used outside a Fiber.
     */
    public function emit(mixed $value): void
    {
        if (!$this->isActive) {
            return;
        }

        // ── Backpressure handling ────────────────────────────────
        if ($this->isBufferFull()) {
            switch ($this->onBufferOverflow) {
                case BackpressureStrategy::SUSPEND:
                    $this->suspendUntilSpace($value);
                    return; // value was buffered + dispatched inside suspend loop

                case BackpressureStrategy::DROP_OLDEST:
                    $this->evictOldest();
                    break; // fall through to normal buffering below

                case BackpressureStrategy::DROP_LATEST:
                    // Silently discard the new value — do NOT buffer or dispatch
                    return;

                case BackpressureStrategy::ERROR:
                    throw new RuntimeException(
                        "SharedFlow buffer overflow: buffer is full " .
                            "(capacity={$this->totalCapacity}, " .
                            "strategy={$this->onBufferOverflow->value}). " .
                            "Consider increasing extraBufferCapacity or using a " .
                            "different BackpressureStrategy.",
                    );
            }
        }

        // ── Buffer the value ─────────────────────────────────────
        $this->bufferValue($value);

        // ── Dispatch to active collectors ────────────────────────
        $this->dispatchToCollectors($value);
    }

    /**
     * Try to emit a value without suspending or throwing.
     *
     * Returns true if the value was successfully buffered and dispatched.
     * Returns false if the buffer is full (regardless of strategy).
     *
     * This is a non-blocking, non-throwing alternative to emit().
     *
     * @param mixed $value The value to emit.
     * @return bool True if emitted, false if buffer was full.
     */
    public function tryEmit(mixed $value): bool
    {
        if (!$this->isActive) {
            return false;
        }

        if ($this->isBufferFull()) {
            // For DROP_OLDEST we can still accept
            if ($this->onBufferOverflow === BackpressureStrategy::DROP_OLDEST) {
                $this->evictOldest();
                $this->bufferValue($value);
                $this->dispatchToCollectors($value);
                return true;
            }
            return false;
        }

        $this->bufferValue($value);
        $this->dispatchToCollectors($value);
        return true;
    }

    // ─── Collect ─────────────────────────────────────────────────────

    /**
     * Register a collector to receive future emissions.
     *
     * The collector immediately receives all values in the replay buffer
     * (up to `replay` most recent values), then continues to receive
     * new emissions as they arrive.
     *
     * Registering a collector also wakes any suspended emitters, since
     * the new collector may consume buffered values and free space.
     *
     * @param callable $collector Callback invoked with each emitted value.
     */
    public function collect(callable $collector): void
    {
        $collectorKey = uniqid("c_", true);
        $emittedCount = 0;
        $skippedCount = 0;

        $this->collectors[$collectorKey] = [
            "callback" => $collector,
            "emittedCount" => 0,
            "skippedCount" => 0,
            "operators" => $this->operators,
            "consumedIndex" => $this->writeIndex, // start from current position
        ];

        // Replay buffered values to the new collector.
        // Only replay up to $this->replay most recent values.
        $replayValues = $this->getReplayValues();

        foreach ($replayValues as $value) {
            try {
                $processedValue = $this->applyOperators(
                    $value,
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

        // A new collector may help drain the buffer — wake suspended emitters
        $this->resumeSuspendedEmitters();
    }

    /**
     * Remove a collector by its key.
     *
     * @param string $collectorKey The collector key returned conceptually
     *                             (internal — for advanced use).
     */
    public function removeCollector(string $collectorKey): void
    {
        unset($this->collectors[$collectorKey]);
    }

    /**
     * Get current number of collectors.
     */
    public function getCollectorCount(): int
    {
        return count($this->collectors);
    }

    // ─── Lifecycle ───────────────────────────────────────────────────

    /**
     * Complete the SharedFlow (no more emissions accepted).
     *
     * Any suspended emitters are woken and their values are discarded.
     */
    public function complete(): void
    {
        $this->isActive = false;

        // Wake all suspended emitters so they don't hang forever
        foreach ($this->suspendedEmitters as $id => $entry) {
            $fiber = $entry["fiber"];
            unset($this->suspendedEmitters[$id]);
            if ($fiber->isSuspended()) {
                $fiber->resume(false); // false = "not accepted"
            }
        }

        $this->executeOnCompletion(null);
    }

    /**
     * Check whether the flow is still active (accepting emissions).
     */
    public function isActive(): bool
    {
        return $this->isActive;
    }

    // ─── Internals: buffer management ────────────────────────────────

    /**
     * Add a value to the internal buffer.
     *
     * The replay region always operates as a ring buffer: when the total
     * buffer exceeds totalCapacity (or exceeds replay when extraBuffer is 0),
     * the oldest value is automatically evicted. This eviction is NOT
     * backpressure — it is normal replay-buffer housekeeping.
     *
     * Backpressure (checked via isBufferFull) only applies to the extra
     * buffer region and is handled by emit() BEFORE calling this method.
     */
    private function bufferValue(mixed $value): void
    {
        $this->buffer[] = $value;
        $this->bufferedCount++;
        $this->writeIndex++;

        // Determine the effective maximum buffer size.
        // When extraBufferCapacity > 0: max = replay + extraBufferCapacity
        // When extraBufferCapacity == 0 and replay > 0: max = replay (ring buffer)
        // When both are 0: no limit (truly unbounded, no replay)
        $maxSize = $this->totalCapacity;
        if ($maxSize === 0 && $this->replay > 0) {
            $maxSize = $this->replay;
        }

        // Auto-evict oldest values to maintain the maximum buffer size.
        // This is replay ring-buffer housekeeping, NOT backpressure.
        if ($maxSize > 0) {
            while ($this->bufferedCount > $maxSize) {
                array_shift($this->buffer);
                $this->bufferedCount--;
            }
        }
    }

    /**
     * Evict the oldest value from the buffer (DROP_OLDEST strategy).
     */
    private function evictOldest(): void
    {
        if ($this->bufferedCount > 0) {
            array_shift($this->buffer);
            $this->bufferedCount--;
        }
    }

    /**
     * Get the most recent `replay` values from the buffer.
     *
     * @return array<int, mixed>
     */
    private function getReplayValues(): array
    {
        if ($this->replay <= 0 || $this->bufferedCount === 0) {
            return [];
        }

        $count = min($this->replay, $this->bufferedCount);
        return array_slice($this->buffer, -$count);
    }

    /**
     * Notify the buffer that a collector has consumed a value,
     * potentially freeing space for a suspended emitter.
     *
     * Called internally after successful collector dispatch.
     */
    private function onCollectorConsumed(): void
    {
        // If there are emitters suspended waiting for buffer space,
        // try to resume them now that a collector has consumed.
        if (!empty($this->suspendedEmitters)) {
            $this->resumeSuspendedEmitters();
        }
    }

    // ─── Internals: dispatch ─────────────────────────────────────────

    /**
     * Dispatch a value to all active collectors.
     */
    private function dispatchToCollectors(mixed $value): void
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
     * If not inside a Fiber, falls back to a spin-loop with Pause::new()
     * (which is a no-op outside fibers but at least drives the scheduler
     * in contexts like RunBlocking or Thread::wait).
     *
     * @param mixed $value The value waiting to be emitted.
     */
    private function suspendUntilSpace(mixed $value): void
    {
        $fiber = Fiber::getCurrent();

        if ($fiber !== null) {
            // Inside a Fiber — register ourselves and suspend
            $emitterId = $this->nextEmitterId++;
            $this->suspendedEmitters[$emitterId] = [
                "fiber" => $fiber,
                "value" => $value,
            ];

            // Suspend — we'll be resumed by resumeSuspendedEmitters()
            // when a collector consumes a value or a new collector registers.
            $accepted = Fiber::suspend();

            // After resume: if accepted === true, value was already buffered
            // and dispatched by resumeSuspendedEmitters(). Nothing more to do.
            // If accepted === false (e.g. flow completed), the value is lost.
            return;
        }

        // Not inside a Fiber — spin-wait with cooperative scheduling.
        // This path is for top-level code that calls emit() directly.
        $maxSpins = 10000;
        $spins = 0;

        while ($this->isBufferFull() && $this->isActive && $spins < $maxSpins) {
            // Attempt to drive the scheduler so collectors can run
            Pause::new(); // no-op outside Fiber, but documents intent
            usleep(100); // small real-time sleep to avoid hot spin
            $spins++;
        }

        if (!$this->isActive) {
            return; // Flow was completed while we were waiting
        }

        if ($this->isBufferFull()) {
            // Timed out waiting — fall back to DROP_OLDEST to avoid deadlock
            $this->evictOldest();
        }

        $this->bufferValue($value);
        $this->dispatchToCollectors($value);
    }

    /**
     * Resume suspended emitters if buffer space is now available.
     *
     * For each suspended emitter (in FIFO order), if the buffer has space,
     * we buffer their value, dispatch it, and resume their fiber.
     */
    private function resumeSuspendedEmitters(): void
    {
        // Process suspended emitters in FIFO order
        foreach ($this->suspendedEmitters as $id => $entry) {
            if ($this->isBufferFull()) {
                break; // No more space — remaining emitters stay suspended
            }

            $fiber = $entry["fiber"];
            $value = $entry["value"];

            // Remove from waiting list BEFORE resuming to prevent re-entrancy issues
            unset($this->suspendedEmitters[$id]);

            // Buffer and dispatch the waiting value
            $this->bufferValue($value);
            $this->dispatchToCollectors($value);

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
        // Buffer contents are preserved in the clone (for replay purposes)
    }
}
