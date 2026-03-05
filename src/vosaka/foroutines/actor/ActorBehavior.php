<?php

declare(strict_types=1);

namespace vosaka\foroutines\actor;

/**
 * ActorBehavior — Defines how an actor processes incoming messages.
 *
 * Any class that wants to act as an actor's "brain" must implement this
 * interface. The Actor class delegates every received Message to the
 * behavior's handle() method, which contains the domain logic.
 *
 * Design principles:
 *   - Single method interface — minimal surface area, easy to implement.
 *   - The ActorContext provides access to the actor's own identity, the
 *     ActorSystem registry (for sending messages to other actors), and
 *     self-management operations (stop, restart).
 *   - Behaviors are stateful by design: the implementing class can hold
 *     private fields that persist across messages (like Erlang's gen_server
 *     state). The state is isolated to a single Fiber, so no locking is
 *     needed.
 *   - Returning void keeps the interface simple. If the actor needs to
 *     reply, it uses context->reply() or context->send() explicitly.
 *
 * Usage:
 *   class PingBehavior implements ActorBehavior
 *   {
 *       private int $count = 0;
 *
 *       public function handle(Message $message, ActorContext $context): void
 *       {
 *           match ($message->type) {
 *               'ping' => $this->onPing($message, $context),
 *               'get_count' => $context->reply($message, 'count', $this->count),
 *               default => null, // ignore unknown messages
 *           };
 *       }
 *
 *       private function onPing(Message $message, ActorContext $context): void
 *       {
 *           $this->count++;
 *           if ($message->hasSender()) {
 *               $context->reply($message, 'pong', $this->count);
 *           }
 *       }
 *
 *       public function preStart(ActorContext $context): void
 *       {
 *           // Called once before the actor starts processing messages.
 *       }
 *
 *       public function postStop(ActorContext $context): void
 *       {
 *           // Called once after the actor stops (cleanup resources).
 *       }
 *
 *       public function onError(Message $message, \Throwable $error, ActorContext $context): bool
 *       {
 *           // Return true to continue processing, false to stop the actor.
 *           return true;
 *       }
 *   }
 */
interface ActorBehavior
{
    /**
     * Handle an incoming message.
     *
     * This is the core method that contains the actor's domain logic.
     * It is called once for each message dequeued from the actor's mailbox.
     *
     * IMPORTANT: This method runs inside a single Fiber. It MUST NOT
     * block indefinitely. If it needs to wait for something, it should
     * use Pause::force() or Delay::new() to cooperatively yield.
     *
     * The method can:
     *   - Mutate internal state (fields on $this).
     *   - Send messages to other actors via $context->send().
     *   - Reply to the sender via $context->reply().
     *   - Spawn child actors via $context->spawn().
     *   - Stop itself via $context->stop().
     *
     * @param Message      $message The incoming message to process.
     * @param ActorContext  $context Access to self-identity, system, and management.
     */
    public function handle(Message $message, ActorContext $context): void;

    /**
     * Called once before the actor begins processing messages from its mailbox.
     *
     * Use this for initialization logic: opening connections, loading state
     * from storage, registering with other actors, etc.
     *
     * The default implementation is a no-op, so implementors only need to
     * override this if they have setup work to do.
     *
     * @param ActorContext $context Access to self-identity and the actor system.
     */
    public function preStart(ActorContext $context): void;

    /**
     * Called once after the actor has stopped processing messages.
     *
     * Use this for cleanup logic: closing connections, flushing buffers,
     * persisting state, notifying watchers, etc.
     *
     * This is called regardless of whether the actor stopped gracefully
     * (via context->stop()) or was terminated by a supervisor after an error.
     *
     * The default implementation is a no-op.
     *
     * @param ActorContext $context Access to self-identity and the actor system.
     */
    public function postStop(ActorContext $context): void;

    /**
     * Called when handle() throws an exception.
     *
     * This gives the behavior a chance to decide what happens next:
     *   - Return true:  The actor continues processing the next message
     *                   (the failed message is discarded). This is useful
     *                   for transient errors that don't corrupt state.
     *   - Return false: The actor stops. If it's supervised, the supervisor
     *                   will be notified and may restart it according to
     *                   its restart strategy.
     *
     * The default implementation should return false (fail-fast / let-it-crash),
     * which is consistent with Erlang/OTP philosophy.
     *
     * @param Message    $message The message that caused the error.
     * @param \Throwable $error   The exception that was thrown.
     * @param ActorContext $context Access to self-identity and the actor system.
     * @return bool True to continue, false to stop the actor.
     */
    public function onError(Message $message, \Throwable $error, ActorContext $context): bool;
}
