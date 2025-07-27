<?php

declare(strict_types=1);

namespace vosaka\foroutines\flow;

use RuntimeException;
use Throwable;

/**
 * StateFlow - A SharedFlow that always has a current value
 */
final class StateFlow extends BaseFlow
{
    private array $collectors = [];
    private mixed $currentValue;
    private bool $hasValue = false;

    private function __construct(mixed $initialValue)
    {
        $this->currentValue = $initialValue;
        $this->hasValue = true;
    }

    /**
     * Create a new StateFlow with initial value
     */
    public static function new(mixed $initialValue): StateFlow
    {
        return new self($initialValue);
    }

    /**
     * Get current value
     */
    public function getValue(): mixed
    {
        if (!$this->hasValue) {
            throw new RuntimeException('StateFlow has no value');
        }
        return $this->currentValue;
    }

    /**
     * Set new value and emit to collectors
     */
    public function setValue(mixed $value): void
    {
        $oldValue = $this->currentValue;
        $this->currentValue = $value;
        $this->hasValue = true;

        // Only emit if value actually changed
        if ($oldValue !== $value) {
            $this->emitToCollectors($value);
        }
    }

    /**
     * Update value using a function
     */
    public function update(callable $updater): void
    {
        $newValue = $updater($this->currentValue);
        $this->setValue($newValue);
    }

    /**
     * Collect state changes
     */
    public function collect(callable $collector): void
    {
        $collectorKey = uniqid();
        $emittedCount = 0;
        $skippedCount = 0;

        // Store collector
        $this->collectors[$collectorKey] = [
            'callback' => $collector,
            'emittedCount' => 0,
            'skippedCount' => 0
        ];

        // Immediately emit current value
        if ($this->hasValue) {
            try {
                $processedValue = $this->applyOperators($this->currentValue, $emittedCount, $skippedCount);
                if ($processedValue !== null) {
                    $collector($processedValue);
                    $emittedCount++;
                }
            } catch (Throwable $e) {
                unset($this->collectors[$collectorKey]);
                throw $e;
            }
        }

        $this->collectors[$collectorKey]['emittedCount'] = $emittedCount;
        $this->collectors[$collectorKey]['skippedCount'] = $skippedCount;
    }

    /**
     * Collect only distinct values (skip if same as previous)
     */
    public function distinctUntilChanged(?callable $compareFunction = null): StateFlow
    {
        $newFlow = clone $this;
        $newFlow->operators[] = [
            'type' => 'distinctUntilChanged',
            'compare' => $compareFunction ?? fn($a, $b) => $a === $b
        ];
        return $newFlow;
    }

    /**
     * Get current number of collectors
     */
    public function getCollectorCount(): int
    {
        return count($this->collectors);
    }

    /**
     * Remove a collector
     */
    public function removeCollector(string $collectorKey): void
    {
        unset($this->collectors[$collectorKey]);
    }

    /**
     * Check if StateFlow has any collectors
     */
    public function hasCollectors(): bool
    {
        return !empty($this->collectors);
    }

    private function emitToCollectors(mixed $value): void
    {
        foreach ($this->collectors as $key => $collectorInfo) {
            try {
                $processedValue = $this->applyOperatorsForCollector($value, $collectorInfo);
                if ($processedValue !== null) {
                    $collectorInfo['callback']($processedValue);
                    $collectorInfo['emittedCount']++;
                }
            } catch (Throwable) {
                // Remove failed collector
                unset($this->collectors[$key]);
            }
        }
    }

    private function applyOperatorsForCollector(mixed $value, array &$collectorInfo): mixed
    {
        return $this->applyOperators($value, $collectorInfo['emittedCount'], $collectorInfo['skippedCount']);
    }

    public function __clone()
    {
        parent::__clone();
        $this->collectors = [];
    }
}
