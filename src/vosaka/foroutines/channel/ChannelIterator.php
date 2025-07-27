<?php

declare(strict_types=1);

namespace vosaka\foroutines\channel;

final class ChannelIterator
{
    public function __construct(private Channel $channel) {}

    public function current(): mixed
    {
        return $this->channel->receive();
    }

    public function valid(): bool
    {
        return !$this->channel->isClosed() || !$this->channel->isEmpty();
    }

    public function next(): void
    {
        // Iterator logic handled by current()
    }

    public function key(): mixed
    {
        return null; // Channels don't have keys
    }

    public function rewind(): void
    {
        // Cannot rewind a channel
    }
}
