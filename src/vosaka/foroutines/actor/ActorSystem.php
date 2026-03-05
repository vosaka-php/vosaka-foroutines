<?php

declare(strict_types=1);

namespace vosaka\foroutines\actor;

use vosaka\foroutines\Launch;
use vosaka\foroutines\Dispatchers;
use vosaka\foroutines\Pause;
use vosaka\foroutines\Thread;
use RuntimeException;
use Throwable;

/**
 * ActorSystem — The registry, lifecycle manager, and entry point for actors.
 *
 * An ActorSystem is the top-level container that manages a group of actors.
 * It provides:
 *
 *   - **Actor registry**: A name → Actor mapping so that actors can look up
 *     and send messages to each other by name.
 *   - **Spawning**: Creates Actor instances, registers them, and starts their
 *     processing loops.
 *   - **Lifecycle coordination**: Provides awaitAll() to block until all actors
 *     have stopped, and shutdown() to gracefully stop everything.
 *   - **Dead letter handling**: Messages sent to non-existent actors can be
 *     routed to a configurable dead-letter handler.
 *
 * Design principles:
 *   - One ActorSystem per application (or per isolated subsystem).
 *   - Actor names are unique within a system — spawning with a duplicate
 *     name throws an exception.
 *   - The system does NOT own the scheduler. It delegates to VOsaka's existing
 *     Launch / RunBlocking / Thread infrastructure for Fiber scheduling.
 *   - Thread-safe within PHP's cooperative concurrency model (single-threaded
 *     Fibers). No locks are needed because only one Fiber runs at a time.
 *
 * Usage:
 *   $system = ActorSystem::create('my-app');
 *
 *   $system->spawn('greeter', new GreeterBehavior(), capacity: 100);
 *   $system->spawn('counter', new CounterBehavior());
 *
 *   // Send messages
 *   $system->tell('greeter', Message::of('greet', 'World'));
 *   $system->tell('counter', Message::of('increment'));
 *
 *   // Wait for all actors to finish (or use inside RunBlocking)
 *   $system->awaitAll();
 *
 *   // Or shut down everything
 *   $system->shutdown();
 *
 * Integration with Supervisor:
 *   A Supervisor can be registered as an actor within the system. When it
 *   detects a child failure, it uses the system's spawn/stop methods to
 *   restart actors according to its restart strategy.
 *
 * @see Actor          The core actor entity.
 * @see ActorBehavior  The interface defining message handling logic.
 * @see ActorContext    The runtime context passed to behaviors.
 * @see Message         The envelope for actor-to-actor communication.
 */
final class ActorSystem
{
    // ─── State ───────────────────────────────────────────────────────

    /**
     * The system's unique name (for logging/debugging).
     */
    public readonly string $name;

    /**
     * Actor registry: maps actor name → Actor instance.
     *
     * @var array<string, Actor>
     */
    private array $actors = [];

    /**
     * Whether the system has been shut down.
     */
    private bool $isShutdown = false;

    /**
     * Dead-letter handler — invoked when a message is sent to a
     * non-existent actor via tell(). If null, dead letters are silently
     * discarded (with an error_log warning).
     *
     * Signature: fn(string $targetName, Message $message): void
     *
     * @var (callable(string, Message): void)|null
     */
    private $deadLetterHandler = null;

    /**
     * Count of dead letters received since system creation.
     */
    private int $deadLetterCount = 0;

    /**
     * Timestamp when the system was created.
     */
    private float $createdAt;

    // ═════════════════════════════════════════════════════════════════
    //  Constructor & Factory
    // ═════════════════════════════════════════════════════════════════

    /**
     * @param string $name The system's unique name.
     */
    private function __construct(string $name)
    {
        $this->name = $name;
        $this->createdAt = microtime(true);
    }

    /**
     * Create a new ActorSystem.
     *
     * @param string $name A descriptive name for this system (used in logs).
     * @return self
     */
    public static function create(string $name = 'default'): self
    {
        return new self($name);
    }

    // ═════════════════════════════════════════════════════════════════
    //  Spawning & Registration
    // ═════════════════════════════════════════════════════════════════

    /**
     * Spawn a new actor: create, register, and start it.
     *
     * This is the primary way to create actors within a system. The actor
     * is immediately started and begins processing messages from its mailbox.
     *
     * @param string        $name     Unique name for the actor within this system.
     * @param ActorBehavior $behavior The message handler implementation.
     * @param int           $capacity Mailbox capacity (default: 50).
     *                                0 = unbuffered (rendezvous), N = buffered.
     * @param Dispatchers|null $dispatcher The dispatcher for the processing loop.
     *                                     null = Dispatchers::DEFAULT.
     * @return Actor The spawned and started actor.
     * @throws RuntimeException If the system is shut down or the name is taken.
     */
    public function spawn(
        string $name,
        ActorBehavior $behavior,
        int $capacity = 50,
        ?Dispatchers $dispatcher = null,
    ): Actor {
        $this->ensureNotShutdown('spawn');

        if (isset($this->actors[$name])) {
            throw new RuntimeException(
                "ActorSystem '{$this->name}': Cannot spawn actor '{$name}' — " .
                "an actor with this name already exists. Use a unique name or " .
                "stop the existing actor first.",
            );
        }

        $actor = new Actor($name, $behavior, $capacity);
        $actor->setSystem($this);

        $this->actors[$name] = $actor;

        // Start the actor's processing loop
        $actor->start($dispatcher);

        return $actor;
    }

    /**
     * Register an existing Actor with this system (without starting it).
     *
     * Used internally by Actor::ensureContext() for standalone actors,
     * and by Supervisor for actors it manages.
     *
     * @param Actor $actor The actor to register.
     * @throws RuntimeException If the name is already taken.
     * @internal
     */
    public function register(Actor $actor): void
    {
        $name = $actor->name;

        if (isset($this->actors[$name])) {
            // If the same Actor instance is already registered, no-op
            if ($this->actors[$name] === $actor) {
                return;
            }

            throw new RuntimeException(
                "ActorSystem '{$this->name}': Cannot register actor '{$name}' — " .
                "an actor with this name already exists.",
            );
        }

        $this->actors[$name] = $actor;
    }

    /**
     * Unregister an actor from the system (without stopping it).
     *
     * Typically called after an actor has fully stopped to free the name
     * slot for potential reuse (e.g. by a supervisor restarting the actor).
     *
     * @param string $name The actor's name.
     * @return bool True if the actor was found and unregistered.
     */
    public function unregister(string $name): bool
    {
        if (!isset($this->actors[$name])) {
            return false;
        }

        unset($this->actors[$name]);
        return true;
    }

    // ═════════════════════════════════════════════════════════════════
    //  Actor Lookup
    // ═════════════════════════════════════════════════════════════════

    /**
     * Look up an actor by name.
     *
     * @param string $name The actor's name.
     * @return Actor|null The actor if found, null otherwise.
     */
    public function getActor(string $name): ?Actor
    {
        return $this->actors[$name] ?? null;
    }

    /**
     * Check if an actor with the given name exists.
     *
     * @param string $name The actor's name.
     * @return bool True if the actor is registered.
     */
    public function hasActor(string $name): bool
    {
        return isset($this->actors[$name]);
    }

    /**
     * Get all registered actor names.
     *
     * @return string[] Array of actor names.
     */
    public function getAllActorNames(): array
    {
        return array_keys($this->actors);
    }

    /**
     * Get all registered actors.
     *
     * @return array<string, Actor> Map of name → Actor.
     */
    public function getAllActors(): array
    {
        return $this->actors;
    }

    /**
     * Get the number of registered actors.
     *
     * @return int
     */
    public function actorCount(): int
    {
        return count($this->actors);
    }

    /**
     * Get the number of currently alive (started and not stopped) actors.
     *
     * @return int
     */
    public function aliveCount(): int
    {
        $count = 0;
        foreach ($this->actors as $actor) {
            if ($actor->isAlive()) {
                $count++;
            }
        }
        return $count;
    }

    // ═════════════════════════════════════════════════════════════════
    //  Messaging
    // ═════════════════════════════════════════════════════════════════

    /**
     * Send a message to an actor by name (fire-and-forget).
     *
     * If the target actor doesn't exist, the message is routed to the
     * dead-letter handler (if configured) or silently dropped with a
     * warning log.
     *
     * @param string  $actorName The target actor's name.
     * @param Message $message   The message to send.
     * @return bool True if the message was delivered, false if dead-lettered.
     */
    public function tell(string $actorName, Message $message): bool
    {
        $actor = $this->actors[$actorName] ?? null;

        if ($actor === null) {
            $this->handleDeadLetter($actorName, $message);
            return false;
        }

        // If the actor is stopped, treat as dead letter
        if ($actor->isStopped()) {
            $this->handleDeadLetter($actorName, $message);
            return false;
        }

        try {
            $actor->send($message);
            return true;
        } catch (Throwable $e) {
            // Mailbox closed between our check and the send — dead letter
            $this->handleDeadLetter($actorName, $message);
            return false;
        }
    }

    /**
     * Try to send a message to an actor without blocking.
     *
     * Returns false if the target doesn't exist, is stopped, or the
     * mailbox is full. Does NOT route to dead-letter handler.
     *
     * @param string  $actorName The target actor's name.
     * @param Message $message   The message to send.
     * @return bool True if the message was enqueued.
     */
    public function tryTell(string $actorName, Message $message): bool
    {
        $actor = $this->actors[$actorName] ?? null;

        if ($actor === null || $actor->isStopped()) {
            return false;
        }

        return $actor->trySend($message);
    }

    /**
     * Broadcast a message to all registered actors.
     *
     * @param Message      $message     The message to broadcast.
     * @param string|null  $excludeName Actor name to exclude (e.g. sender).
     */
    public function broadcast(Message $message, ?string $excludeName = null): void
    {
        foreach ($this->actors as $name => $actor) {
            if ($name === $excludeName) {
                continue;
            }

            if ($actor->isAlive()) {
                try {
                    $actor->send($message);
                } catch (Throwable) {
                    // Best-effort — skip actors whose mailboxes are closed
                }
            }
        }
    }

    // ═════════════════════════════════════════════════════════════════
    //  Lifecycle Management
    // ═════════════════════════════════════════════════════════════════

    /**
     * Wait until all actors in the system have stopped.
     *
     * This method cooperatively yields (via Pause::force) when called from
     * within a Fiber, or drives the scheduler manually when called from
     * outside a Fiber.
     *
     * Useful inside RunBlocking or at the end of a script to ensure all
     * actor work completes before the process exits.
     *
     * @param float $timeoutSeconds Maximum seconds to wait (0 = infinite).
     * @return bool True if all actors stopped, false if timeout was reached.
     */
    public function awaitAll(float $timeoutSeconds = 0.0): bool
    {
        $deadline = $timeoutSeconds > 0.0
            ? microtime(true) + $timeoutSeconds
            : PHP_FLOAT_MAX;

        while ($this->aliveCount() > 0) {
            if (microtime(true) >= $deadline) {
                return false;
            }

            if (\Fiber::getCurrent() !== null) {
                Pause::force();
            } else {
                // Outside a Fiber — drive the scheduler manually
                $this->driveScheduler();
                usleep(500);
            }
        }

        return true;
    }

    /**
     * Wait until a specific actor has stopped.
     *
     * @param string $actorName The actor to wait for.
     * @param float  $timeoutSeconds Maximum seconds to wait (0 = infinite).
     * @return bool True if the actor stopped, false if timeout or not found.
     */
    public function awaitActor(string $actorName, float $timeoutSeconds = 0.0): bool
    {
        $actor = $this->actors[$actorName] ?? null;
        if ($actor === null) {
            return false;
        }

        $deadline = $timeoutSeconds > 0.0
            ? microtime(true) + $timeoutSeconds
            : PHP_FLOAT_MAX;

        while ($actor->isAlive()) {
            if (microtime(true) >= $deadline) {
                return false;
            }

            if (\Fiber::getCurrent() !== null) {
                Pause::force();
            } else {
                $this->driveScheduler();
                usleep(500);
            }
        }

        return true;
    }

    /**
     * Gracefully shut down the entire system.
     *
     * 1. Sends a stop request to all alive actors.
     * 2. Waits for all actors to stop (with timeout).
     * 3. Force-closes any actors still alive after timeout.
     * 4. Clears the registry.
     *
     * @param float $gracePeriod Seconds to wait for graceful stop (default: 5.0).
     */
    public function shutdown(float $gracePeriod = 5.0): void
    {
        if ($this->isShutdown) {
            return;
        }

        $this->isShutdown = true;

        // Phase 1: Request all actors to stop
        foreach ($this->actors as $actor) {
            if ($actor->isAlive()) {
                $actor->requestStop();
            }
        }

        // Phase 2: Wait for graceful stop
        $deadline = microtime(true) + $gracePeriod;

        while ($this->aliveCount() > 0 && microtime(true) < $deadline) {
            if (\Fiber::getCurrent() !== null) {
                Pause::force();
            } else {
                $this->driveScheduler();
                usleep(1_000);
            }
        }

        // Phase 3: Force-close any remaining actors
        $stillAlive = 0;
        foreach ($this->actors as $actor) {
            if ($actor->isAlive()) {
                $stillAlive++;
                // Close the mailbox to force the processing loop to exit
                if (!$actor->mailbox->isClosed()) {
                    $actor->mailbox->close();
                }
            }
        }

        if ($stillAlive > 0) {
            error_log(
                "ActorSystem '{$this->name}': Force-closed {$stillAlive} " .
                "actor(s) that did not stop within {$gracePeriod}s grace period.",
            );
        }

        // Phase 4: Clear registry
        $this->actors = [];
    }

    /**
     * Stop a specific actor by name.
     *
     * Requests the actor to stop gracefully and unregisters it from
     * the system. Does not wait for the actor to fully stop — use
     * awaitActor() after this if you need to wait.
     *
     * @param string $name The actor's name.
     * @return bool True if the actor was found and stop was requested.
     */
    public function stop(string $name): bool
    {
        $actor = $this->actors[$name] ?? null;
        if ($actor === null) {
            return false;
        }

        $actor->requestStop();
        return true;
    }

    /**
     * Stop and unregister a specific actor.
     *
     * Unlike stop(), this also removes the actor from the registry
     * immediately, freeing the name for reuse.
     *
     * @param string $name The actor's name.
     * @return bool True if the actor was found.
     */
    public function stopAndUnregister(string $name): bool
    {
        $actor = $this->actors[$name] ?? null;
        if ($actor === null) {
            return false;
        }

        $actor->requestStop();
        unset($this->actors[$name]);
        return true;
    }

    /**
     * Check if the system has been shut down.
     *
     * @return bool
     */
    public function isShutdown(): bool
    {
        return $this->isShutdown;
    }

    // ═════════════════════════════════════════════════════════════════
    //  Dead Letter Handling
    // ═════════════════════════════════════════════════════════════════

    /**
     * Set a custom dead-letter handler.
     *
     * Dead letters are messages that cannot be delivered because the
     * target actor doesn't exist or has stopped. By default, they are
     * logged via error_log(). Set a custom handler to implement
     * dead-letter queues, monitoring, or retry logic.
     *
     * @param callable(string, Message): void $handler
     * @return self Fluent return.
     */
    public function setDeadLetterHandler(callable $handler): self
    {
        $this->deadLetterHandler = $handler;
        return $this;
    }

    /**
     * Get the count of dead letters since system creation.
     *
     * @return int
     */
    public function getDeadLetterCount(): int
    {
        return $this->deadLetterCount;
    }

    // ═════════════════════════════════════════════════════════════════
    //  Statistics & Debugging
    // ═════════════════════════════════════════════════════════════════

    /**
     * Get a snapshot of the system's state for debugging/monitoring.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        $actorStats = [];
        foreach ($this->actors as $name => $actor) {
            $actorStats[$name] = $actor->getStats();
        }

        return [
            'name' => $this->name,
            'isShutdown' => $this->isShutdown,
            'actorCount' => count($this->actors),
            'aliveCount' => $this->aliveCount(),
            'deadLetterCount' => $this->deadLetterCount,
            'uptimeSeconds' => microtime(true) - $this->createdAt,
            'actors' => $actorStats,
        ];
    }

    /**
     * Get a human-readable summary string for logging.
     *
     * @return string
     */
    public function __toString(): string
    {
        $alive = $this->aliveCount();
        $total = count($this->actors);
        $status = $this->isShutdown ? 'SHUTDOWN' : 'RUNNING';

        return "ActorSystem(name={$this->name}, status={$status}, " .
               "actors={$alive}/{$total}, deadLetters={$this->deadLetterCount})";
    }

    // ═════════════════════════════════════════════════════════════════
    //  Internal
    // ═════════════════════════════════════════════════════════════════

    /**
     * Handle a dead letter (message that couldn't be delivered).
     *
     * @param string  $targetName The intended recipient.
     * @param Message $message    The undeliverable message.
     */
    private function handleDeadLetter(string $targetName, Message $message): void
    {
        $this->deadLetterCount++;

        if ($this->deadLetterHandler !== null) {
            try {
                ($this->deadLetterHandler)($targetName, $message);
            } catch (Throwable $e) {
                error_log(
                    "ActorSystem '{$this->name}': Dead letter handler threw: " .
                    $e->getMessage(),
                );
            }
            return;
        }

        // Default: log a warning
        error_log(
            "ActorSystem '{$this->name}': Dead letter — " .
            "target='{$targetName}', type='{$message->type}'" .
            ($message->sender !== null ? ", sender='{$message->sender}'" : '') .
            ".",
        );
    }

    /**
     * Drive the VOsaka scheduler manually (for use outside Fiber context).
     *
     * Ticks the three subsystems that the cooperative scheduler relies on:
     *   1. AsyncIO — non-blocking stream I/O watchers
     *   2. WorkerPool — IO-dispatched child processes
     *   3. Launch — cooperative fiber scheduler queue
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

    /**
     * Guard: throw if the system is shut down.
     *
     * @param string $operation Description of the attempted operation.
     * @throws RuntimeException
     */
    private function ensureNotShutdown(string $operation): void
    {
        if ($this->isShutdown) {
            throw new RuntimeException(
                "ActorSystem '{$this->name}': Cannot {$operation} — " .
                "the system has been shut down.",
            );
        }
    }
}
