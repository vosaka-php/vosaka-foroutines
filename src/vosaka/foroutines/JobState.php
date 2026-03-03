<?php

declare(strict_types=1);

namespace vosaka\foroutines;

enum JobState: string
{
    case PENDING = "pending";
    case RUNNING = "running";
    case COMPLETED = "completed";
    case FAILED = "failed";
    case CANCELLED = "cancelled";

    public function isFinal(): bool
    {
        return match ($this) {
            self::COMPLETED, self::FAILED, self::CANCELLED => true,
            default => false,
        };
    }
}
