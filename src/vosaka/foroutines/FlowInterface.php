<?php

declare(strict_types=1);

namespace vosaka\foroutines;

/**
 * Base interface for all Flow types
 */
interface FlowInterface
{
    public function collect(callable $collector): void;
    public function map(callable $transform): FlowInterface;
    public function filter(callable $predicate): FlowInterface;
    public function onEach(callable $action): FlowInterface;
    public function catch(callable $handler): FlowInterface;
}
