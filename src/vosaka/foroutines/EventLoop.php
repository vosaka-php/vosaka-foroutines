<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Fiber;
use SplPriorityQueue;
use Throwable;

final class EventLoop
{
    private static ?SplPriorityQueue $queue = null;

    /**
     * Resets the event loop state to initial values.
     *
     * This is used by ForkProcess after pcntl_fork() to clear stale
     * fibers inherited from the parent process. Without this, the
     * shutdown function registered by init() would attempt to run
     * parent fibers in the child process, causing errors or hangs
     * during child exit.
     */
    public static function resetState(): void
    {
        self::$queue = null;
    }

    public function __construct()
    {
        self::init();
    }

    public static function init(): void
    {
        if (self::$queue === null) {
            self::$queue = new SplPriorityQueue();

            register_shutdown_function(function () {
                try {
                    self::runAll();
                } catch (Throwable $e) {
                    error_log(
                        "Error during EventLoop shutdown: " .
                            $e->getMessage() .
                            "\n" .
                            $e->getTraceAsString(),
                    );
                }
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
