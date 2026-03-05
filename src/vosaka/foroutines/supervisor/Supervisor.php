<?php

declare(strict_types=1);

namespace vosaka\foroutines\supervisor;

use Closure;
use vosaka\foroutines\Dispatchers;
use vosaka\foroutines\Launch;
use vosaka\foroutines\Pause;
use vosaka\foroutines\FiberUtils;
use vosaka\foroutines\Thread;
use Fiber;

/**
 * Supervisor — manages a set of child tasks with automatic restart.
 *
 * Inspired by Erlang/OTP Supervisors, this class implements the
 * "let it crash" philosophy: children are expected to fail, and the
 * supervisor handles restart logic transparently.
 *
 * === Strategies ===
 *
 *   - ONE_FOR_ONE:  Only the crashed child is restarted.
 *   - ONE_FOR_ALL:  All children are stopped and restarted.
 *   - REST_FOR_ONE: Crashed child + all children started after it
 *                   are stopped and restarted.
 *
 * === Restart Budget ===
 *
 * Each child has a restart budget (maxRestarts within maxRestartWindow).
 * If a child exceeds its budget, the supervisor stops trying to restart
 * it (circuit-breaker). This prevents infinite restart loops.
 *
 * === Backoff ===
 *
 * Restarts use exponential backoff (100ms → 200ms → 400ms → ... max 30s)
 * to avoid CPU spinning when a child crashes immediately after starting.
 *
 * === Usage ===
 *
 *     Supervisor::new(RestartStrategy::ONE_FOR_ONE)
 *         ->child(fn() => workerA(), 'worker-a')
 *         ->child(fn() => workerB(), 'worker-b')
 *         ->start();
 *
 *     // Works with IO dispatchers too:
 *     Supervisor::new(RestartStrategy::ONE_FOR_ONE)
 *         ->child(fn() => ioWorker(), 'io-worker')
 *         ->start(Dispatchers::IO);
 */
final class Supervisor
{
    /** The restart strategy for this supervisor */
    private RestartStrategy $strategy;

    /**
     * Child specifications.
     * @var ChildSpec[]
     */
    private array $specs = [];

    /**
     * Running child Launch jobs, keyed by spec ID.
     * @var array<string, Launch>
     */
    private array $children = [];

    /**
     * Restart history per child: array of timestamps of recent restarts.
     * Used to enforce the restart budget.
     * @var array<string, float[]>
     */
    private array $restartHistory = [];

    /**
     * Consecutive restart count per child (for backoff calculation).
     * Reset when a child runs successfully for a while.
     * @var array<string, int>
     */
    private array $consecutiveRestarts = [];

    /**
     * Children that have failed (caught by the wrapped factory).
     * Maps child ID => error message.
     * Cleared after each supervision tick processes them.
     * @var array<string, string>
     */
    private array $failedChildren = [];

    /** Whether the supervisor is currently running */
    private bool $running = false;

    /** The supervisor's own Launch job */
    private ?Launch $supervisorJob = null;

    /** Auto-increment counter for child IDs */
    private int $childCounter = 0;

    // ═════════════════════════════════════════════════════════════════
    //  Factory
    // ═════════════════════════════════════════════════════════════════

    private function __construct(RestartStrategy $strategy)
    {
        $this->strategy = $strategy;
    }

    /**
     * Create a new Supervisor with the given restart strategy.
     *
     * @param RestartStrategy $strategy The strategy for handling child crashes.
     * @return self
     */
    public static function new(
        RestartStrategy $strategy = RestartStrategy::ONE_FOR_ONE,
    ): self {
        return new self($strategy);
    }

    // ═════════════════════════════════════════════════════════════════
    //  Child registration
    // ═════════════════════════════════════════════════════════════════

    /**
     * Add a child specification.
     *
     * @param Closure|callable $factory           Factory function for the child task.
     * @param string           $id                Optional unique ID (auto-generated if empty).
     * @param int              $maxRestarts       Max restarts in the window before circuit-break.
     * @param float            $maxRestartWindow  Window in seconds for the restart budget.
     * @return self For fluent chaining.
     */
    public function child(
        Closure|callable $factory,
        string $id = '',
        int $maxRestarts = 5,
        float $maxRestartWindow = 60.0,
    ): self {
        if ($id === '') {
            $id = 'child-' . $this->childCounter++;
        }

        $closure = $factory instanceof Closure
            ? $factory
            : Closure::fromCallable($factory);

        $this->specs[] = new ChildSpec(
            id: $id,
            factory: $closure,
            maxRestarts: $maxRestarts,
            maxRestartWindow: $maxRestartWindow,
        );

        return $this;
    }

    // ═════════════════════════════════════════════════════════════════
    //  Supervision lifecycle
    // ═════════════════════════════════════════════════════════════════

    /**
     * Start all children and begin the supervision loop.
     *
     * The supervision loop runs as a Fiber via Launch. It periodically
     * checks if any children have failed and applies the restart
     * strategy accordingly.
     *
     * @param Dispatchers $dispatcher The dispatcher for child tasks.
     *                                Use Dispatchers::IO for IO-bound children
     *                                (they'll run in WorkerPool processes).
     */
    public function start(
        Dispatchers $dispatcher = Dispatchers::DEFAULT ,
    ): void {
        if ($this->running) {
            return;
        }

        $this->running = true;

        // Start all children
        foreach ($this->specs as $spec) {
            $this->startChild($spec, $dispatcher);
        }

        // Launch the supervision loop
        $this->supervisorJob = Launch::new(function () use ($dispatcher) {
            while ($this->running) {
                $this->supervisionTick($dispatcher);
                Pause::force();
            }
        });
    }

    /**
     * Stop all children and the supervision loop.
     */
    public function stop(): void
    {
        if (!$this->running) {
            return;
        }

        $this->running = false;

        // Cancel all running children
        foreach ($this->children as $id => $job) {
            if (!$job->isFinal()) {
                try {
                    $job->cancel();
                } catch (\Throwable) {
                    // Best-effort
                }
            }
        }

        $this->children = [];

        // Cancel the supervisor's own job
        if ($this->supervisorJob !== null && !$this->supervisorJob->isFinal()) {
            try {
                $this->supervisorJob->cancel();
            } catch (\Throwable) {
            }
        }
    }

    /**
     * Check if the supervisor is running.
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Get the supervisor's Launch job.
     */
    public function getJob(): ?Launch
    {
        return $this->supervisorJob;
    }

    // ═════════════════════════════════════════════════════════════════
    //  Internal: child management
    // ═════════════════════════════════════════════════════════════════

    /**
     * Start a single child from its specification.
     *
     * The factory is wrapped in a try-catch so that if the child throws,
     * the exception is caught INSIDE the Fiber. This prevents Launch::runOnce()
     * from re-throwing the exception (which would crash the entire RunBlocking).
     * Instead, the Job completes normally and we track the failure via a
     * per-child flag that the supervision loop checks.
     */
    private function startChild(
        ChildSpec $spec,
        Dispatchers $dispatcher,
    ): void {
        $childId = $spec->id;

        // Wrap factory so exceptions are contained
        $wrappedFactory = function () use ($spec, $childId) {
            try {
                ($spec->factory)();
            } catch (\Throwable $e) {
                // Mark this child as failed (for the supervision loop)
                $this->failedChildren[$childId] = $e->getMessage();
            }
        };

        $job = Launch::new($wrappedFactory, $dispatcher);
        $this->children[$spec->id] = $job;
    }

    /**
     * One tick of the supervision loop.
     *
     * Checks all children for failures and applies the configured
     * restart strategy.
     */
    private function supervisionTick(Dispatchers $dispatcher): void
    {
        foreach ($this->specs as $index => $spec) {
            $childId = $spec->id;

            // Check if child was marked as failed by the wrapped factory
            if (isset($this->failedChildren[$childId])) {
                unset($this->failedChildren[$childId]);
                $this->handleChildFailure($spec, $index, $dispatcher);
                continue;
            }

            // Check if the child's job has finished (terminated fiber)
            $job = $this->children[$childId] ?? null;
            if ($job === null) {
                continue;
            }

            // If the job is final (completed or failed), check if it was
            // unexpected (child shouldn't have stopped on its own)
            if ($job->isFailed()) {
                $this->handleChildFailure($spec, $index, $dispatcher);
            }
        }
    }

    /**
     * Handle a child failure according to the restart strategy.
     */
    private function handleChildFailure(
        ChildSpec $spec,
        int $specIndex,
        Dispatchers $dispatcher,
    ): void {
        // Check restart budget
        if (!$this->canRestart($spec)) {
            error_log(
                "Supervisor: Child '{$spec->id}' exceeded restart budget " .
                "({$spec->maxRestarts} in {$spec->maxRestartWindow}s). " .
                "Not restarting.",
            );
            unset($this->children[$spec->id]);
            return;
        }

        // Apply backoff delay
        $this->applyBackoff($spec->id);

        // Apply strategy
        match ($this->strategy) {
            RestartStrategy::ONE_FOR_ONE => $this->restartOne(
                $spec,
                $dispatcher,
            ),
            RestartStrategy::ONE_FOR_ALL => $this->restartAll($dispatcher),
            RestartStrategy::REST_FOR_ONE => $this->restartRest(
                $specIndex,
                $dispatcher,
            ),
        };

        // Record this restart
        $this->recordRestart($spec->id);
    }

    /**
     * ONE_FOR_ONE: Restart only the failed child.
     */
    private function restartOne(
        ChildSpec $spec,
        Dispatchers $dispatcher,
    ): void {
        $this->startChild($spec, $dispatcher);
    }

    /**
     * ONE_FOR_ALL: Stop all children, then restart all.
     */
    private function restartAll(Dispatchers $dispatcher): void
    {
        // Stop all children
        foreach ($this->children as $id => $job) {
            if (!$job->isFinal()) {
                try {
                    $job->cancel();
                } catch (\Throwable) {
                }
            }
        }
        $this->children = [];

        // Restart all
        foreach ($this->specs as $spec) {
            $this->startChild($spec, $dispatcher);
        }
    }

    /**
     * REST_FOR_ONE: Stop the failed child and all children started
     * after it, then restart them in order.
     */
    private function restartRest(
        int $fromIndex,
        Dispatchers $dispatcher,
    ): void {
        // Stop children from fromIndex onward
        for ($i = $fromIndex; $i < count($this->specs); $i++) {
            $spec = $this->specs[$i];
            $job = $this->children[$spec->id] ?? null;
            if ($job !== null && !$job->isFinal()) {
                try {
                    $job->cancel();
                } catch (\Throwable) {
                }
            }
            unset($this->children[$spec->id]);
        }

        // Restart them in order
        for ($i = $fromIndex; $i < count($this->specs); $i++) {
            $this->startChild($this->specs[$i], $dispatcher);
        }
    }

    // ═════════════════════════════════════════════════════════════════
    //  Restart budget & backoff
    // ═════════════════════════════════════════════════════════════════

    /**
     * Check if a child can be restarted within its budget.
     */
    private function canRestart(ChildSpec $spec): bool
    {
        $history = $this->restartHistory[$spec->id] ?? [];

        if (empty($history)) {
            return true;
        }

        // Count restarts within the window
        $now = microtime(true);
        $windowStart = $now - $spec->maxRestartWindow;
        $recentRestarts = 0;

        foreach ($history as $timestamp) {
            if ($timestamp >= $windowStart) {
                $recentRestarts++;
            }
        }

        return $recentRestarts < $spec->maxRestarts;
    }

    /**
     * Record a restart timestamp for budget tracking.
     */
    private function recordRestart(string $childId): void
    {
        if (!isset($this->restartHistory[$childId])) {
            $this->restartHistory[$childId] = [];
        }

        $this->restartHistory[$childId][] = microtime(true);

        // Trim old entries (keep only those within the max window)
        $maxWindow = 0.0;
        foreach ($this->specs as $spec) {
            if ($spec->id === $childId) {
                $maxWindow = $spec->maxRestartWindow;
                break;
            }
        }

        if ($maxWindow > 0.0) {
            $cutoff = microtime(true) - $maxWindow;
            $this->restartHistory[$childId] = array_values(
                array_filter(
                    $this->restartHistory[$childId],
                    fn(float $ts) => $ts >= $cutoff,
                ),
            );
        }

        // Increment consecutive restart counter
        $this->consecutiveRestarts[$childId] =
            ($this->consecutiveRestarts[$childId] ?? 0) + 1;
    }

    /**
     * Apply exponential backoff delay before restarting.
     *
     * First restart: 100ms, second: 200ms, third: 400ms, ... max 30s.
     * Uses usleep() to avoid CPU spinning.
     */
    private function applyBackoff(string $childId): void
    {
        $attempts = $this->consecutiveRestarts[$childId] ?? 0;

        if ($attempts <= 0) {
            return;
        }

        // Exponential backoff: 100ms × 2^(attempt-1), max 30s
        $delayMs = min(100 * (1 << ($attempts - 1)), 30_000);
        usleep($delayMs * 1000);
    }
}
