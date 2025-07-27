<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Throwable;

/**
 * Utility class for creating MutableStateFlow
 */
final class MutableStateFlow extends StateFlow
{
    public static function create(mixed $initialValue): MutableStateFlow
    {
        return new self($initialValue);
    }

    /**
     * Alias for setValue for better API consistency
     */
    public function emit(mixed $value): void
    {
        $this->setValue($value);
    }

    /**
     * Compare and set - only set if current value matches expected
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
     * Try to emit - returns false if StateFlow is completed/closed
     */
    public function tryEmit(mixed $value): bool
    {
        try {
            $this->setValue($value);
            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
