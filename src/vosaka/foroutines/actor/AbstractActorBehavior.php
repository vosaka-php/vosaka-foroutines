<?php

declare(strict_types=1);

namespace vosaka\foroutines\actor;

/**
 * AbstractActorBehavior — Base class with sensible defaults for actor behaviors.
 *
 * Provides no-op implementations of the lifecycle hooks (preStart, postStop)
 * and a fail-fast onError() that returns false (let-it-crash), consistent
 * with Erlang/OTP philosophy. Concrete behaviors only need to implement
 * the handle() method.
 *
 * This class also provides convenience helpers for common patterns:
 *   - become()     — swap the message handler at runtime (FSM pattern)
 *   - stash/unstash — temporarily defer messages for later processing
 *   - scheduleOnce  — send a delayed message to self
 *
 * Usage:
 *   class MyActor extends AbstractActorBehavior
 *   {
 *       private int $counter = 0;
 *
 *       public function handle(Message $message, ActorContext $context): void
 *       {
 *           match ($message->type) {
 *               'increment' => $this->counter++,
 *               'get'       => $context->reply($message, 'count', $this->counter),
 *               'reset'     => $this->counter = 0,
 *               default     => $this->unhandled($message, $context),
 *           };
 *       }
 *   }
 */
abstract class AbstractActorBehavior implements ActorBehavior
{
    // ─── Stash support ───────────────────────────────────────────────

    /**
     * Stashed messages — temporarily deferred for later processing.
     *
     * When an actor is in a state where it cannot handle certain messages
     * (e.g. waiting for initialization to complete), it can stash them.
     * Later, when the actor is ready, it calls unstashAll() to re-process
     * them in FIFO order.
     *
     * @var array<int, Message>
     */
    private array $stash = [];

    /**
     * Maximum stash capacity to prevent unbounded memory growth.
     * Override in subclass if needed.
     *
     * @var int
     */
    protected int $maxStashSize = 1000;

    // ─── Become/Unbecome support ─────────────────────────────────────

    /**
     * Stack of behavior handlers for become/unbecome (FSM pattern).
     *
     * When become() is called, the current handler is pushed onto this
     * stack and the new handler takes over. unbecome() pops the stack
     * and restores the previous handler. If the stack is empty, the
     * default handle() method is used.
     *
     * @var array<int, callable(Message, ActorContext): void>
     */
    private array $behaviorStack = [];

    /**
     * Current active handler override, or null to use handle().
     *
     * @var (callable(Message, ActorContext): void)|null
     */
    private $currentBehavior = null;

    // ═════════════════════════════════════════════════════════════════
    //  ActorBehavior interface — defaults
    // ═════════════════════════════════════════════════════════════════

    /**
     * {@inheritdoc}
     *
     * No-op default — override in subclass if initialization is needed.
     */
    public function preStart(ActorContext $context): void
    {
        // No-op — subclasses override as needed
    }

    /**
     * {@inheritdoc}
     *
     * No-op default — override in subclass if cleanup is needed.
     */
    public function postStop(ActorContext $context): void
    {
        // No-op — subclasses override as needed
    }

    /**
     * {@inheritdoc}
     *
     * Default: fail-fast (let-it-crash). Returns false so the actor stops
     * and the supervisor (if any) can decide the restart strategy.
     *
     * Override to return true if the actor should continue after errors
     * (e.g. for transient failures that don't corrupt internal state).
     */
    public function onError(Message $message, \Throwable $error, ActorContext $context): bool
    {
        // Let-it-crash — consistent with Erlang/OTP philosophy.
        // The supervisor will handle restart if configured.
        return false;
    }

    // ═════════════════════════════════════════════════════════════════
    //  Dispatch — routes through become() overrides
    // ═════════════════════════════════════════════════════════════════

    /**
     * Dispatch a message through the current behavior.
     *
     * If become() has been called, the overridden handler is used.
     * Otherwise, the default handle() method is called.
     *
     * This method is called by the Actor runtime instead of handle()
     * directly, so that become/unbecome works transparently.
     *
     * @param Message      $message The incoming message.
     * @param ActorContext  $context The actor's context.
     */
    public function dispatch(Message $message, ActorContext $context): void
    {
        if ($this->currentBehavior !== null) {
            ($this->currentBehavior)($message, $context);
        } else {
            $this->handle($message, $context);
        }
    }

    // ═════════════════════════════════════════════════════════════════
    //  Become / Unbecome — FSM pattern
    // ═════════════════════════════════════════════════════════════════

    /**
     * Switch the actor's message handler to a new callable.
     *
     * The previous handler is pushed onto a stack so that unbecome()
     * can restore it. This enables finite state machine (FSM) patterns
     * where the actor's behavior changes based on its internal state.
     *
     * Usage:
     *   // In handle():
     *   match ($message->type) {
     *       'authenticate' => $this->become(fn($msg, $ctx) => $this->authenticated($msg, $ctx)),
     *   };
     *
     * @param callable(Message, ActorContext): void $handler The new message handler.
     * @param bool $discardOld If true, don't push the old handler onto the stack
     *                         (replaces instead of stacking). Default: false.
     */
    protected function become(callable $handler, bool $discardOld = false): void
    {
        if (!$discardOld && $this->currentBehavior !== null) {
            $this->behaviorStack[] = $this->currentBehavior;
        }
        $this->currentBehavior = $handler;
    }

    /**
     * Revert to the previous message handler (pop the behavior stack).
     *
     * If the stack is empty, reverts to the default handle() method.
     */
    protected function unbecome(): void
    {
        if (!empty($this->behaviorStack)) {
            $this->currentBehavior = array_pop($this->behaviorStack);
        } else {
            $this->currentBehavior = null;
        }
    }

    /**
     * Check if the actor is currently using an overridden behavior.
     *
     * @return bool True if become() has been called and not fully reverted.
     */
    protected function hasBecomeOverride(): bool
    {
        return $this->currentBehavior !== null;
    }

    /**
     * Get the current behavior stack depth.
     *
     * @return int Number of stacked behaviors (0 = using default handle()).
     */
    protected function behaviorDepth(): int
    {
        return count($this->behaviorStack) + ($this->currentBehavior !== null ? 1 : 0);
    }

    // ═════════════════════════════════════════════════════════════════
    //  Stash / Unstash — deferred message processing
    // ═════════════════════════════════════════════════════════════════

    /**
     * Stash a message for later processing.
     *
     * Use this when the actor is in a state where it cannot handle
     * certain message types yet (e.g. waiting for initialization,
     * authentication, or a resource to become available).
     *
     * Stashed messages are re-processed in FIFO order when unstashAll()
     * is called, typically after the actor transitions to a new state
     * via become().
     *
     * @param Message $message The message to stash.
     * @throws \OverflowException If the stash exceeds $maxStashSize.
     */
    protected function stash(Message $message): void
    {
        if (count($this->stash) >= $this->maxStashSize) {
            throw new \OverflowException(
                "Actor stash overflow: cannot stash more than {$this->maxStashSize} messages. " .
                "Increase \$maxStashSize or call unstashAll() more frequently.",
            );
        }

        $this->stash[] = $message;
    }

    /**
     * Re-process all stashed messages through the current behavior.
     *
     * Messages are dispatched in FIFO order (oldest first). After
     * unstashing, the stash is cleared.
     *
     * Typically called after a state transition (become()) that makes
     * the actor ready to handle previously deferred messages.
     *
     * @param ActorContext $context The actor's context.
     */
    protected function unstashAll(ActorContext $context): void
    {
        $stashed = $this->stash;
        $this->stash = [];

        foreach ($stashed as $message) {
            $this->dispatch($message, $context);
        }
    }

    /**
     * Get the number of currently stashed messages.
     *
     * @return int
     */
    protected function stashSize(): int
    {
        return count($this->stash);
    }

    /**
     * Clear all stashed messages without processing them.
     *
     * Use this when stashed messages are no longer relevant
     * (e.g. after a timeout or state reset).
     */
    protected function clearStash(): void
    {
        $this->stash = [];
    }

    // ═════════════════════════════════════════════════════════════════
    //  Utility methods for subclasses
    // ═════════════════════════════════════════════════════════════════

    /**
     * Called when a message is not handled by the current behavior.
     *
     * Default implementation logs a warning. Override in subclass to
     * implement dead-letter handling, forwarding, or error reporting.
     *
     * @param Message      $message The unhandled message.
     * @param ActorContext  $context The actor's context.
     */
    protected function unhandled(Message $message, ActorContext $context): void
    {
        error_log(
            "Actor '{$context->getName()}' received unhandled message: " .
            "type={$message->type}" .
            ($message->sender !== null ? ", sender={$message->sender}" : ''),
        );
    }

    /**
     * Reset all internal state (stash, behavior stack).
     *
     * Useful in preStart() of a restarted actor to ensure clean state.
     */
    protected function resetInternalState(): void
    {
        $this->stash = [];
        $this->behaviorStack = [];
        $this->currentBehavior = null;
    }
}
