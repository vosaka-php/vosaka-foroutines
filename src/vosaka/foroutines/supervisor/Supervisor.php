<?php

declare(strict_types=1);

namespace vosaka\foroutines\supervisor;

use vosaka\foroutines\actor\Actor;
use vosaka\foroutines\actor\ActorBehavior;
use vosaka\foroutines\actor\ActorSystem;
use vosaka\foroutines\actor\Message;
use vosaka\foroutines\Dispatchers;
use vosaka\foroutines\Launch;
use vosaka\foroutines\Pause;
use vosaka\foroutines\FiberUtils;
use Closure;
use Fiber;
use RuntimeException;
use Throwable;

/**
 * Supervisor — OTP-style supervisor tree for fault-tolerant concurrency.
 *
 * Implements the "let it crash" philosophy from Erlang/OTP: instead of
 * writing defensive error-handling code throughout your application, you
 * let tasks crash and rely on a Supervisor to automatically restart them
 * according to a well-defined strategy.
 *
 * The Supervisor monitors child tasks (either Actor-based or Closure-based)
 * and reacts to failures using one of three restart strategies:
 *
 *   ┌─────────────────┬──────────────────────────────────────────────────┐
 *   │ ONE_FOR_ONE      │ Only the crashed child is restarted.            │
 *   │ ONE_FOR_ALL      │ ALL children are stopped and restarted.         │
 *   │ REST_FOR_ONE     │ The crashed child + all children after it       │
 *   │                  │ (in registration order) are restarted.          │
 *   └─────────────────┴──────────────────────────────────────────────────┘
 *
 * Each child is described by a {@see ChildSpec} that defines:
 *   - How to create the child (Actor behavior or Closure).
 *   - When to restart it (PERMANENT / TRANSIENT / TEMPORARY).
 *   - How many restarts are tolerated (maxRestarts within withinSeconds).
 *
 * The Supervisor itself has an intensity limit: if too many total child
 * restarts occur within a time window, the supervisor gives up and shuts
 * down all children — this prevents infinite restart cascades.
 *
 * Dispatcher support:
 *   Children can be configured to run on any VOsaka dispatcher:
 *   - Dispatchers::DEFAULT — Fiber-only cooperative scheduling.
 *   - Dispatchers::IO      — Offloaded to the WorkerPool.
 *   - Dispatchers::MAIN    — Run on the EventLoop.
 *
 * Usage:
 *   // Simple closure-based supervisor
 *   Supervisor::new(strategy: RestartStrategy::ONE_FOR_ONE)
 *       ->child('worker-a', fn() => workerA())
 *       ->child('worker-b', fn() => workerB())
 *       ->start();
 *
 *   // Actor-based supervisor with full specs
 *   $system = ActorSystem::create('my-app');
 *   Supervisor::new(
 *       strategy: RestartStrategy::REST_FOR_ONE,
 *       system: $system,
 *   )
 *       ->childSpec(
 *           ChildSpec::actor('db-conn', new DbConnectionBehavior())
 *               ->permanent()
 *               ->withMaxRestarts(5, withinSeconds: 30.0)
 *       )
 *       ->childSpec(
 *           ChildSpec::actor('repo', new RepositoryBehavior())
 *               ->permanent()
 *       )
 *       ->start();
 *
 *   // Mixed: actors + closures, with IO dispatcher
 *   Supervisor::new(strategy: RestartStrategy::ONE_FOR_ONE)
 *       ->child('bg-job', fn() => processQueue(), dispatcher: Dispatchers::IO)
 *       ->childSpec(
 *           ChildSpec::actor('handler', new HandlerBehavior())
 *               ->transient()
 *       )
 *       ->start();
 *
 * Nesting (Supervisor trees):
 *   Supervisors can be children of other supervisors. Wrap the inner
 *   supervisor's start() in a closure and add it as a child:
 *
 *   $root = Supervisor::new(strategy: RestartStrategy::ONE_FOR_ONE)
 *       ->child('sub-supervisor', function () {
 *           Supervisor::new(strategy: RestartStrategy::ONE_FOR_ALL)
 *               ->child('worker-1', fn() => work1())
 *               ->child('worker-2', fn() => work2())
 *               ->startAndAwait();
 *       })
 *       ->child('logger', fn() => logService())
 *       ->start();
 *
 * @see RestartStrategy  Defines WHICH children to restart on a crash.
 * @see RestartType      Defines WHEN a specific child should be restarted.
 * @see ChildSpec        The full specification for a supervised child.
 * @see Actor            The actor entity (for actor-mode children).
 * @see ActorSystem      The actor registry (optional, for actor-mode children).
 */
final class Supervisor
{
    // ─── Configuration ───────────────────────────────────────────────

    /**
     * The restart strategy for this supervisor.
     */
    private RestartStrategy $strategy;

    /**
     * The ActorSystem for actor-mode children.
     * Created lazily if not provided and an actor child is added.
     */
    private ?ActorSystem $system;

    /**
     * Supervisor-level max total restarts allowed within $supervisorWithinSeconds.
     * If exceeded, the supervisor shuts down all children.
     */
    private int $supervisorMaxRestarts;

    /**
     * Time window for supervisor-level restart intensity (seconds).
     */
    private float $supervisorWithinSeconds;

    /**
     * Default dispatcher for children that don't specify one.
     */
    private ?Dispatchers $defaultDispatcher;

    // ─── Child registry ──────────────────────────────────────────────

    /**
     * Ordered list of child specifications.
     * Order matters for REST_FOR_ONE and ONE_FOR_ALL strategies.
     *
     * @var array<string, ChildSpec>
     */
    private array $childSpecs = [];

    /**
     * Maintains insertion order (child names in registration order).
     *
     * @var string[]
     */
    private array $childOrder = [];

    /**
     * Active Actor instances for actor-mode children.
     * Maps child name => Actor.
     *
     * @var array<string, Actor>
     */
    private array $activeActors = [];

    /**
     * Active Launch jobs for closure-mode children.
     * Maps child name => Launch.
     *
     * @var array<string, Launch>
     */
    private array $activeLaunchJobs = [];

    /**
     * Error captured from closure-mode children (set by the wrapper fiber).
     * Maps child name => Throwable|null.
     *
     * @var array<string, Throwable|null>
     */
    private array $closureErrors = [];

    /**
     * Flags indicating a closure child has finished (normally or with error).
     * Maps child name => bool.
     *
     * @var array<string, bool>
     */
    private array $closureFinished = [];

    // ─── Supervisor state ────────────────────────────────────────────

    /**
     * Whether the supervisor has been started.
     */
    private bool $started = false;

    /**
     * Whether the supervisor is shutting down.
     */
    private bool $shuttingDown = false;

    /**
     * Supervisor-level restart timestamps for intensity limiting.
     *
     * @var float[]
     */
    private array $supervisorRestartTimestamps = [];

    /**
     * Total restarts performed by this supervisor (lifetime counter).
     */
    private int $totalRestarts = 0;

    /**
     * The monitoring fiber/Launch job that watches children.
     */
    private ?Launch $monitorJob = null;

    /**
     * Unique name for this supervisor (for logging).
     */
    private string $name;

    /**
     * Auto-increment counter for unnamed supervisors.
     */
    private static int $instanceCounter = 0;

    // ═════════════════════════════════════════════════════════════════
    //  Constructor & Factory
    // ═════════════════════════════════════════════════════════════════

    /**
     * @param RestartStrategy  $strategy               Restart strategy.
     * @param ActorSystem|null $system                  Actor system (optional).
     * @param int              $supervisorMaxRestarts   Max total restarts.
     * @param float            $supervisorWithinSeconds Time window for counting.
     * @param Dispatchers|null $defaultDispatcher       Default dispatcher for children.
     * @param string|null      $name                    Supervisor name (auto-generated if null).
     */
    private function __construct(
        RestartStrategy $strategy,
        ?ActorSystem $system,
        int $supervisorMaxRestarts,
        float $supervisorWithinSeconds,
        ?Dispatchers $defaultDispatcher,
        ?string $name,
    ) {
        $this->strategy = $strategy;
        $this->system = $system;
        $this->supervisorMaxRestarts = $supervisorMaxRestarts;
        $this->supervisorWithinSeconds = $supervisorWithinSeconds;
        $this->defaultDispatcher = $defaultDispatcher;
        $this->name = $name ?? 'supervisor-' . (++self::$instanceCounter);
    }

    /**
     * Create a new Supervisor.
     *
     * @param RestartStrategy  $strategy               The restart strategy (default: ONE_FOR_ONE).
     * @param ActorSystem|null $system                  Actor system for actor-mode children.
     *                                                  Created lazily if not provided.
     * @param int              $supervisorMaxRestarts   Max total restarts before supervisor gives up
     *                                                  (default: 10).
     * @param float            $supervisorWithinSeconds Time window for supervisor-level intensity
     *                                                  (default: 60.0 seconds).
     * @param Dispatchers|null $defaultDispatcher       Default dispatcher for children.
     * @param string|null      $name                    Name for logging (auto-generated if null).
     * @return self
     */
    public static function new(
        RestartStrategy $strategy = RestartStrategy::ONE_FOR_ONE,
        ?ActorSystem $system = null,
        int $supervisorMaxRestarts = 10,
        float $supervisorWithinSeconds = 60.0,
        ?Dispatchers $defaultDispatcher = null,
        ?string $name = null,
    ): self {
        return new self(
            strategy: $strategy,
            system: $system,
            supervisorMaxRestarts: max(1, $supervisorMaxRestarts),
            supervisorWithinSeconds: max(1.0, $supervisorWithinSeconds),
            defaultDispatcher: $defaultDispatcher,
            name: $name,
        );
    }

    // ═════════════════════════════════════════════════════════════════
    //  Child Registration (Builder API)
    // ═════════════════════════════════════════════════════════════════

    /**
     * Add a closure-based child with a simple API.
     *
     * This is a convenience method that creates a ChildSpec internally.
     * For full control over restart type, intensity, etc., use childSpec().
     *
     * @param string          $name       Unique child name.
     * @param Closure         $closure    The task to run and supervise.
     * @param RestartType     $restartType Restart behavior (default: PERMANENT).
     * @param Dispatchers|null $dispatcher Dispatcher override (null = use default).
     * @return self Fluent return.
     */
    public function child(
        string $name,
        Closure $closure,
        RestartType $restartType = RestartType::PERMANENT,
        ?Dispatchers $dispatcher = null,
    ): self {
        $spec = ChildSpec::closure($name, $closure)
            ->withRestartType($restartType);

        if ($dispatcher !== null) {
            $spec->withDispatcher($dispatcher);
        } elseif ($this->defaultDispatcher !== null) {
            $spec->withDispatcher($this->defaultDispatcher);
        }

        return $this->childSpec($spec);
    }

    /**
     * Add an actor-based child with a simple API.
     *
     * @param string          $name       Unique child name.
     * @param ActorBehavior   $behavior   The actor's message handler.
     * @param int             $capacity   Mailbox capacity (default: 50).
     * @param RestartType     $restartType Restart behavior (default: PERMANENT).
     * @param Dispatchers|null $dispatcher Dispatcher override.
     * @return self Fluent return.
     */
    public function actorChild(
        string $name,
        ActorBehavior $behavior,
        int $capacity = 50,
        RestartType $restartType = RestartType::PERMANENT,
        ?Dispatchers $dispatcher = null,
    ): self {
        $spec = ChildSpec::actor($name, $behavior)
            ->withRestartType($restartType)
            ->withCapacity($capacity);

        if ($dispatcher !== null) {
            $spec->withDispatcher($dispatcher);
        } elseif ($this->defaultDispatcher !== null) {
            $spec->withDispatcher($this->defaultDispatcher);
        }

        return $this->childSpec($spec);
    }

    /**
     * Add a child with a full ChildSpec.
     *
     * This is the most flexible way to add a child. The ChildSpec
     * builder allows full control over restart type, intensity limits,
     * mailbox capacity, and dispatcher.
     *
     * @param ChildSpec $spec The child specification.
     * @return self Fluent return.
     * @throws RuntimeException If a child with the same name already exists.
     */
    public function childSpec(ChildSpec $spec): self
    {
        $name = $spec->name;

        if (isset($this->childSpecs[$name])) {
            throw new RuntimeException(
                "Supervisor '{$this->name}': Duplicate child name '{$name}'. " .
                "Each child must have a unique name within a supervisor.",
            );
        }

        $spec->setOrderIndex(count($this->childOrder));
        $this->childSpecs[$name] = $spec;
        $this->childOrder[] = $name;

        return $this;
    }

    // ═════════════════════════════════════════════════════════════════
    //  Start / Stop
    // ═════════════════════════════════════════════════════════════════

    /**
     * Start the supervisor: launch all children and begin monitoring.
     *
     * Children are started in registration order. The supervisor then
     * enters a monitoring loop (in a Launch fiber) that checks for
     * child failures and applies the restart strategy.
     *
     * This method is non-blocking — it returns immediately after
     * launching the monitor fiber. Use startAndAwait() to block until
     * all children have finished.
     *
     * @return self Fluent return.
     * @throws RuntimeException If the supervisor is already started or has no children.
     */
    public function start(): self
    {
        if ($this->started && !$this->shuttingDown) {
            throw new RuntimeException(
                "Supervisor '{$this->name}' is already started.",
            );
        }

        if (empty($this->childSpecs)) {
            throw new RuntimeException(
                "Supervisor '{$this->name}': Cannot start with no children. " .
                "Add at least one child via child() or childSpec().",
            );
        }

        $this->started = true;
        $this->shuttingDown = false;

        // Ensure ActorSystem exists for actor-mode children
        $this->ensureSystem();

        // Start all children in registration order
        foreach ($this->childOrder as $childName) {
            $this->startChild($childName);
        }

        // Launch the monitoring loop
        $this->monitorJob = Launch::new(fn() => $this->monitoringLoop());

        return $this;
    }

    /**
     * Start the supervisor and block until all children have stopped.
     *
     * This is a convenience for scripts that want to run a supervisor
     * as the main program loop. Equivalent to:
     *   $supervisor->start();
     *   Thread::await();
     *
     * When called from within a Fiber, cooperatively yields.
     * When called from outside a Fiber, drives the scheduler manually.
     */
    public function startAndAwait(): void
    {
        $this->start();

        // Wait for the monitor to finish (which only happens on shutdown)
        while (!$this->shuttingDown && $this->started) {
            // Check if all children are done (for non-permanent children)
            $allDone = true;
            foreach ($this->childSpecs as $name => $spec) {
                if (!$spec->isRetired() && $this->isChildAlive($name)) {
                    $allDone = false;
                    break;
                }
                // Non-retired specs that aren't alive might still restart
                if (!$spec->isRetired() && $spec->getRestartType()->canRestart()) {
                    $allDone = false;
                    break;
                }
            }

            if ($allDone) {
                break;
            }

            if (Fiber::getCurrent() !== null) {
                Pause::force();
            } else {
                $this->driveScheduler();
                usleep(500);
            }
        }
    }

    /**
     * Gracefully shut down the supervisor and all its children.
     *
     * Children are stopped in reverse registration order (for clean
     * teardown of dependent services). The monitoring loop is then
     * terminated.
     *
     * @param float $gracePeriod Seconds to wait for graceful child stop (default: 5.0).
     */
    public function shutdown(float $gracePeriod = 5.0): void
    {
        if (!$this->started || $this->shuttingDown) {
            return;
        }

        $this->shuttingDown = true;

        // Stop children in reverse registration order
        $reversed = array_reverse($this->childOrder);

        foreach ($reversed as $childName) {
            $this->stopChild($childName);
        }

        // Wait for children to stop
        $deadline = microtime(true) + $gracePeriod;

        while (microtime(true) < $deadline) {
            $anyAlive = false;
            foreach ($this->childOrder as $childName) {
                if ($this->isChildAlive($childName)) {
                    $anyAlive = true;
                    break;
                }
            }

            if (!$anyAlive) {
                break;
            }

            if (Fiber::getCurrent() !== null) {
                Pause::force();
            } else {
                $this->driveScheduler();
                usleep(1_000);
            }
        }

        // Force-stop any remaining
        foreach ($this->childOrder as $childName) {
            $this->forceStopChild($childName);
        }

        $this->activeActors = [];
        $this->activeLaunchJobs = [];
        $this->closureErrors = [];
        $this->closureFinished = [];
        $this->started = false;
    }

    // ═════════════════════════════════════════════════════════════════
    //  Introspection
    // ═════════════════════════════════════════════════════════════════

    /**
     * Get the supervisor's name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the restart strategy.
     *
     * @return RestartStrategy
     */
    public function getStrategy(): RestartStrategy
    {
        return $this->strategy;
    }

    /**
     * Get the total number of restarts performed.
     *
     * @return int
     */
    public function getTotalRestarts(): int
    {
        return $this->totalRestarts;
    }

    /**
     * Check if the supervisor is currently running.
     *
     * @return bool
     */
    public function isRunning(): bool
    {
        return $this->started && !$this->shuttingDown;
    }

    /**
     * Get the number of registered children.
     *
     * @return int
     */
    public function childCount(): int
    {
        return count($this->childSpecs);
    }

    /**
     * Get the number of currently alive children.
     *
     * @return int
     */
    public function aliveChildCount(): int
    {
        $count = 0;
        foreach ($this->childOrder as $name) {
            if ($this->isChildAlive($name)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Get a child spec by name.
     *
     * @param string $name Child name.
     * @return ChildSpec|null
     */
    public function getChildSpec(string $name): ?ChildSpec
    {
        return $this->childSpecs[$name] ?? null;
    }

    /**
     * Get all child specs.
     *
     * @return array<string, ChildSpec>
     */
    public function getChildSpecs(): array
    {
        return $this->childSpecs;
    }

    /**
     * Get a snapshot of the supervisor's state for debugging/monitoring.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        $childStats = [];
        foreach ($this->childSpecs as $name => $spec) {
            $childStats[$name] = array_merge($spec->getStats(), [
                'alive' => $this->isChildAlive($name),
            ]);
        }

        return [
            'name' => $this->name,
            'strategy' => $this->strategy->value,
            'started' => $this->started,
            'shuttingDown' => $this->shuttingDown,
            'totalRestarts' => $this->totalRestarts,
            'childCount' => count($this->childSpecs),
            'aliveCount' => $this->aliveChildCount(),
            'supervisorMaxRestarts' => $this->supervisorMaxRestarts,
            'supervisorWithinSeconds' => $this->supervisorWithinSeconds,
            'children' => $childStats,
        ];
    }

    /**
     * Get a human-readable string representation.
     *
     * @return string
     */
    public function __toString(): string
    {
        $alive = $this->aliveChildCount();
        $total = count($this->childSpecs);
        $status = $this->shuttingDown ? 'SHUTTING_DOWN' : ($this->started ? 'RUNNING' : 'STOPPED');

        return "Supervisor(name={$this->name}, strategy={$this->strategy->value}, " .
               "status={$status}, children={$alive}/{$total}, " .
               "totalRestarts={$this->totalRestarts})";
    }

    // ═════════════════════════════════════════════════════════════════
    //  Internal: Child lifecycle
    // ═════════════════════════════════════════════════════════════════

    /**
     * Start a single child by name.
     *
     * Dispatches to startActorChild() or startClosureChild() depending
     * on the ChildSpec's mode.
     *
     * @param string $childName The child's name.
     */
    private function startChild(string $childName): void
    {
        $spec = $this->childSpecs[$childName] ?? null;
        if ($spec === null || $spec->isRetired()) {
            return;
        }

        try {
            if ($spec->isActorMode()) {
                $this->startActorChild($childName, $spec);
            } else {
                $this->startClosureChild($childName, $spec);
            }
        } catch (Throwable $e) {
            error_log(
                "Supervisor '{$this->name}': Failed to start child '{$childName}': " .
                $e->getMessage(),
            );
        }
    }

    /**
     * Start an actor-mode child.
     *
     * Creates an Actor, registers it with the ActorSystem, hooks up the
     * onStop callback for failure detection, and starts the actor.
     *
     * @param string    $childName Child name.
     * @param ChildSpec $spec      The child specification.
     */
    private function startActorChild(string $childName, ChildSpec $spec): void
    {
        $behavior = $spec->getBehavior();
        if ($behavior === null) {
            return;
        }

        // If there's already an active actor with this name, clean it up
        if (isset($this->activeActors[$childName])) {
            $this->cleanupActorChild($childName);
        }

        // Unregister old actor from system if present
        $this->system?->unregister($childName);

        $actor = new Actor($childName, $behavior, $spec->getCapacity());

        if ($this->system !== null) {
            $actor->setSystem($this->system);
            $this->system->register($actor);
        }

        // Hook into actor stop for failure detection
        $supervisorRef = $this;
        $actor->onStop(function (Actor $stoppedActor, ?Throwable $error) use ($supervisorRef, $childName): void {
            if (!$supervisorRef->isRunning()) {
                return;
            }

            // Queue the failure for the monitoring loop to handle
            $supervisorRef->handleChildFailure($childName, $error);
        });

        $actor->start($spec->getDispatcher());
        $this->activeActors[$childName] = $actor;
    }

    /**
     * Start a closure-mode child.
     *
     * Wraps the closure in an error-catching fiber via Launch::new().
     * The wrapper captures any exception so the monitoring loop can
     * detect failures and apply the restart strategy.
     *
     * @param string    $childName Child name.
     * @param ChildSpec $spec      The child specification.
     */
    private function startClosureChild(string $childName, ChildSpec $spec): void
    {
        $closure = $spec->getClosure();
        if ($closure === null) {
            return;
        }

        // Clean up previous run
        unset($this->activeLaunchJobs[$childName]);
        unset($this->closureErrors[$childName]);
        $this->closureFinished[$childName] = false;

        $supervisorRef = $this;
        $dispatcher = $spec->getDispatcher() ?? $this->defaultDispatcher ?? Dispatchers::DEFAULT;

        // Wrap the closure to capture errors and signal completion
        $wrappedClosure = static function () use ($closure, $supervisorRef, $childName): void {
            $error = null;
            try {
                $closure();
            } catch (Throwable $e) {
                $error = $e;
            }

            // Signal completion to the supervisor
            $supervisorRef->closureErrors[$childName] = $error;
            $supervisorRef->closureFinished[$childName] = true;
        };

        $job = Launch::new($wrappedClosure, $dispatcher);
        $this->activeLaunchJobs[$childName] = $job;
    }

    /**
     * Stop a child gracefully.
     *
     * @param string $childName Child name.
     */
    private function stopChild(string $childName): void
    {
        // Actor-mode
        if (isset($this->activeActors[$childName])) {
            $actor = $this->activeActors[$childName];
            if ($actor->isAlive()) {
                $actor->requestStop();
            }
        }

        // Closure-mode
        if (isset($this->activeLaunchJobs[$childName])) {
            $job = $this->activeLaunchJobs[$childName];
            if (!$job->isFinal()) {
                $job->cancel();
            }
            $this->closureFinished[$childName] = true;
        }
    }

    /**
     * Force-stop a child (close mailbox, cancel job).
     *
     * @param string $childName Child name.
     */
    private function forceStopChild(string $childName): void
    {
        if (isset($this->activeActors[$childName])) {
            $actor = $this->activeActors[$childName];
            if ($actor->isAlive() && !$actor->mailbox->isClosed()) {
                $actor->mailbox->close();
            }
        }

        if (isset($this->activeLaunchJobs[$childName])) {
            $job = $this->activeLaunchJobs[$childName];
            if (!$job->isFinal()) {
                try {
                    $job->cancel();
                } catch (Throwable) {
                    // Best effort
                }
            }
            $this->closureFinished[$childName] = true;
        }
    }

    /**
     * Clean up resources for an actor child (before restart or shutdown).
     *
     * @param string $childName Child name.
     */
    private function cleanupActorChild(string $childName): void
    {
        $actor = $this->activeActors[$childName] ?? null;
        if ($actor === null) {
            return;
        }

        if ($actor->isAlive()) {
            $actor->requestStop();
        }

        if (!$actor->mailbox->isClosed()) {
            $actor->mailbox->close();
        }

        unset($this->activeActors[$childName]);
    }

    /**
     * Check if a child is currently alive.
     *
     * @param string $childName Child name.
     * @return bool True if the child is alive and running.
     */
    private function isChildAlive(string $childName): bool
    {
        // Actor-mode
        if (isset($this->activeActors[$childName])) {
            return $this->activeActors[$childName]->isAlive();
        }

        // Closure-mode
        if (isset($this->activeLaunchJobs[$childName])) {
            return !($this->closureFinished[$childName] ?? true);
        }

        return false;
    }

    // ═════════════════════════════════════════════════════════════════
    //  Internal: Monitoring loop
    // ═════════════════════════════════════════════════════════════════

    /**
     * The main monitoring loop that watches children and applies
     * restart strategies on failure.
     *
     * Runs as a Launch fiber. Periodically checks all children's status
     * and handles any failures that the onStop/closureFinished callbacks
     * have reported.
     */
    private function monitoringLoop(): void
    {
        while (!$this->shuttingDown) {
            // Check closure-mode children for completion
            foreach ($this->childOrder as $childName) {
                $spec = $this->childSpecs[$childName] ?? null;
                if ($spec === null || $spec->isActorMode()) {
                    continue;
                }

                if ($this->closureFinished[$childName] ?? false) {
                    $error = $this->closureErrors[$childName] ?? null;
                    $this->closureFinished[$childName] = false; // Reset flag

                    if (!$this->shuttingDown) {
                        $this->handleChildFailure($childName, $error);
                    }
                }
            }

            // Check actor-mode children that might have stopped without
            // triggering the onStop callback (e.g. mailbox closed externally)
            foreach ($this->childOrder as $childName) {
                $spec = $this->childSpecs[$childName] ?? null;
                if ($spec === null || $spec->isClosureMode()) {
                    continue;
                }

                $actor = $this->activeActors[$childName] ?? null;
                if ($actor !== null && $actor->isStopped() && !$spec->isRetired()) {
                    // Actor stopped but hasn't been handled yet
                    // The onStop callback may have already queued this,
                    // but we double-check here as a safety net
                }
            }

            // Yield to let other fibers run
            Pause::force();
        }
    }

    // ═════════════════════════════════════════════════════════════════
    //  Internal: Failure handling & restart strategies
    // ═════════════════════════════════════════════════════════════════

    /**
     * Handle a child failure. This is the core of the supervisor logic.
     *
     * 1. Checks if the child's ChildSpec says it should be restarted.
     * 2. Checks the supervisor-level restart intensity.
     * 3. Applies the restart strategy (ONE_FOR_ONE, ONE_FOR_ALL, REST_FOR_ONE).
     *
     * This method is public so that the onStop callback (which runs
     * inside the actor's fiber) can call it directly. It is NOT part of
     * the public API — it is @internal.
     *
     * @param string         $childName The child that failed.
     * @param Throwable|null $error     The error, or null for normal exit.
     * @internal
     */
    public function handleChildFailure(string $childName, ?Throwable $error): void
    {
        if ($this->shuttingDown) {
            return;
        }

        $spec = $this->childSpecs[$childName] ?? null;
        if ($spec === null) {
            return;
        }

        // Log the failure
        if ($error !== null) {
            error_log(
                "Supervisor '{$this->name}': Child '{$childName}' crashed: " .
                $error->getMessage(),
            );
        } else {
            error_log(
                "Supervisor '{$this->name}': Child '{$childName}' exited normally.",
            );
        }

        // Check if the child should be restarted
        if (!$spec->shouldRestart($error)) {
            error_log(
                "Supervisor '{$this->name}': Child '{$childName}' will NOT be " .
                "restarted (restart type: {$spec->getRestartType()->value}" .
                ($spec->isRetired() ? ', RETIRED' : '') . ").",
            );
            return;
        }

        // Check supervisor-level restart intensity
        if (!$this->checkSupervisorIntensity()) {
            error_log(
                "Supervisor '{$this->name}': SUPERVISOR INTENSITY EXCEEDED — " .
                "too many total restarts ({$this->supervisorMaxRestarts} within " .
                "{$this->supervisorWithinSeconds}s). Shutting down all children.",
            );
            $this->shutdown();
            return;
        }

        // Apply the restart strategy
        $this->applyStrategy($childName, $error);
    }

    /**
     * Apply the configured restart strategy.
     *
     * @param string         $childName The child that triggered the restart.
     * @param Throwable|null $error     The error that caused the failure.
     */
    private function applyStrategy(string $childName, ?Throwable $error): void
    {
        match ($this->strategy) {
            RestartStrategy::ONE_FOR_ONE => $this->applyOneForOne($childName),
            RestartStrategy::ONE_FOR_ALL => $this->applyOneForAll($childName),
            RestartStrategy::REST_FOR_ONE => $this->applyRestForOne($childName),
        };
    }

    /**
     * ONE_FOR_ONE: Restart only the crashed child.
     *
     * @param string $childName The crashed child's name.
     */
    private function applyOneForOne(string $childName): void
    {
        $spec = $this->childSpecs[$childName];

        error_log(
            "Supervisor '{$this->name}': [ONE_FOR_ONE] Restarting '{$childName}' " .
            "(restart #{$spec->getTotalRestarts()} + 1).",
        );

        $this->restartChild($childName);
    }

    /**
     * ONE_FOR_ALL: Stop ALL children, then restart ALL in original order.
     *
     * @param string $crashedChild The child that triggered the restart.
     */
    private function applyOneForAll(string $crashedChild): void
    {
        error_log(
            "Supervisor '{$this->name}': [ONE_FOR_ALL] Child '{$crashedChild}' crashed — " .
            "stopping and restarting ALL children.",
        );

        // Stop all children in reverse order
        $reversed = array_reverse($this->childOrder);
        foreach ($reversed as $childName) {
            if ($childName !== $crashedChild && $this->isChildAlive($childName)) {
                $this->stopChild($childName);
            }
        }

        // Wait briefly for children to stop
        $this->waitForChildrenToStop(2.0);

        // Force-stop any stragglers
        foreach ($this->childOrder as $childName) {
            if ($this->isChildAlive($childName)) {
                $this->forceStopChild($childName);
            }
        }

        // Restart all children in original order
        foreach ($this->childOrder as $childName) {
            $spec = $this->childSpecs[$childName];
            if (!$spec->isRetired()) {
                // Only record restart for the crashed child; others are
                // collateral restarts that shouldn't count against their
                // individual intensity limits.
                if ($childName === $crashedChild) {
                    $spec->recordRestart();
                    $this->recordSupervisorRestart();
                }

                $this->startChild($childName);
            }
        }
    }

    /**
     * REST_FOR_ONE: Stop the crashed child and all children registered
     * AFTER it, then restart them in original order.
     *
     * @param string $crashedChild The child that triggered the restart.
     */
    private function applyRestForOne(string $crashedChild): void
    {
        // Find the crashed child's order index
        $crashedIndex = -1;
        foreach ($this->childOrder as $i => $name) {
            if ($name === $crashedChild) {
                $crashedIndex = $i;
                break;
            }
        }

        if ($crashedIndex === -1) {
            return;
        }

        // Identify children to restart: crashed child + everything after
        $toRestart = array_slice($this->childOrder, $crashedIndex);

        error_log(
            "Supervisor '{$this->name}': [REST_FOR_ONE] Child '{$crashedChild}' crashed — " .
            "restarting " . count($toRestart) . " child(ren): " .
            implode(', ', $toRestart) . ".",
        );

        // Stop affected children in reverse order
        $toStopReversed = array_reverse($toRestart);
        foreach ($toStopReversed as $childName) {
            if ($childName !== $crashedChild && $this->isChildAlive($childName)) {
                $this->stopChild($childName);
            }
        }

        // Wait briefly
        $this->waitForChildrenToStop(2.0);

        // Force-stop stragglers
        foreach ($toRestart as $childName) {
            if ($this->isChildAlive($childName)) {
                $this->forceStopChild($childName);
            }
        }

        // Restart in original order
        foreach ($toRestart as $childName) {
            $spec = $this->childSpecs[$childName];
            if (!$spec->isRetired()) {
                if ($childName === $crashedChild) {
                    $spec->recordRestart();
                    $this->recordSupervisorRestart();
                }

                $this->startChild($childName);
            }
        }
    }

    /**
     * Restart a single child (used by ONE_FOR_ONE).
     *
     * @param string $childName The child to restart.
     */
    private function restartChild(string $childName): void
    {
        $spec = $this->childSpecs[$childName];

        $spec->recordRestart();
        $this->recordSupervisorRestart();

        // Clean up the old instance
        if ($spec->isActorMode()) {
            $this->cleanupActorChild($childName);
        }

        // Start a fresh instance
        $this->startChild($childName);
    }

    // ═════════════════════════════════════════════════════════════════
    //  Internal: Supervisor intensity limiting
    // ═════════════════════════════════════════════════════════════════

    /**
     * Check if the supervisor is within its restart intensity limit.
     *
     * @return bool True if another restart is allowed.
     */
    private function checkSupervisorIntensity(): bool
    {
        $this->pruneSupervisorTimestamps();
        return count($this->supervisorRestartTimestamps) < $this->supervisorMaxRestarts;
    }

    /**
     * Record a supervisor-level restart event.
     */
    private function recordSupervisorRestart(): void
    {
        $this->supervisorRestartTimestamps[] = microtime(true);
        $this->totalRestarts++;
    }

    /**
     * Remove old supervisor restart timestamps outside the time window.
     */
    private function pruneSupervisorTimestamps(): void
    {
        $cutoff = microtime(true) - $this->supervisorWithinSeconds;

        $this->supervisorRestartTimestamps = array_values(
            array_filter(
                $this->supervisorRestartTimestamps,
                static fn(float $ts): bool => $ts >= $cutoff,
            ),
        );
    }

    // ═════════════════════════════════════════════════════════════════
    //  Internal: Utility
    // ═════════════════════════════════════════════════════════════════

    /**
     * Wait for all children to stop (with timeout).
     *
     * @param float $timeoutSeconds Maximum wait time.
     */
    private function waitForChildrenToStop(float $timeoutSeconds): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $anyAlive = false;
            foreach ($this->childOrder as $name) {
                if ($this->isChildAlive($name)) {
                    $anyAlive = true;
                    break;
                }
            }

            if (!$anyAlive) {
                return;
            }

            if (Fiber::getCurrent() !== null) {
                Pause::force();
            } else {
                $this->driveScheduler();
                usleep(500);
            }
        }
    }

    /**
     * Ensure an ActorSystem exists (create lazily if needed).
     */
    private function ensureSystem(): void
    {
        // Check if any child is actor-mode
        $needsSystem = false;
        foreach ($this->childSpecs as $spec) {
            if ($spec->isActorMode()) {
                $needsSystem = true;
                break;
            }
        }

        if ($needsSystem && $this->system === null) {
            $this->system = ActorSystem::create('__supervisor_' . $this->name);
        }
    }

    /**
     * Drive the VOsaka scheduler manually (for use outside Fiber context).
     */
    private function driveScheduler(): void
    {
        if (\vosaka\foroutines\AsyncIO::hasPending()) {
            \vosaka\foroutines\AsyncIO::pollOnce();
        }

        if (!\vosaka\foroutines\WorkerPool::isEmpty()) {
            \vosaka\foroutines\WorkerPool::run();
        }

        if (Launch::getInstance()->hasActiveTasks()) {
            Launch::getInstance()->runOnce();
        }
    }
}
