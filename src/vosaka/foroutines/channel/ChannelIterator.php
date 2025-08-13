<?php

declare(strict_types=1);

namespace vosaka\foroutines\channel;

use Iterator;
use Exception;

final class ChannelIterator implements Iterator
{
    private mixed $current = null;
    private bool $valid = true;
    private int $key = 0;
    private bool $started = false;

    public function __construct(private Channel $channel) {}

    public function current(): mixed
    {
        return $this->current;
    }

    public function valid(): bool
    {
        return $this->valid;
    }

    public function next(): void
    {
        $this->key++;
        $this->fetchNext();
    }

    public function key(): int
    {
        return $this->key;
    }

    public function rewind(): void
    {
        if ($this->started) {
            throw new Exception("Cannot rewind a channel iterator - channels are forward-only");
        }

        $this->key = 0;
        $this->valid = true;
        $this->started = true;
        $this->fetchNext();
    }

    private function fetchNext(): void
    {
        try {
            if ($this->channel->isClosed() && $this->channel->isEmpty()) {
                $this->valid = false;
                $this->current = null;
                return;
            }

            $this->current = $this->channel->tryReceive();

            if ($this->current === null) {
                // No data available, check if channel is closed
                if ($this->channel->isClosed()) {
                    $this->valid = false;
                    return;
                }

                // Try blocking receive if channel is still open
                try {
                    $this->current = $this->channel->receive();
                } catch (Exception) {
                    $this->valid = false;
                    $this->current = null;
                }
            }
        } catch (Exception) {
            $this->valid = false;
            $this->current = null;
        }
    }
}
