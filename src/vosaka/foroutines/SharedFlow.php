<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Throwable;

/**
 * Hot Flow that shares emissions among multiple collectors
 */
final class SharedFlow extends BaseFlow
{
    private array $collectors = [];
    private array $emittedValues = [];
    private int $replay;
    private bool $isActive = true;

    private function __construct(int $replay = 0)
    {
        $this->replay = $replay;
    }

    /**
     * Create a new SharedFlow
     */
    public static function create(int $replay = 0): SharedFlow
    {
        return new self($replay);
    }

    /**
     * Emit a value to all collectors
     */
    public function emit(mixed $value): void
    {
        if (!$this->isActive) {
            return;
        }

        // Store for replay
        $this->emittedValues[] = $value;
        if (count($this->emittedValues) > $this->replay) {
            array_shift($this->emittedValues);
        }

        // Emit to all active collectors
        foreach ($this->collectors as $key => $collectorInfo) {
            try {
                $processedValue = $this->applyOperatorsForCollector($value, $collectorInfo);
                if ($processedValue !== null) {
                    $collectorInfo['callback']($processedValue);
                    $collectorInfo['emittedCount']++;
                }
            } catch (Throwable) {
                unset($this->collectors[$key]);
            }
        }
    }

    /**
     * Collect values from SharedFlow
     */
    public function collect(callable $collector): void
    {
        $collectorKey = uniqid();
        $emittedCount = 0;
        $skippedCount = 0;

        // Store collector info
        $this->collectors[$collectorKey] = [
            'callback' => $collector,
            'emittedCount' => 0,
            'skippedCount' => 0,
            'operators' => $this->operators
        ];

        // Replay previous values
        foreach ($this->emittedValues as $value) {
            try {
                $processedValue = $this->applyOperators($value, $emittedCount, $skippedCount);
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
     * Get current number of collectors
     */
    public function getCollectorCount(): int
    {
        return count($this->collectors);
    }

    /**
     * Complete the SharedFlow (no more emissions)
     */
    public function complete(): void
    {
        $this->isActive = false;
        $this->executeOnCompletion(null);
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
