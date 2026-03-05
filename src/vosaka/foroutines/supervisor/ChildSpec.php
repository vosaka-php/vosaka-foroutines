<?php

declare(strict_types=1);

namespace vosaka\foroutines\supervisor;

use Closure;

/**
 * Specification for a supervised child.
 *
 * Defines the factory function to create the child, and the restart
 * budget (max restarts within a time window before circuit-breaking).
 *
 * Usage:
 *     $spec = new ChildSpec(
 *         id: 'worker-a',
 *         factory: fn() => doWork(),
 *         maxRestarts: 5,
 *         maxRestartWindow: 60.0,
 *     );
 */
final class ChildSpec
{
    /**
     * @param string  $id               Unique child identifier.
     * @param Closure $factory           Factory function: fn() => void (the child's work).
     * @param int     $maxRestarts       Max restarts allowed within the window.
     * @param float   $maxRestartWindow  Window in seconds for restart budget.
     */
    public function __construct(
        public readonly string $id,
        public readonly Closure $factory,
        public readonly int $maxRestarts = 5,
        public readonly float $maxRestartWindow = 60.0,
    ) {
    }
}
