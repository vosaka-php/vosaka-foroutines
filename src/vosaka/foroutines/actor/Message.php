<?php

declare(strict_types=1);

namespace vosaka\foroutines\actor;

/**
 * A typed message for Actor mailboxes.
 *
 * Messages are the fundamental unit of communication between Actors.
 * Each message has a type string for pattern matching, an optional
 * payload of any type, an optional sender identifier, and a timestamp.
 *
 * Usage:
 *     $msg = new Message('greet', ['name' => 'World']);
 *     $msg = new Message('shutdown');
 *     $msg = Message::of('ping', sender: 'actor-a');
 */
final class Message
{
    /**
     * Message timestamp (microtime float).
     * Auto-set to current time if not provided.
     */
    public readonly float $timestamp;

    /**
     * @param string      $type    Message type identifier for routing/pattern matching.
     * @param mixed       $payload Arbitrary data carried by the message.
     * @param string|null $sender  Optional sender actor name.
     * @param float       $timestamp Message creation time (auto-set if 0.0).
     */
    public function __construct(
        public readonly string $type,
        public readonly mixed $payload = null,
        public readonly ?string $sender = null,
        float $timestamp = 0.0,
    ) {
        $this->timestamp = $timestamp > 0.0 ? $timestamp : microtime(true);
    }

    /**
     * Named constructor for fluent API.
     *
     * @param string      $type    Message type.
     * @param mixed       $payload Message data.
     * @param string|null $sender  Sender name.
     * @return self
     */
    public static function of(
        string $type,
        mixed $payload = null,
        ?string $sender = null,
    ): self {
        return new self($type, $payload, $sender);
    }

    /**
     * Create a poison-pill message that tells actors to shut down.
     */
    public static function poison(?string $sender = null): self
    {
        return new self('__POISON__', null, $sender);
    }

    /**
     * Check if this message is a poison pill.
     */
    public function isPoison(): bool
    {
        return $this->type === '__POISON__';
    }
}
