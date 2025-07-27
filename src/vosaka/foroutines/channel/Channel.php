<?php

declare(strict_types=1);

namespace vosaka\foroutines\channel;

use Exception;
use Fiber;
use vosaka\foroutines\Pause;

final class Channel
{
    private array $buffer = [];
    private int $capacity;
    private array $sendQueue = [];
    private array $receiveQueue = [];
    private bool $closed = false;

    public function __construct(int $capacity = 0)
    {
        $this->capacity = $capacity;
    }

    public static function new(int $capacity = 0): Channel
    {
        return new self($capacity);
    }

    public function send(mixed $value): void
    {
        if ($this->closed) {
            throw new Exception("Channel is closed");
        }

        if (!empty($this->receiveQueue)) {
            $receiver = array_shift($this->receiveQueue);
            $receiver['fiber']->resume($value);
            return;
        }

        if (count($this->buffer) < $this->capacity || $this->capacity === 0) {
            $this->buffer[] = $value;
            return;
        }

        $currentFiber = Fiber::getCurrent();
        if ($currentFiber === null) {
            throw new Exception("send() must be called from within a Fiber");
        }

        $this->sendQueue[] = [
            'fiber' => $currentFiber,
            'value' => $value
        ];

        Pause::new();
    }

    public function receive(): mixed
    {
        if (!empty($this->buffer)) {
            $value = array_shift($this->buffer);

            if (!empty($this->sendQueue)) {
                $sender = array_shift($this->sendQueue);
                $this->buffer[] = $sender['value'];
                $sender['fiber']->resume();
            }

            return $value;
        }

        if ($this->closed) {
            throw new Exception("Channel is closed and empty");
        }

        $currentFiber = Fiber::getCurrent();
        if ($currentFiber === null) {
            throw new Exception("receive() must be called from within a Fiber");
        }

        $this->receiveQueue[] = [
            'fiber' => $currentFiber
        ];

        return Fiber::suspend();
    }

    public function trySend(mixed $value): bool
    {
        if ($this->closed) {
            return false;
        }

        if (!empty($this->receiveQueue)) {
            $receiver = array_shift($this->receiveQueue);
            $receiver['fiber']->resume($value);
            return true;
        }

        if (count($this->buffer) < $this->capacity || $this->capacity === 0) {
            $this->buffer[] = $value;
            return true;
        }

        return false;
    }

    public function tryReceive(): mixed
    {
        if (!empty($this->buffer)) {
            $value = array_shift($this->buffer);

            // Resume sender nếu có
            if (!empty($this->sendQueue)) {
                $sender = array_shift($this->sendQueue);
                $this->buffer[] = $sender['value'];
                $sender['fiber']->resume();
            }

            return $value;
        }

        return null;
    }

    public function close(): void
    {
        $this->closed = true;

        foreach ($this->receiveQueue as $receiver) {
            $receiver['fiber']->throw(new Exception("Channel is closed"));
        }

        foreach ($this->sendQueue as $sender) {
            $sender['fiber']->throw(new Exception("Channel is closed"));
        }

        $this->receiveQueue = [];
        $this->sendQueue = [];
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function isEmpty(): bool
    {
        return empty($this->buffer);
    }

    public function isFull(): bool
    {
        return count($this->buffer) >= $this->capacity && $this->capacity > 0;
    }

    public function size(): int
    {
        return count($this->buffer);
    }

    public function getIterator(): ChannelIterator
    {
        return new ChannelIterator($this);
    }
}
