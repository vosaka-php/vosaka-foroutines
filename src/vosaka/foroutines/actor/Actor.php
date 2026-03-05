<?php

declare(strict_types=1);

namespace vosaka\foroutines\actor;

use vosaka\foroutines\channel\Channel;
use vosaka\foroutines\Dispatchers;
use vosaka\foroutines\Launch;
use vosaka\foroutines\Pause;

/**
 * Actor — a lightweight concurrent entity that processes messages sequentially.
 *
 * Inspired by the Erlang/Akka Actor Model, each Actor has:
 *   - A unique name
 *   - A Channel-based mailbox for receiving messages
 *   - A processing loop that runs as a Fiber via Launch
 *
 * Messages are delivered in order (FIFO) and processed one at a time.
 * This eliminates the need for locks or synchronization — the Actor
 * is the unit of concurrency, and message passing is the only way to
 * communicate.
 *
 * === Usage ===
 *
 *     class GreeterActor extends Actor {
 *         protected function receive(Message $msg): void {
 *             echo "Hello, {$msg->payload}!\n";
 *         }
 *     }
 *
 *     $actor = new GreeterActor('greeter');
 *     $actor->start();
 *     $actor->send(Message::of('greet', 'World'));
 *     $actor->stop();
 *
 * === Lifecycle ===
 *
 *     1. Created → mailbox Channel is allocated
 *     2. start() → processing loop launched as a Fiber
 *     3. Receives messages → receive() called for each
 *     4. stop() → poison pill sent → loop exits gracefully
 *
 * Subclasses MUST implement the receive() method to define behavior.
 */
abstract class Actor
{
    /**
     * The actor's mailbox — an in-process Channel.
     * Messages are sent here via send() and consumed by the processing loop.
     */
    public readonly Channel $mailbox;

    /**
     * The Launch job running the actor's processing loop.
     * null when the actor is not running.
     */
    private ?Launch $job = null;

    /**
     * Whether the actor is currently running.
     */
    private bool $running = false;

    /**
     * @param string $name     Unique actor name (used for identification).
     * @param int    $capacity Mailbox buffer capacity (0 = unbounded).
     */
    public function __construct(
        public readonly string $name,
        int $capacity = 50,
    ) {
        $this->mailbox = Channel::new(capacity: $capacity);
    }

    /**
     * Override in subclass to handle incoming messages.
     *
     * This method is called once for each message, in order.
     * It runs inside a Fiber, so it can use Pause, Delay, etc.
     *
     * @param Message $msg The incoming message.
     */
    abstract protected function receive(Message $msg): void;

    /**
     * Called once when the actor starts, before the message loop begins.
     * Override to perform initialization.
     */
    protected function preStart(): void
    {
        // Default: no-op
    }

    /**
     * Called once when the actor stops, after the message loop ends.
     * Override to perform cleanup.
     */
    protected function postStop(): void
    {
        // Default: no-op
    }

    /**
     * Send a message to this actor's mailbox.
     *
     * @param Message $msg The message to send.
     */
    public function send(Message $msg): void
    {
        $this->mailbox->send($msg);
    }

    /**
     * Start the actor's processing loop.
     *
     * Launches a Fiber that continuously reads from the mailbox
     * and calls receive() for each message. The loop runs until
     * a poison-pill message is received or stop() is called.
     *
     * @param Dispatchers $dispatcher The dispatcher for the Launch.
     * @return Launch The Launch job managing this actor.
     */
    public function start(
        Dispatchers $dispatcher = Dispatchers::DEFAULT ,
    ): Launch {
        if ($this->running) {
            return $this->job;
        }

        $this->running = true;

        $this->job = Launch::new(function () {
            $this->preStart();

            while ($this->running) {
                try {
                    /** @var Message|null $msg */
                    $msg = $this->mailbox->tryReceive();

                    if ($msg === null) {
                        // No message available — yield and retry
                        Pause::force();
                        continue;
                    }

                    // Poison pill → graceful shutdown
                    if ($msg->isPoison()) {
                        $this->running = false;
                        break;
                    }

                    $this->receive($msg);
                } catch (\Throwable $e) {
                    // Actor receive() threw — log but don't crash the loop.
                    // Supervision (if configured) will handle restarts.
                    error_log(
                        "Actor '{$this->name}' error: " .
                        $e->getMessage() .
                        "\n" .
                        $e->getTraceAsString(),
                    );
                }
            }

            $this->postStop();
        }, $dispatcher);

        return $this->job;
    }

    /**
     * Stop the actor gracefully by sending a poison-pill message.
     *
     * The actor will finish processing its current message, then
     * exit the loop cleanly.
     */
    public function stop(): void
    {
        if (!$this->running) {
            return;
        }

        $this->mailbox->send(Message::poison($this->name));
    }

    /**
     * Check if the actor is currently running.
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Get the Launch job for this actor (null if not started).
     */
    public function getJob(): ?Launch
    {
        return $this->job;
    }
}
