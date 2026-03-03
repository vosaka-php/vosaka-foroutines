<?php

declare(strict_types=1);

namespace vosaka\foroutines\selects;

use vosaka\foroutines\channel\Channel;

final class Select
{
    // ─── Case type constants ─────────────────────────────────────────
    // Using int constants instead of string keys ("send"/"receive")
    // for case type identification. Avoids hash table allocation per
    // case entry and enables faster int comparison in execute().

    private const TYPE_SEND = 0;
    private const TYPE_RECEIVE = 1;

    private array $cases = [];
    private bool $hasDefault = false;
    private mixed $defaultValue = null;

    public function onSend(
        Channel $channel,
        mixed $value,
        callable $action,
    ): Select {
        // Indexed array [type, channel, action, value] instead of
        // associative ["type" => ..., "channel" => ..., ...] —
        // avoids hash table overhead per case entry.
        $this->cases[] = [self::TYPE_SEND, $channel, $action, $value];
        return $this;
    }

    public function onReceive(Channel $channel, callable $action): Select
    {
        // Indexed array [type, channel, action] — no value needed for receive.
        $this->cases[] = [self::TYPE_RECEIVE, $channel, $action];
        return $this;
    }

    public function default(mixed $value = null): Select
    {
        $this->hasDefault = true;
        $this->defaultValue = $value;
        return $this;
    }

    public function execute(): mixed
    {
        // First pass: try non-blocking operations
        foreach ($this->cases as $case) {
            // Indexed access: [0] = type, [1] = channel, [2] = action, [3] = value (send only)
            if ($case[0] === self::TYPE_SEND) {
                if ($case[1]->trySend($case[3])) {
                    return $case[2]();
                }
            } elseif ($case[0] === self::TYPE_RECEIVE) {
                $value = $case[1]->tryReceive();
                if ($value !== null) {
                    return $case[2]($value);
                }
            }
        }

        // If default is set, return it without blocking
        if ($this->hasDefault) {
            return $this->defaultValue;
        }

        // No non-blocking operation succeeded — pick a random case and block
        $randomCase = $this->cases[array_rand($this->cases)];

        if ($randomCase[0] === self::TYPE_SEND) {
            $randomCase[1]->send($randomCase[3]);
            return $randomCase[2]();
        } else {
            $value = $randomCase[1]->receive();
            return $randomCase[2]($value);
        }
    }
}
