<?php

declare(strict_types=1);

namespace vosaka\foroutines\actor;

/**
 * Message — The envelope for actor-to-actor communication.
 *
 * A Message wraps a payload with metadata (type tag, sender reference,
 * correlation ID) so that actors can pattern-match on incoming messages
 * and send replies back to the originator.
 *
 * Design principles:
 *   - Immutable after construction (readonly properties).
 *   - Lightweight — no inheritance hierarchy, just a data envelope.
 *   - The `type` field is a free-form string tag that actors use for
 *     pattern matching (e.g. "ping", "work:request", "shutdown").
 *   - The `payload` is any serializable PHP value (scalar, array, object).
 *   - The `sender` is an optional actor name so the receiver can reply.
 *   - The `correlationId` links request/response pairs for ask() patterns.
 *   - The `timestamp` records creation time for debugging and ordering.
 *
 * Usage:
 *   $msg = Message::of('ping', ['data' => 42]);
 *   $msg = Message::of('greet', 'hello', sender: 'actorA');
 *   $msg = new Message(type: 'work', payload: $task, sender: 'pool');
 */
final class Message
{
    /**
     * @param string      $type          Free-form message type tag for pattern matching.
     * @param mixed       $payload       The message body — any PHP value.
     * @param string|null $sender        Name of the sending actor (for replies).
     * @param string|null $correlationId Correlation ID linking request ↔ response.
     * @param float       $timestamp     Creation timestamp (microtime(true)).
     */
    public function __construct(
        public readonly string $type,
        public readonly mixed $payload = null,
        public readonly ?string $sender = null,
        public readonly ?string $correlationId = null,
        public readonly float $timestamp = 0.0,
    ) {
        // If timestamp was not provided (left at default 0.0), we
        // override it in the factory method. The constructor keeps
        // 0.0 as default so that callers who provide an explicit
        // timestamp (e.g. deserialization) don't get it overwritten.
    }

    // ═════════════════════════════════════════════════════════════════
    //  Factory methods
    // ═════════════════════════════════════════════════════════════════

    /**
     * Create a new Message with automatic timestamping.
     *
     * This is the preferred way to create messages. The timestamp is
     * set to the current microtime automatically.
     *
     * @param string      $type    Message type tag.
     * @param mixed       $payload Message body.
     * @param string|null $sender  Sender actor name.
     * @return self
     */
    public static function of(
        string $type,
        mixed $payload = null,
        ?string $sender = null,
    ): self {
        return new self(
            type: $type,
            payload: $payload,
            sender: $sender,
            correlationId: null,
            timestamp: microtime(true),
        );
    }

    /**
     * Create a request message with a correlation ID for ask() patterns.
     *
     * The correlation ID is auto-generated (uniqid-based) so that the
     * receiver can include it in the reply, allowing the sender to match
     * responses to their original requests.
     *
     * @param string      $type    Message type tag.
     * @param mixed       $payload Message body.
     * @param string|null $sender  Sender actor name.
     * @return self
     */
    public static function request(
        string $type,
        mixed $payload = null,
        ?string $sender = null,
    ): self {
        return new self(
            type: $type,
            payload: $payload,
            sender: $sender,
            correlationId: uniqid('msg_', true),
            timestamp: microtime(true),
        );
    }

    /**
     * Create a reply message that carries the correlation ID from the
     * original request, so the sender can match it.
     *
     * @param Message     $original The original request message.
     * @param string      $type     Reply message type tag.
     * @param mixed       $payload  Reply body.
     * @param string|null $sender   Sender actor name (the replier).
     * @return self
     */
    public static function reply(
        Message $original,
        string $type,
        mixed $payload = null,
        ?string $sender = null,
    ): self {
        return new self(
            type: $type,
            payload: $payload,
            sender: $sender,
            correlationId: $original->correlationId,
            timestamp: microtime(true),
        );
    }

    // ═════════════════════════════════════════════════════════════════
    //  Convenience / introspection
    // ═════════════════════════════════════════════════════════════════

    /**
     * Check if this message matches a given type.
     *
     * @param string $type The type to check against.
     * @return bool
     */
    public function isType(string $type): bool
    {
        return $this->type === $type;
    }

    /**
     * Check if this message has a correlation ID (is part of a
     * request/response exchange).
     *
     * @return bool
     */
    public function isCorrelated(): bool
    {
        return $this->correlationId !== null;
    }

    /**
     * Check if this message has a sender reference.
     *
     * @return bool
     */
    public function hasSender(): bool
    {
        return $this->sender !== null;
    }

    /**
     * Return a human-readable string representation for debugging.
     *
     * @return string
     */
    public function __toString(): string
    {
        $parts = ["Message(type={$this->type}"];

        if ($this->sender !== null) {
            $parts[] = "sender={$this->sender}";
        }

        if ($this->correlationId !== null) {
            $parts[] = "correlationId={$this->correlationId}";
        }

        $payloadType = get_debug_type($this->payload);
        $parts[] = "payload={$payloadType}";

        return implode(', ', $parts) . ')';
    }
}
