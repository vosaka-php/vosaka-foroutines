<?php

declare(strict_types=1);

namespace vosaka\foroutines\actor;

use RuntimeException;

/**
 * ActorContext — The runtime context available to an actor during message processing.
 *
 * Every time an actor's behavior handle() method is called, it receives an
 * ActorContext that provides:
 *
 *   - **Self-identity**: the actor's own name and reference.
 *   - **Messaging**: send messages to other actors by name, reply to senders.
 *   - **Lifecycle**: stop the current actor, request restart.
 *   - **Child management**: spawn child actors that are supervised by this actor.
 *   - **System access**: look up actors in the registry, access the ActorSystem.
 *
 * Design principles:
 *   - The context is a lightweight proxy — it holds a reference to the
 *     ActorSystem and the owning Actor, delegating most operations.
 *   - Immutable identity (name) — the actor's name cannot change after creation.
 *   - All messaging is asynchronous (fire-and-forget). For request/response
 *     patterns, use ask() which creates a correlated Message and waits for
 *     the reply on a temporary channel.
 *
 * Usage inside a behavior:
 *   public function handle(Message $message, ActorContext $context): void
 *   {
 *       // Send a message to another actor
 *       $context->send('other-actor', Message::of('hello', 'world'));
 *
 *       // Reply to the sender of the current message
 *       $context->reply($message, 'pong', ['data' => 42]);
 *
 *       // Spawn a child actor
 *       $context->spawn('child', new ChildBehavior(), capacity: 20);
 *
 *       // Stop self
 *       $context->stop();
 *   }
 */
final class ActorContext
{
    /**
     * @param string      $name   The owning actor's unique name.
     * @param Actor       $self   Reference to the owning Actor instance.
     * @param ActorSystem $system The ActorSystem this actor belongs to.
     */
    public function __construct(
        private readonly string $name,
        private readonly Actor $self,
        private readonly ActorSystem $system,
    ) {}

    // ═════════════════════════════════════════════════════════════════
    //  Self-identity
    // ═════════════════════════════════════════════════════════════════

    /**
     * Get the name of this actor.
     *
     * @return string The actor's unique name within the system.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the Actor reference for self.
     *
     * Useful for passing "self" to other actors so they can send
     * messages back, or for registering self with external services.
     *
     * @return Actor
     */
    public function getSelf(): Actor
    {
        return $this->self;
    }

    /**
     * Get the ActorSystem this actor belongs to.
     *
     * @return ActorSystem
     */
    public function getSystem(): ActorSystem
    {
        return $this->system;
    }

    // ═════════════════════════════════════════════════════════════════
    //  Messaging
    // ═════════════════════════════════════════════════════════════════

    /**
     * Send a message to another actor by name.
     *
     * The message is placed into the target actor's mailbox (Channel).
     * This is fire-and-forget — the caller does not wait for the message
     * to be processed.
     *
     * The message's sender field is automatically set to this actor's name
     * if not already set.
     *
     * @param string  $actorName The target actor's name.
     * @param Message $message   The message to send.
     * @throws RuntimeException If the target actor is not found in the system.
     */
    public function send(string $actorName, Message $message): void
    {
        // Auto-fill sender if not set
        if ($message->sender === null) {
            $message = new Message(
                type: $message->type,
                payload: $message->payload,
                sender: $this->name,
                correlationId: $message->correlationId,
                timestamp: $message->timestamp > 0.0 ? $message->timestamp : microtime(true),
            );
        }

        $actor = $this->system->getActor($actorName);
        if ($actor === null) {
            throw new RuntimeException(
                "ActorContext: Cannot send message to unknown actor '{$actorName}'. " .
                "Sender: '{$this->name}', message type: '{$message->type}'.",
            );
        }

        $actor->send($message);
    }

    /**
     * Send a message directly to self's mailbox.
     *
     * Useful for scheduling self-messages (e.g. retry logic, periodic
     * ticks, state machine transitions).
     *
     * @param Message $message The message to send to self.
     */
    public function sendToSelf(Message $message): void
    {
        if ($message->sender === null) {
            $message = new Message(
                type: $message->type,
                payload: $message->payload,
                sender: $this->name,
                correlationId: $message->correlationId,
                timestamp: $message->timestamp > 0.0 ? $message->timestamp : microtime(true),
            );
        }

        $this->self->send($message);
    }

    /**
     * Send a reply to the sender of the original message.
     *
     * Convenience method that creates a reply Message (preserving the
     * correlation ID) and sends it to the original message's sender.
     *
     * If the original message has no sender, this is a no-op (the reply
     * is silently discarded, since there's no one to send it to).
     *
     * @param Message     $original The original message being replied to.
     * @param string      $type     Reply message type tag.
     * @param mixed       $payload  Reply body.
     */
    public function reply(Message $original, string $type, mixed $payload = null): void
    {
        if ($original->sender === null) {
            // No sender — nowhere to reply. Silently discard.
            return;
        }

        $replyMsg = Message::reply($original, $type, $payload, sender: $this->name);

        $actor = $this->system->getActor($original->sender);
        if ($actor === null) {
            // Sender actor no longer exists — discard the reply.
            // This can happen if the sender was stopped between sending
            // the request and the reply being generated.
            return;
        }

        $actor->send($replyMsg);
    }

    /**
     * Try to send a message to another actor, returning false if the
     * target actor doesn't exist (instead of throwing).
     *
     * @param string  $actorName The target actor's name.
     * @param Message $message   The message to send.
     * @return bool True if the message was sent, false if the target doesn't exist.
     */
    public function trySend(string $actorName, Message $message): bool
    {
        if ($message->sender === null) {
            $message = new Message(
                type: $message->type,
                payload: $message->payload,
                sender: $this->name,
                correlationId: $message->correlationId,
                timestamp: $message->timestamp > 0.0 ? $message->timestamp : microtime(true),
            );
        }

        $actor = $this->system->getActor($actorName);
        if ($actor === null) {
            return false;
        }

        $actor->send($message);
        return true;
    }

    /**
     * Broadcast a message to all actors in the system (except self).
     *
     * @param Message $message The message to broadcast.
     * @param bool    $includeSelf Whether to also send to self (default: false).
     */
    public function broadcast(Message $message, bool $includeSelf = false): void
    {
        if ($message->sender === null) {
            $message = new Message(
                type: $message->type,
                payload: $message->payload,
                sender: $this->name,
                correlationId: $message->correlationId,
                timestamp: $message->timestamp > 0.0 ? $message->timestamp : microtime(true),
            );
        }

        foreach ($this->system->getAllActorNames() as $actorName) {
            if (!$includeSelf && $actorName === $this->name) {
                continue;
            }

            $actor = $this->system->getActor($actorName);
            if ($actor !== null) {
                $actor->send($message);
            }
        }
    }

    // ═════════════════════════════════════════════════════════════════
    //  Lifecycle management
    // ═════════════════════════════════════════════════════════════════

    /**
     * Request this actor to stop.
     *
     * The actor will finish processing the current message, then stop
     * its mailbox loop. The behavior's postStop() hook will be called.
     *
     * If the actor is supervised, the supervisor will be notified of
     * the stop and may choose to restart it based on its strategy.
     */
    public function stop(): void
    {
        $this->self->requestStop();
    }

    /**
     * Check if this actor has been requested to stop.
     *
     * @return bool True if stop() has been called.
     */
    public function isStopping(): bool
    {
        return $this->self->isStopping();
    }

    /**
     * Check if this actor's mailbox is closed.
     *
     * @return bool True if the actor's mailbox channel is closed.
     */
    public function isMailboxClosed(): bool
    {
        return $this->self->mailbox->isClosed();
    }

    // ═════════════════════════════════════════════════════════════════
    //  Child actor management
    // ═════════════════════════════════════════════════════════════════

    /**
     * Spawn a child actor registered in the same ActorSystem.
     *
     * The child actor is created and registered with the system. It begins
     * processing messages from its mailbox immediately (via a Launch fiber).
     *
     * Note: child supervision is handled by the Supervisor class, not
     * directly by the ActorContext. If you need supervised children, use
     * a Supervisor with the appropriate restart strategy.
     *
     * @param string        $name     Unique name for the child actor.
     * @param ActorBehavior $behavior The child's message handler.
     * @param int           $capacity Mailbox capacity (default: 50).
     * @return Actor The spawned child actor.
     * @throws RuntimeException If an actor with the given name already exists.
     */
    public function spawn(string $name, ActorBehavior $behavior, int $capacity = 50): Actor
    {
        return $this->system->spawn($name, $behavior, $capacity);
    }

    /**
     * Stop a child actor by name.
     *
     * @param string $name The child actor's name.
     * @return bool True if the actor was found and stopped, false if not found.
     */
    public function stopChild(string $name): bool
    {
        $actor = $this->system->getActor($name);
        if ($actor === null) {
            return false;
        }

        $actor->requestStop();
        return true;
    }

    // ═════════════════════════════════════════════════════════════════
    //  Actor lookup
    // ═════════════════════════════════════════════════════════════════

    /**
     * Look up an actor by name in the system.
     *
     * @param string $name The actor's name.
     * @return Actor|null The actor if found, null otherwise.
     */
    public function actorOf(string $name): ?Actor
    {
        return $this->system->getActor($name);
    }

    /**
     * Check if an actor with the given name exists in the system.
     *
     * @param string $name The actor's name.
     * @return bool True if the actor exists.
     */
    public function hasActor(string $name): bool
    {
        return $this->system->hasActor($name);
    }

    // ═════════════════════════════════════════════════════════════════
    //  Convenience: message factories with auto-sender
    // ═════════════════════════════════════════════════════════════════

    /**
     * Create a Message with this actor as the sender.
     *
     * @param string $type    Message type.
     * @param mixed  $payload Message body.
     * @return Message
     */
    public function createMessage(string $type, mixed $payload = null): Message
    {
        return Message::of($type, $payload, sender: $this->name);
    }

    /**
     * Create a request Message (with correlation ID) with this actor as sender.
     *
     * @param string $type    Message type.
     * @param mixed  $payload Message body.
     * @return Message
     */
    public function createRequest(string $type, mixed $payload = null): Message
    {
        return Message::request($type, $payload, sender: $this->name);
    }
}
