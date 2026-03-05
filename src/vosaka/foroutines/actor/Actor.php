<?php

declare(strict_types=1);

namespace vosaka\foroutines\actor;

use vosaka\foroutines\channel\Channel;
use vosaka\foroutines\Pause;
use vosaka\foroutines\Launch;
use vosaka\foroutines\Dispatchers;
use RuntimeException;
use Throwable;

/**
 * Actor — The core actor entity with a Channel-based mailbox and Fiber-driven processing loop.
 *
 * Each Actor encapsulates:
 *   - A **name** (unique identifier within an ActorSystem).
 *   - A **mailbox** (Channel) for receiving messages in FIFO order.
 *   - A **behavior** (ActorBehavior) that defines how messages are processed.
 *   - A **Fiber-based processing loop** that dequeues messages from the mailbox
 *     and delegates them to the behavior's handle() method.
 *
 * Lifecycle:
 *   1. **Created** — Actor is constructed but not yet processing messages.
 *   2. **Started** — The processing loop Fiber is launched. The behavior's
 *      preStart() hook is called, then the actor begins dequeueing messages.
 *   3. **Running** — The actor is actively processing messages from its mailbox.
 *      Each message is dispatched to the behavior's handle() method. If handle()
 *      throws, the behavior's onError() decides whether to continue or stop.
 *   4. **Stopping** — requestStop() has been called. The actor finishes the
 *      current message, then exits the processing loop.
 *   5. **Stopped** — The behavior's postStop() hook has been called. The mailbox
 *      is closed. The actor can be restarted by the Supervisor if supervised.
 *
 * Design principles:
 *   - **Single-threaded per actor**: Each actor runs in exactly one Fiber.
 *     No two messages are ever processed concurrently within the same actor,
 *     eliminating the need for locks or synchronization on internal state.
 *   - **Asynchronous messaging**: send() is non-blocking — it places the message
 *     into the mailbox Channel and returns immediately.
 *   - **Cooperative scheduling**: The processing loop uses Pause::force() between
 *     messages so that other actors (Fibers) get CPU time. This integrates
 *     naturally with VOsaka's existing scheduler (Launch, RunBlocking, Thread).
 *   - **Let-it-crash**: When a message handler throws and onError() returns false,
 *     the actor stops. A Supervisor (if configured) can then restart it according
 *     to its restart strategy.
 *   - **Dispatcher-aware**: The processing loop can run on DEFAULT (Fiber-only)
 *     or IO (WorkerPool) dispatchers, specified at start() time.
 *
 * Usage:
 *   // Standalone (no system):
 *   $actor = new Actor('greeter', new GreeterBehavior(), capacity: 50);
 *   $actor->start();
 *   $actor->send(Message::of('greet', 'World'));
 *
 *   // With ActorSystem (recommended):
 *   $system = ActorSystem::create('my-system');
 *   $actor = $system->spawn('greeter', new GreeterBehavior());
 *   $actor->send(Message::of('greet', 'World'));
 *   $system->awaitAll();
 *
 * Integration with Supervisor:
 *   When an actor is registered with a Supervisor, the supervisor monitors
 *   its lifecycle. On failure, the supervisor's restart strategy determines
 *   whether to restart just this actor (ONE_FOR_ONE), all children
 *   (ONE_FOR_ALL), or a subset (REST_FOR_ONE).
 *
 * @see ActorBehavior   The interface defining message handling logic.
 * @see ActorContext     The runtime context passed to behavior methods.
 * @see ActorSystem      The registry and lifecycle manager for actors.
 * @see Message          The envelope for actor-to-actor communication.
 */
final class Actor
{
    // ─── State ───────────────────────────────────────────────────────

    /**
     * The actor's mailbox — a buffered Channel that holds incoming messages.
     *
     * Messages are enqueued by send() and dequeued by the processing loop.
     * The capacity determines how many messages can be buffered before
     * send() starts blocking (backpressure).
     */
    public readonly Channel $mailbox;

    /**
     * The actor's unique name within its ActorSystem.
     */
    public readonly string $name;

    /**
     * The behavior that handles incoming messages.
     *
     * This can be swapped at restart time (by creating a new Actor with
     * a fresh behavior instance), allowing stateful recovery patterns.
     */
    private ActorBehavior $behavior;

    /**
     * The ActorSystem this actor belongs to (null if standalone).
     */
    private ?ActorSystem $system = null;

    /**
     * The ActorContext passed to the behavior on each message.
     * Lazy-initialized on first start().
     */
    private ?ActorContext $context = null;

    /**
     * Whether the actor has been started (processing loop launched).
     */
    private bool $started = false;

    /**
     * Whether a stop has been requested.
     *
     * When true, the processing loop will exit after the current message
     * is finished. This flag is checked between messages in the loop.
     */
    private bool $stopRequested = false;

    /**
     * Whether the actor has fully stopped (postStop called, mailbox closed).
     */
    private bool $stopped = false;

    /**
     * The Launch job reference for the processing loop Fiber.
     * Null before start() and after stop.
     */
    private ?Launch $loopJob = null;

    /**
     * The last error that caused the actor to stop (if any).
     * Used by supervisors to inspect failure reasons.
     */
    private ?Throwable $lastError = null;

    /**
     * Total number of messages successfully processed by this actor.
     * Useful for monitoring and debugging.
     */
    private int $processedCount = 0;

    /**
     * Total number of messages that caused errors.
     */
    private int $errorCount = 0;

    /**
     * Callback invoked when the actor stops (for supervisor integration).
     * Signature: fn(Actor $actor, ?Throwable $error): void
     *
     * @var (callable(Actor, ?Throwable): void)|null
     */
    private $onStopCallback = null;

    /**
     * Mailbox capacity — stored so that restart() can recreate the mailbox.
     */
    private int $capacity;

    // ═════════════════════════════════════════════════════════════════
    //  Constructor
    // ═════════════════════════════════════════════════════════════════

    /**
     * Create a new Actor.
     *
     * The actor is NOT started automatically — call start() to begin
     * processing messages, or let an ActorSystem/Supervisor manage it.
     *
     * @param string        $name     Unique name for this actor.
     * @param ActorBehavior $behavior The message handler.
     * @param int           $capacity Mailbox capacity (default: 50).
     *                                0 = unbuffered (rendezvous), N = buffered.
     */
    public function __construct(
        string $name,
        ActorBehavior $behavior,
        int $capacity = 50,
    ) {
        $this->name = $name;
        $this->behavior = $behavior;
        $this->capacity = $capacity;
        $this->mailbox = Channel::new(capacity: $capacity);
    }

    // ═════════════════════════════════════════════════════════════════
    //  Lifecycle
    // ═════════════════════════════════════════════════════════════════

    /**
     * Start the actor's message processing loop.
     *
     * Launches a Fiber (via Launch::new) that:
     *   1. Calls behavior->preStart()
     *   2. Loops: dequeue message → dispatch to behavior → yield
     *   3. On stop: calls behavior->postStop(), closes mailbox
     *
     * @param Dispatchers|null $dispatcher The dispatcher to use for the loop Fiber.
     *                                     null = Dispatchers::DEFAULT (Fiber-only).
     *                                     Dispatchers::IO = run in WorkerPool.
     * @return self Fluent return.
     * @throws RuntimeException If the actor is already started.
     */
    public function start(?Dispatchers $dispatcher = null): self
    {
        if ($this->started && !$this->stopped) {
            throw new RuntimeException(
                "Actor '{$this->name}' is already started.",
            );
        }

        // Reset state for (re)start
        $this->stopRequested = false;
        $this->stopped = false;
        $this->lastError = null;

        // Ensure context is initialized
        if ($this->context === null) {
            $this->ensureContext();
        }

        $this->started = true;

        $effectiveDispatcher = $dispatcher ?? Dispatchers::DEFAULT;

        // Launch the processing loop as a Fiber
        $this->loopJob = Launch::new(
            fn() => $this->processingLoop(),
            $effectiveDispatcher,
        );

        return $this;
    }

    /**
     * Request the actor to stop gracefully.
     *
     * The actor will finish processing the current message (if any),
     * then exit the processing loop. The behavior's postStop() hook
     * will be called, and the mailbox will be closed.
     *
     * This is non-blocking — the actor may still be running when this
     * method returns. Use awaitStop() to block until fully stopped.
     */
    public function requestStop(): void
    {
        $this->stopRequested = true;
    }

    /**
     * Check if a stop has been requested.
     *
     * @return bool True if requestStop() has been called.
     */
    public function isStopping(): bool
    {
        return $this->stopRequested;
    }

    /**
     * Check if the actor has fully stopped.
     *
     * @return bool True if the processing loop has exited and postStop() has been called.
     */
    public function isStopped(): bool
    {
        return $this->stopped;
    }

    /**
     * Check if the actor has been started (may or may not be currently running).
     *
     * @return bool True if start() has been called at least once.
     */
    public function isStarted(): bool
    {
        return $this->started;
    }

    /**
     * Check if the actor is currently alive (started and not stopped).
     *
     * @return bool True if the actor is processing messages.
     */
    public function isAlive(): bool
    {
        return $this->started && !$this->stopped;
    }

    /**
     * Get the last error that caused the actor to stop (if any).
     *
     * @return Throwable|null The last fatal error, or null if the actor
     *                        stopped gracefully or is still running.
     */
    public function getLastError(): ?Throwable
    {
        return $this->lastError;
    }

    /**
     * Get the Launch job reference for the processing loop.
     *
     * @return Launch|null The job, or null if not started.
     */
    public function getLoopJob(): ?Launch
    {
        return $this->loopJob;
    }

    // ═════════════════════════════════════════════════════════════════
    //  Messaging
    // ═════════════════════════════════════════════════════════════════

    /**
     * Send a message to this actor's mailbox.
     *
     * The message is placed into the mailbox Channel. If the mailbox is
     * full (at capacity), this call will block (cooperatively yield) until
     * space is available — providing natural backpressure.
     *
     * This method can be called from any context: inside another actor's
     * handle(), from a Launch fiber, from RunBlocking, or from top-level code.
     *
     * @param Message $message The message to enqueue.
     * @throws RuntimeException If the mailbox is closed (actor stopped).
     */
    public function send(Message $message): void
    {
        if ($this->mailbox->isClosed()) {
            throw new RuntimeException(
                "Cannot send message to stopped actor '{$this->name}'. " .
                "Message type: '{$message->type}'.",
            );
        }

        $this->mailbox->send($message);
    }

    /**
     * Try to send a message without blocking.
     *
     * @param Message $message The message to enqueue.
     * @return bool True if the message was enqueued, false if the mailbox
     *              is full or closed.
     */
    public function trySend(Message $message): bool
    {
        if ($this->mailbox->isClosed()) {
            return false;
        }

        return $this->mailbox->trySend($message);
    }

    // ═════════════════════════════════════════════════════════════════
    //  System integration
    // ═════════════════════════════════════════════════════════════════

    /**
     * Associate this actor with an ActorSystem.
     *
     * Called by ActorSystem::spawn(). Sets up the ActorContext with
     * a reference to the system so that the behavior can look up
     * other actors, send messages, and spawn children.
     *
     * @param ActorSystem $system The owning system.
     * @internal Called by ActorSystem — not part of the public API.
     */
    public function setSystem(ActorSystem $system): void
    {
        $this->system = $system;
        $this->context = new ActorContext($this->name, $this, $system);
    }

    /**
     * Register a callback to be invoked when the actor stops.
     *
     * Used by Supervisor to detect actor failures and trigger
     * restart strategies.
     *
     * @param callable(Actor, ?Throwable): void $callback
     * @internal Called by Supervisor — not part of the public API.
     */
    public function onStop(callable $callback): void
    {
        $this->onStopCallback = $callback;
    }

    /**
     * Get the behavior instance (for supervisor restart — create a fresh behavior).
     *
     * @return ActorBehavior
     */
    public function getBehavior(): ActorBehavior
    {
        return $this->behavior;
    }

    /**
     * Get the mailbox capacity (for supervisor restart — recreate actor).
     *
     * @return int
     */
    public function getCapacity(): int
    {
        return $this->capacity;
    }

    // ═════════════════════════════════════════════════════════════════
    //  Statistics
    // ═════════════════════════════════════════════════════════════════

    /**
     * Get the total number of messages successfully processed.
     *
     * @return int
     */
    public function getProcessedCount(): int
    {
        return $this->processedCount;
    }

    /**
     * Get the total number of messages that caused errors.
     *
     * @return int
     */
    public function getErrorCount(): int
    {
        return $this->errorCount;
    }

    /**
     * Get the current number of messages waiting in the mailbox.
     *
     * @return int
     */
    public function getMailboxSize(): int
    {
        return $this->mailbox->size();
    }

    /**
     * Get a snapshot of the actor's state for debugging/monitoring.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return [
            'name' => $this->name,
            'started' => $this->started,
            'stopped' => $this->stopped,
            'stopping' => $this->stopRequested,
            'alive' => $this->isAlive(),
            'mailboxSize' => $this->mailbox->isClosed() ? 0 : $this->mailbox->size(),
            'mailboxCapacity' => $this->capacity,
            'processedCount' => $this->processedCount,
            'errorCount' => $this->errorCount,
            'hasError' => $this->lastError !== null,
            'lastError' => $this->lastError?->getMessage(),
        ];
    }

    // ═════════════════════════════════════════════════════════════════
    //  Internal: processing loop
    // ═════════════════════════════════════════════════════════════════

    /**
     * The main processing loop executed inside a Fiber.
     *
     * Flow:
     *   1. Call behavior->preStart()
     *   2. Loop until stopRequested or mailbox closed:
     *      a. Try to receive a message from the mailbox (non-blocking)
     *      b. If message available: dispatch to behavior
     *      c. If no message: cooperative yield (Pause::force)
     *   3. Call behavior->postStop()
     *   4. Close the mailbox
     *   5. Notify onStopCallback (for supervisor integration)
     */
    private function processingLoop(): void
    {
        $context = $this->context;
        $behavior = $this->behavior;
        $fatalError = null;

        // ─── preStart ────────────────────────────────────────────
        try {
            $behavior->preStart($context);
        } catch (Throwable $e) {
            $this->lastError = $e;
            $fatalError = $e;
            error_log(
                "Actor '{$this->name}' preStart() failed: " . $e->getMessage(),
            );
            // preStart failure is fatal — go directly to postStop
            $this->finalizeStop($fatalError);
            return;
        }

        // ─── Message loop ────────────────────────────────────────
        while (!$this->stopRequested) {
            // Check if mailbox was closed externally
            if ($this->mailbox->isClosed()) {
                break;
            }

            // Non-blocking receive — tryReceive returns null if empty
            $message = $this->mailbox->tryReceive();

            if ($message === null) {
                // No message available — cooperatively yield so other
                // fibers can run, then try again on next tick.
                Pause::force();
                continue;
            }

            // Validate that we received a Message instance
            if (!$message instanceof Message) {
                // Non-Message values in the mailbox are ignored.
                // This should not happen in normal usage but guards
                // against misuse (e.g. sending raw values to the channel).
                $this->errorCount++;
                continue;
            }

            // ─── Handle special system messages ──────────────────
            if ($message->type === '__actor_stop__') {
                // Internal stop signal — break the loop
                break;
            }

            // ─── Dispatch to behavior ────────────────────────────
            try {
                // If the behavior is an AbstractActorBehavior, use
                // dispatch() which routes through become() overrides.
                if ($behavior instanceof AbstractActorBehavior) {
                    $behavior->dispatch($message, $context);
                } else {
                    $behavior->handle($message, $context);
                }

                $this->processedCount++;
            } catch (Throwable $e) {
                $this->errorCount++;
                $this->lastError = $e;

                // Ask the behavior whether to continue or stop
                try {
                    $shouldContinue = $behavior->onError($message, $e, $context);
                } catch (Throwable $onErrorException) {
                    // onError itself failed — fatal, stop the actor
                    error_log(
                        "Actor '{$this->name}' onError() threw: " .
                        $onErrorException->getMessage(),
                    );
                    $fatalError = $e;
                    break;
                }

                if (!$shouldContinue) {
                    // Behavior says stop — let-it-crash
                    $fatalError = $e;
                    break;
                }

                // Behavior says continue — loop back for next message
            }

            // Cooperatively yield between messages so other actors
            // (fibers) get CPU time. This is critical for fairness
            // in a cooperative scheduling system.
            Pause::force();
        }

        // ─── Finalize stop ───────────────────────────────────────
        $this->finalizeStop($fatalError);
    }

    /**
     * Finalize the stop sequence: call postStop, close mailbox, notify supervisor.
     *
     * @param Throwable|null $fatalError The error that caused the stop, or null for graceful stop.
     */
    private function finalizeStop(?Throwable $fatalError): void
    {
        // Call postStop hook
        try {
            $this->behavior->postStop($this->context);
        } catch (Throwable $e) {
            error_log(
                "Actor '{$this->name}' postStop() failed: " . $e->getMessage(),
            );
        }

        // Close the mailbox — any messages still in the buffer are lost.
        // In a production system you might want to drain to a dead-letter
        // queue, but that's out of scope for the core actor implementation.
        if (!$this->mailbox->isClosed()) {
            $this->mailbox->close();
        }

        $this->stopped = true;
        $this->loopJob = null;

        // Notify the supervisor (if any) that this actor has stopped
        if ($this->onStopCallback !== null) {
            try {
                ($this->onStopCallback)($this, $fatalError);
            } catch (Throwable $e) {
                error_log(
                    "Actor '{$this->name}' onStopCallback failed: " . $e->getMessage(),
                );
            }
        }
    }

    /**
     * Ensure the ActorContext is initialized.
     *
     * If no ActorSystem has been set (standalone actor), creates a
     * minimal context with a dummy system.
     */
    private function ensureContext(): void
    {
        if ($this->context !== null) {
            return;
        }

        // Standalone actor without a system — create a minimal system
        // so that the context is usable (self-referencing only).
        if ($this->system === null) {
            $this->system = ActorSystem::create('__standalone_' . $this->name);
            $this->system->register($this);
        }

        $this->context = new ActorContext($this->name, $this, $this->system);
    }
}
