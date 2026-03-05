<?php

declare(strict_types=1);

namespace vosaka\foroutines\supervisor;

use vosaka\foroutines\Dispatchers;
use vosaka\foroutines\actor\ActorBehavior;
use Closure;
use RuntimeException;

/**
 * ChildSpec — Specification for a supervised child (actor or closure-based task).
 *
 * A ChildSpec tells the Supervisor everything it needs to know to:
 *   1. **Create** the child (either an Actor with a behavior, or a plain
 *      closure/callable that runs in a Fiber).
 *   2. **Restart** the child when it crashes (how many times, how quickly).
 *   3. **Categorize** the child's restart importance (permanent, transient,
 *      or temporary).
 *
 * Two modes of operation:
 *
 *   **Actor mode** — The child is an Actor with an ActorBehavior. The
 *   supervisor creates the Actor, registers it with the ActorSystem, and
 *   monitors its lifecycle via the Actor's onStop callback.
 *
 *   **Closure mode** — The child is a plain callable (Closure) that runs
 *   in a Fiber via Launch::new(). The supervisor wraps it in an error-
 *   catching fiber and monitors the Launch job's completion/failure.
 *   This is the simpler mode, suitable for supervised background tasks
 *   that don't need the full actor messaging infrastructure.
 *
 * Restart types (inspired by Erlang/OTP child_spec restart field):
 *
 *   ┌─────────────┬───────────────────────────────────────────────────┐
 *   │ Type        │ Behavior                                          │
 *   ├─────────────┼───────────────────────────────────────────────────┤
 *   │ PERMANENT   │ Always restart, regardless of exit reason.        │
 *   │             │ Use for long-running services that must stay up.  │
 *   ├─────────────┼───────────────────────────────────────────────────┤
 *   │ TRANSIENT   │ Restart only if the child exits abnormally        │
 *   │             │ (with an error). Normal exit is not restarted.    │
 *   │             │ Use for tasks that should complete once but must  │
 *   │             │ be retried on failure.                            │
 *   ├─────────────┼───────────────────────────────────────────────────┤
 *   │ TEMPORARY   │ Never restart. Once it stops, it's gone.         │
 *   │             │ Use for one-shot tasks where failure is acceptable│
 *   │             │ or handled elsewhere.                             │
 *   └─────────────┴───────────────────────────────────────────────────┘
 *
 * Restart intensity limiting:
 *   To prevent infinite restart loops (a child that crashes immediately
 *   after every restart), each ChildSpec has a maxRestarts / withinSeconds
 *   pair. If the child crashes more than maxRestarts times within
 *   withinSeconds, the supervisor considers it permanently failed and
 *   stops trying (or escalates, depending on the supervisor configuration).
 *
 * Usage:
 *   // Actor-based child
 *   $spec = ChildSpec::actor('worker-1', new WorkerBehavior())
 *       ->permanent()
 *       ->withCapacity(100)
 *       ->withMaxRestarts(5, withinSeconds: 60.0);
 *
 *   // Closure-based child
 *   $spec = ChildSpec::closure('bg-task', fn() => processQueue())
 *       ->transient()
 *       ->withDispatcher(Dispatchers::IO)
 *       ->withMaxRestarts(3, withinSeconds: 30.0);
 *
 *   // Add to supervisor
 *   Supervisor::new(strategy: RestartStrategy::ONE_FOR_ONE)
 *       ->child($spec)
 *       ->start();
 *
 * @see Supervisor       The supervisor that manages these specs.
 * @see RestartStrategy  The strategy that determines which children to restart.
 * @see RestartType      The enum defining PERMANENT / TRANSIENT / TEMPORARY.
 */
final class ChildSpec
{
    // ─── Identity ────────────────────────────────────────────────────

    /**
     * Unique name for this child within its supervisor.
     */
    public readonly string $name;

    // ─── Child factory ───────────────────────────────────────────────

    /**
     * The ActorBehavior class for actor-mode children.
     * Null for closure-mode children.
     */
    private ?ActorBehavior $behavior;

    /**
     * The callable/closure for closure-mode children.
     * Null for actor-mode children.
     *
     * @var (Closure(): mixed)|null
     */
    private ?Closure $closure;

    // ─── Restart configuration ───────────────────────────────────────

    /**
     * Restart type: PERMANENT, TRANSIENT, or TEMPORARY.
     */
    private RestartType $restartType = RestartType::PERMANENT;

    /**
     * Maximum number of restarts allowed within $withinSeconds.
     *
     * If exceeded, the child is considered permanently failed.
     * Default: 3 (allow 3 restarts before giving up).
     */
    private int $maxRestarts = 3;

    /**
     * Time window (in seconds) for counting restarts.
     *
     * Restart timestamps older than this window are forgotten, so a
     * child that crashes occasionally (but not rapidly) can be restarted
     * indefinitely.
     *
     * Default: 60.0 seconds (1 minute).
     */
    private float $withinSeconds = 60.0;

    // ─── Actor-specific configuration ────────────────────────────────

    /**
     * Mailbox capacity for actor-mode children (default: 50).
     */
    private int $capacity = 50;

    // ─── Dispatcher ──────────────────────────────────────────────────

    /**
     * The dispatcher to use when starting the child's Fiber/Actor.
     * null = Dispatchers::DEFAULT.
     */
    private ?Dispatchers $dispatcher = null;

    // ─── Runtime state (managed by Supervisor) ───────────────────────

    /**
     * Timestamps of recent restarts for intensity limiting.
     *
     * @var float[]
     */
    private array $restartTimestamps = [];

    /**
     * Total number of restarts over the lifetime of this spec.
     */
    private int $totalRestarts = 0;

    /**
     * Whether this child has been permanently retired (exceeded max restarts).
     */
    private bool $retired = false;

    /**
     * The order index in which this child was added to the supervisor.
     * Used by REST_FOR_ONE and ONE_FOR_ALL strategies for ordering.
     * Set by the Supervisor when the spec is added.
     */
    private int $orderIndex = 0;

    // ═════════════════════════════════════════════════════════════════
    //  Constructor (private — use factory methods)
    // ═════════════════════════════════════════════════════════════════

    /**
     * @param string             $name     Child name.
     * @param ActorBehavior|null $behavior Actor behavior (actor mode).
     * @param Closure|null       $closure  Callable (closure mode).
     */
    private function __construct(
        string $name,
        ?ActorBehavior $behavior,
        ?Closure $closure,
    ) {
        $this->name = $name;
        $this->behavior = $behavior;
        $this->closure = $closure;
    }

    // ═════════════════════════════════════════════════════════════════
    //  Factory methods
    // ═════════════════════════════════════════════════════════════════

    /**
     * Create a ChildSpec for an Actor-based child.
     *
     * The supervisor will create an Actor with the given behavior and
     * register it with the ActorSystem. On restart, a new Actor is
     * created with a fresh instance of the same behavior class.
     *
     * @param string        $name     Unique child name.
     * @param ActorBehavior $behavior The actor's message handler.
     * @return self Fluent builder.
     */
    public static function actor(string $name, ActorBehavior $behavior): self
    {
        return new self($name, $behavior, null);
    }

    /**
     * Create a ChildSpec for a Closure-based child.
     *
     * The supervisor will launch the closure in a Fiber (via Launch::new)
     * and monitor its lifecycle. On restart, the same closure is launched
     * again in a new Fiber.
     *
     * @param string  $name    Unique child name.
     * @param Closure $closure The task to run.
     * @return self Fluent builder.
     */
    public static function closure(string $name, Closure $closure): self
    {
        return new self($name, null, $closure);
    }

    // ═════════════════════════════════════════════════════════════════
    //  Builder methods (fluent API)
    // ═════════════════════════════════════════════════════════════════

    /**
     * Set restart type to PERMANENT — always restart on any exit.
     *
     * @return self
     */
    public function permanent(): self
    {
        $this->restartType = RestartType::PERMANENT;
        return $this;
    }

    /**
     * Set restart type to TRANSIENT — restart only on abnormal exit (error).
     *
     * @return self
     */
    public function transient(): self
    {
        $this->restartType = RestartType::TRANSIENT;
        return $this;
    }

    /**
     * Set restart type to TEMPORARY — never restart.
     *
     * @return self
     */
    public function temporary(): self
    {
        $this->restartType = RestartType::TEMPORARY;
        return $this;
    }

    /**
     * Set the restart type explicitly.
     *
     * @param RestartType $type The restart type.
     * @return self
     */
    public function withRestartType(RestartType $type): self
    {
        $this->restartType = $type;
        return $this;
    }

    /**
     * Set the maximum restart intensity.
     *
     * If the child is restarted more than $maxRestarts times within
     * $withinSeconds, it is considered permanently failed and will
     * not be restarted again.
     *
     * @param int   $maxRestarts   Maximum restarts allowed (must be >= 0).
     *                             0 = never restart (equivalent to TEMPORARY).
     * @param float $withinSeconds Time window in seconds (must be > 0).
     * @return self
     */
    public function withMaxRestarts(int $maxRestarts, float $withinSeconds = 60.0): self
    {
        if ($maxRestarts < 0) {
            throw new RuntimeException(
                "ChildSpec '{$this->name}': maxRestarts must be >= 0, got {$maxRestarts}.",
            );
        }

        if ($withinSeconds <= 0.0) {
            throw new RuntimeException(
                "ChildSpec '{$this->name}': withinSeconds must be > 0, got {$withinSeconds}.",
            );
        }

        $this->maxRestarts = $maxRestarts;
        $this->withinSeconds = $withinSeconds;
        return $this;
    }

    /**
     * Set the mailbox capacity for actor-mode children.
     *
     * Ignored for closure-mode children.
     *
     * @param int $capacity Mailbox capacity (0 = unbuffered, N = buffered).
     * @return self
     */
    public function withCapacity(int $capacity): self
    {
        $this->capacity = max(0, $capacity);
        return $this;
    }

    /**
     * Set the dispatcher for the child's Fiber/Actor loop.
     *
     * @param Dispatchers $dispatcher The dispatcher to use.
     * @return self
     */
    public function withDispatcher(Dispatchers $dispatcher): self
    {
        $this->dispatcher = $dispatcher;
        return $this;
    }

    // ═════════════════════════════════════════════════════════════════
    //  Getters (used by Supervisor)
    // ═════════════════════════════════════════════════════════════════

    /**
     * Check if this is an actor-mode child.
     *
     * @return bool True if actor-mode, false if closure-mode.
     */
    public function isActorMode(): bool
    {
        return $this->behavior !== null;
    }

    /**
     * Check if this is a closure-mode child.
     *
     * @return bool True if closure-mode, false if actor-mode.
     */
    public function isClosureMode(): bool
    {
        return $this->closure !== null;
    }

    /**
     * Get the ActorBehavior (actor-mode only).
     *
     * @return ActorBehavior|null
     */
    public function getBehavior(): ?ActorBehavior
    {
        return $this->behavior;
    }

    /**
     * Get the closure (closure-mode only).
     *
     * @return Closure|null
     */
    public function getClosure(): ?Closure
    {
        return $this->closure;
    }

    /**
     * Get the restart type.
     *
     * @return RestartType
     */
    public function getRestartType(): RestartType
    {
        return $this->restartType;
    }

    /**
     * Get the maximum restarts allowed within the time window.
     *
     * @return int
     */
    public function getMaxRestarts(): int
    {
        return $this->maxRestarts;
    }

    /**
     * Get the time window for restart intensity counting.
     *
     * @return float Seconds.
     */
    public function getWithinSeconds(): float
    {
        return $this->withinSeconds;
    }

    /**
     * Get the mailbox capacity (actor-mode).
     *
     * @return int
     */
    public function getCapacity(): int
    {
        return $this->capacity;
    }

    /**
     * Get the dispatcher configuration.
     *
     * @return Dispatchers|null null = Dispatchers::DEFAULT.
     */
    public function getDispatcher(): ?Dispatchers
    {
        return $this->dispatcher;
    }

    /**
     * Get the total number of restarts over the spec's lifetime.
     *
     * @return int
     */
    public function getTotalRestarts(): int
    {
        return $this->totalRestarts;
    }

    /**
     * Check if this child has been permanently retired.
     *
     * @return bool
     */
    public function isRetired(): bool
    {
        return $this->retired;
    }

    /**
     * Get the order index (position within the supervisor's child list).
     *
     * @return int
     */
    public function getOrderIndex(): int
    {
        return $this->orderIndex;
    }

    // ═════════════════════════════════════════════════════════════════
    //  Supervisor-managed state mutations
    // ═════════════════════════════════════════════════════════════════

    /**
     * Set the order index (called by Supervisor when registering this spec).
     *
     * @param int $index Position in the child list.
     * @internal
     */
    public function setOrderIndex(int $index): void
    {
        $this->orderIndex = $index;
    }

    /**
     * Determine whether the child should be restarted given its exit reason.
     *
     * Applies three checks:
     *   1. Restart type policy (PERMANENT/TRANSIENT/TEMPORARY).
     *   2. Retired flag (already exceeded max restarts).
     *   3. Restart intensity limit (maxRestarts within withinSeconds).
     *
     * If the intensity limit is exceeded, the child is marked as retired.
     *
     * @param \Throwable|null $error The error that caused the exit, or null
     *                               for graceful (normal) exit.
     * @return bool True if the child should be restarted.
     */
    public function shouldRestart(?\Throwable $error): bool
    {
        // Already permanently retired
        if ($this->retired) {
            return false;
        }

        // Apply restart type policy
        if (!$this->restartType->shouldRestart($error)) {
            return false;
        }

        // Check restart intensity
        if (!$this->checkIntensity()) {
            $this->retired = true;
            error_log(
                "Supervisor: Child '{$this->name}' permanently retired — " .
                "exceeded max restarts ({$this->maxRestarts} within " .
                "{$this->withinSeconds}s). Total restarts: {$this->totalRestarts}.",
            );
            return false;
        }

        return true;
    }

    /**
     * Record a restart event.
     *
     * Called by the Supervisor after successfully restarting the child.
     * Adds the current timestamp to the restart history for intensity
     * tracking and increments the total restart counter.
     *
     * @internal Called by Supervisor.
     */
    public function recordRestart(): void
    {
        $this->restartTimestamps[] = microtime(true);
        $this->totalRestarts++;
    }

    /**
     * Reset the restart state (timestamps and retired flag).
     *
     * Called when the supervisor itself is restarted, giving all children
     * a clean slate. Does NOT reset totalRestarts (lifetime counter).
     *
     * @internal Called by Supervisor.
     */
    public function resetRestartState(): void
    {
        $this->restartTimestamps = [];
        $this->retired = false;
    }

    /**
     * Force-retire this child (no further restarts).
     *
     * Used by the Supervisor when it decides to give up on a child
     * (e.g. when the supervisor itself is hitting its own intensity limit).
     *
     * @internal Called by Supervisor.
     */
    public function retire(): void
    {
        $this->retired = true;
    }

    // ═════════════════════════════════════════════════════════════════
    //  Introspection / Debugging
    // ═════════════════════════════════════════════════════════════════

    /**
     * Get a snapshot of this spec's state for debugging/monitoring.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return [
            'name' => $this->name,
            'mode' => $this->isActorMode() ? 'actor' : 'closure',
            'restartType' => $this->restartType->value,
            'maxRestarts' => $this->maxRestarts,
            'withinSeconds' => $this->withinSeconds,
            'totalRestarts' => $this->totalRestarts,
            'recentRestarts' => count($this->getRecentRestarts()),
            'retired' => $this->retired,
            'orderIndex' => $this->orderIndex,
            'dispatcher' => $this->dispatcher?->name ?? 'DEFAULT',
            'capacity' => $this->capacity,
        ];
    }

    /**
     * Get a human-readable string representation.
     *
     * @return string
     */
    public function __toString(): string
    {
        $mode = $this->isActorMode() ? 'actor' : 'closure';
        $status = $this->retired ? 'RETIRED' : 'ACTIVE';

        return "ChildSpec(name={$this->name}, mode={$mode}, " .
               "restart={$this->restartType->value}, status={$status}, " .
               "totalRestarts={$this->totalRestarts})";
    }

    // ═════════════════════════════════════════════════════════════════
    //  Internal: Restart intensity checking
    // ═════════════════════════════════════════════════════════════════

    /**
     * Check if we're within the restart intensity limit.
     *
     * Prunes old timestamps (older than $withinSeconds), then checks
     * if the count of recent restarts is below $maxRestarts.
     *
     * @return bool True if another restart is allowed.
     */
    private function checkIntensity(): bool
    {
        $this->pruneOldTimestamps();

        return count($this->restartTimestamps) < $this->maxRestarts;
    }

    /**
     * Remove restart timestamps older than the $withinSeconds window.
     */
    private function pruneOldTimestamps(): void
    {
        $cutoff = microtime(true) - $this->withinSeconds;

        $this->restartTimestamps = array_values(
            array_filter(
                $this->restartTimestamps,
                static fn(float $ts): bool => $ts >= $cutoff,
            ),
        );
    }

    /**
     * Get the restart timestamps within the current time window.
     *
     * @return float[]
     */
    private function getRecentRestarts(): array
    {
        $this->pruneOldTimestamps();
        return $this->restartTimestamps;
    }
}
