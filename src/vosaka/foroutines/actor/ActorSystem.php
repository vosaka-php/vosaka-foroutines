<?php

declare(strict_types=1);

namespace vosaka\foroutines\actor;

use vosaka\foroutines\Dispatchers;

/**
 * ActorSystem — a registry for managing named Actors.
 *
 * Provides a centralized place to register, look up, and manage
 * Actor instances. Supports bulk start/stop and message routing
 * by actor name.
 *
 * Usage:
 *     $system = ActorSystem::new()
 *         ->register(new GreeterActor('greeter'))
 *         ->register(new LoggerActor('logger'));
 *
 *     $system->startAll();
 *
 *     $system->send('greeter', Message::of('greet', 'World'));
 *     $system->send('logger', Message::of('log', 'Something happened'));
 *
 *     $system->stopAll();
 */
final class ActorSystem
{
    /**
     * Registered actors keyed by name.
     * @var array<string, Actor>
     */
    private array $actors = [];

    private function __construct()
    {
    }

    /**
     * Create a new ActorSystem.
     */
    public static function new(): self
    {
        return new self();
    }

    /**
     * Register an actor with the system.
     *
     * @param Actor $actor The actor to register.
     * @return self For fluent chaining.
     * @throws \RuntimeException If an actor with the same name is already registered.
     */
    public function register(Actor $actor): self
    {
        if (isset($this->actors[$actor->name])) {
            throw new \RuntimeException(
                "Actor '{$actor->name}' is already registered.",
            );
        }

        $this->actors[$actor->name] = $actor;
        return $this;
    }

    /**
     * Unregister an actor by name.
     *
     * @param string $name The actor name to remove.
     * @return self For fluent chaining.
     */
    public function unregister(string $name): self
    {
        unset($this->actors[$name]);
        return $this;
    }

    /**
     * Get an actor by name.
     *
     * @param string $name The actor name.
     * @return Actor|null The actor, or null if not found.
     */
    public function get(string $name): ?Actor
    {
        return $this->actors[$name] ?? null;
    }

    /**
     * Check if an actor is registered.
     *
     * @param string $name The actor name.
     * @return bool True if the actor exists in the registry.
     */
    public function has(string $name): bool
    {
        return isset($this->actors[$name]);
    }

    /**
     * Send a message to a named actor.
     *
     * @param string  $actorName The target actor name.
     * @param Message $msg       The message to send.
     * @throws \RuntimeException If the actor is not found.
     */
    public function send(string $actorName, Message $msg): void
    {
        $actor = $this->actors[$actorName] ?? null;
        if ($actor === null) {
            throw new \RuntimeException(
                "Actor '{$actorName}' not found in system.",
            );
        }
        $actor->send($msg);
    }

    /**
     * Start all registered actors.
     *
     * @param Dispatchers $dispatcher The dispatcher for all actors.
     */
    public function startAll(
        Dispatchers $dispatcher = Dispatchers::DEFAULT ,
    ): void {
        foreach ($this->actors as $actor) {
            if (!$actor->isRunning()) {
                $actor->start($dispatcher);
            }
        }
    }

    /**
     * Stop all registered actors gracefully.
     */
    public function stopAll(): void
    {
        foreach ($this->actors as $actor) {
            if ($actor->isRunning()) {
                $actor->stop();
            }
        }
    }

    /**
     * Get all registered actor names.
     *
     * @return string[]
     */
    public function getNames(): array
    {
        return array_keys($this->actors);
    }

    /**
     * Get the number of registered actors.
     */
    public function count(): int
    {
        return count($this->actors);
    }
}
