<?php

declare(strict_types=1);

namespace vosaka\foroutines\selects;

use vosaka\foroutines\channel\Channel;

final class Select
{
    private array $cases = [];
    private bool $hasDefault = false;
    private mixed $defaultValue = null;

    public function onSend(Channel $channel, mixed $value, callable $action): Select
    {
        $this->cases[] = [
            'type' => 'send',
            'channel' => $channel,
            'value' => $value,
            'action' => $action
        ];
        return $this;
    }

    public function onReceive(Channel $channel, callable $action): Select
    {
        $this->cases[] = [
            'type' => 'receive',
            'channel' => $channel,
            'action' => $action
        ];
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
        foreach ($this->cases as $case) {
            if ($case['type'] === 'send') {
                if ($case['channel']->trySend($case['value'])) {
                    return $case['action']();
                }
            } elseif ($case['type'] === 'receive') {
                $value = $case['channel']->tryReceive();
                if ($value !== null) {
                    return $case['action']($value);
                }
            }
        }

        if ($this->hasDefault) {
            return $this->defaultValue;
        }

        $randomCase = $this->cases[array_rand($this->cases)];

        if ($randomCase['type'] === 'send') {
            $randomCase['channel']->send($randomCase['value']);
            return $randomCase['action']();
        } else {
            $value = $randomCase['channel']->receive();
            return $randomCase['action']($value);
        }
    }
}
