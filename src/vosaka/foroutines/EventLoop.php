<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Fiber;
use SplPriorityQueue;

final class EventLoop
{
    private static ?SplPriorityQueue $queue = null;

    public function __construct()
    {
        self::init();
    }

    public static function init(): void
    {
        if (self::$queue === null) {
            self::$queue = new SplPriorityQueue();

            register_shutdown_function(function () {
                self::runAll();
            });
        }
    }

    public static function add(Fiber $fiber): void
    {
        self::init();
        self::$queue->insert($fiber, spl_object_id($fiber));
    }

    public static function runNext(): ?Fiber
    {
        self::init();
        if (self::$queue->isEmpty()) {
            return null;
        }
        $fiber = self::$queue->extract();
        if (!$fiber->isStarted()) {
            $fiber->start();
        }
        if (FiberUtils::fiberStillRunning($fiber)) {
            $fiber->resume();
            self::$queue->insert($fiber, spl_object_id($fiber)); // Re-add the fiber to the queue
            return null;
        }
        return $fiber;
    }

    private static function runAll(): void
    {
        while (!self::$queue->isEmpty()) {
            self::runNext();
        }
    }
}
