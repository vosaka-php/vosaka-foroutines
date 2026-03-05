<?php

declare(strict_types=1);

require_once __DIR__ . "/../vendor/autoload.php";

use vosaka\foroutines\{
    RunBlocking,
    Launch,
    Async,
    Delay,
    Thread,
    Dispatchers,
    WorkerPool,
    Pause,
    FiberPool,
    TimeUtils,
    WorkerPoolState,
    WorkerLifecycle,
};
use vosaka\foroutines\actor\{Actor, Message, ActorSystem};
use vosaka\foroutines\supervisor\{Supervisor, RestartStrategy, ChildSpec};
use vosaka\foroutines\channel\Channel;
use function vosaka\foroutines\main;

// ─── Test Helpers ────────────────────────────────────────────────────────────

$passed = 0;
$failed = 0;
$testNames = [];

function test(string $name, callable $fn): void
{
    global $passed, $failed, $testNames;
    $testNames[] = $name;
    echo "\n  > {$name} ... ";
    try {
        $fn();
        echo "[PASS]";
        $passed++;
    } catch (\Throwable $e) {
        echo "[FAIL]: " . $e->getMessage();
        echo "\n    at " . $e->getFile() . ":" . $e->getLine();
        $failed++;
    }
}

function assert_eq(mixed $expected, mixed $actual, string $msg = ""): void
{
    if ($expected !== $actual) {
        $expectedStr = var_export($expected, true);
        $actualStr = var_export($actual, true);
        throw new \RuntimeException(
            "Assertion failed{$msg}: expected {$expectedStr}, got {$actualStr}",
        );
    }
}

function assert_true(bool $value, string $msg = ""): void
{
    if (!$value) {
        throw new \RuntimeException("Assertion failed: expected true{$msg}");
    }
}

function assert_gt(float|int $a, float|int $b, string $msg = ""): void
{
    if ($a <= $b) {
        throw new \RuntimeException(
            "Assertion failed: expected {$a} > {$b}{$msg}",
        );
    }
}

function assert_lt(float|int $a, float|int $b, string $msg = ""): void
{
    if ($a >= $b) {
        throw new \RuntimeException(
            "Assertion failed: expected {$a} < {$b}{$msg}",
        );
    }
}

// ─── Banner ──────────────────────────────────────────────────────────────────

echo "+--------------------------------------------------------------+\n";
echo "|   VOsaka Foroutines -- New Features Test Suite               |\n";
echo "|   Testing: Backoff, FiberPool, Actor, Supervisor             |\n";
echo "+--------------------------------------------------------------+\n";

// ═════════════════════════════════════════════════════════════════════════════
// FEATURE 1: Worker Respawn Backoff
// ═════════════════════════════════════════════════════════════════════════════

echo "\n\n--- FEATURE 1: Worker Respawn Backoff ---";

main(function () {
    test("WorkerPoolState has backoff configuration", function () {
        assert_eq(
            10,
            WorkerPoolState::$maxRespawnAttempts,
            " — default max respawn attempts should be 10",
        );
        assert_eq(
            100,
            WorkerPoolState::$respawnBaseDelayMs,
            " — default base delay should be 100ms",
        );
        assert_true(
            is_array(WorkerPoolState::$respawnAttempts),
            " — respawnAttempts should be an array",
        );
        assert_true(
            is_array(WorkerPoolState::$respawnNextAllowed),
            " — respawnNextAllowed should be an array",
        );
    });

    test("WorkerLifecycle::resetBackoff clears counters", function () {
        // Set some fake backoff state
        WorkerPoolState::$respawnAttempts[99] = 5;
        WorkerPoolState::$respawnNextAllowed[99] = microtime(true) + 9999;

        // Reset it
        WorkerLifecycle::resetBackoff(99);

        assert_true(
            !isset(WorkerPoolState::$respawnAttempts[99]),
            " — attempts should be cleared",
        );
        assert_true(
            !isset(WorkerPoolState::$respawnNextAllowed[99]),
            " — nextAllowed should be cleared",
        );
    });

    test("WorkerPoolState::resetAll clears backoff state", function () {
        WorkerPoolState::$respawnAttempts[1] = 3;
        WorkerPoolState::$respawnNextAllowed[1] = microtime(true) + 100;

        WorkerPoolState::resetAll();

        assert_true(
            empty(WorkerPoolState::$respawnAttempts),
            " — respawnAttempts should be empty after resetAll",
        );
        assert_true(
            empty(WorkerPoolState::$respawnNextAllowed),
            " — respawnNextAllowed should be empty after resetAll",
        );
    });

    test("Backoff config is customizable", function () {
        $origMax = WorkerPoolState::$maxRespawnAttempts;
        $origBase = WorkerPoolState::$respawnBaseDelayMs;

        WorkerPoolState::$maxRespawnAttempts = 3;
        WorkerPoolState::$respawnBaseDelayMs = 50;

        assert_eq(3, WorkerPoolState::$maxRespawnAttempts);
        assert_eq(50, WorkerPoolState::$respawnBaseDelayMs);

        // Restore
        WorkerPoolState::$maxRespawnAttempts = $origMax;
        WorkerPoolState::$respawnBaseDelayMs = $origBase;
    });
});

// ═════════════════════════════════════════════════════════════════════════════
// FEATURE 2: FiberPool
// ═════════════════════════════════════════════════════════════════════════════

echo "\n\n--- FEATURE 2: FiberPool ---";

main(function () {
    test("FiberPool::global() returns singleton", function () {
        FiberPool::resetState();
        $pool1 = FiberPool::global();
        $pool2 = FiberPool::global();
        assert_true($pool1 === $pool2, " — should return same instance");
    });

    test("FiberPool default size is 10", function () {
        FiberPool::resetState();
        assert_eq(10, FiberPool::getDefaultSize(), " — default should be 10");
    });

    test("FiberPool::setDefaultSize changes pool size", function () {
        FiberPool::resetState();
        FiberPool::setDefaultSize(20);
        assert_eq(20, FiberPool::getDefaultSize());
        $pool = FiberPool::global();
        assert_eq(20, $pool->getMaxSize(), " — global pool should use new size");
        FiberPool::resetState();
    });

    test("FiberPool::run() executes tasks", function () {
        FiberPool::resetState();
        $pool = new FiberPool(5);

        $result = $pool->run(fn() => 42);
        assert_eq(42, $result, " — should return task result");

        $result2 = $pool->run(fn() => "hello");
        assert_eq("hello", $result2, " — should return string result");
    });

    test("FiberPool::run() reuses fibers", function () {
        FiberPool::resetState();
        $pool = new FiberPool(5);

        // Run multiple tasks
        for ($i = 0; $i < 10; $i++) {
            $pool->run(fn() => $i * 2);
        }

        $stats = $pool->getStats();
        assert_true(
            $stats['reused'] > 0,
            " — should have reused at least one fiber, got {$stats['reused']}",
        );
        assert_true(
            $stats['created'] < 10,
            " — should have created fewer than 10 fibers, got {$stats['created']}",
        );
    });

    test("FiberPool::run() respects maxSize", function () {
        FiberPool::resetState();
        $pool = new FiberPool(3);

        // Run many tasks — pool should cap at 3 idle
        for ($i = 0; $i < 20; $i++) {
            $pool->run(fn() => $i);
        }

        $stats = $pool->getStats();
        assert_true(
            $stats['idle'] <= 3,
            " — idle count should be <= 3, got {$stats['idle']}",
        );
    });

    test("FiberPool::acquire creates working Fibers", function () {
        FiberPool::resetState();
        $pool = new FiberPool(5);

        $fiber = $pool->acquire(fn() => 99);
        assert_true($fiber instanceof Fiber, " — should return a Fiber");
        assert_true(!$fiber->isStarted(), " — fiber should not be started");

        $fiber->start();
        assert_true($fiber->isTerminated(), " — fiber should be terminated");
        assert_eq(99, $fiber->getReturn(), " — fiber should return 99");
    });

    test("FiberPool::setMaxSize adjusts pool", function () {
        FiberPool::resetState();
        $pool = new FiberPool(10);

        // Fill the pool
        for ($i = 0; $i < 10; $i++) {
            $pool->run(fn() => $i);
        }

        // Shrink the pool
        $pool->setMaxSize(3);
        assert_eq(3, $pool->getMaxSize());
        assert_true(
            $pool->idleCount() <= 3,
            " — idle should be <= 3 after shrink",
        );
    });

    test("FiberPool integrates with Launch", function () {
        FiberPool::resetState();
        RunBlocking::new(function () {
            $results = [];

            Launch::new(function () use (&$results) {
                $results[] = "A";
            });

            Launch::new(function () use (&$results) {
                $results[] = "B";
            });

            Thread::await();

            assert_eq(2, count($results), " — both Launch jobs should complete");
        });
    });

    test("FiberPool integrates with Async", function () {
        FiberPool::resetState();
        RunBlocking::new(function () {
            $result = Async::new(fn() => 42)->await();
            assert_eq(42, $result, " — Async should resolve via FiberPool");
        });
    });

    test("FiberPool::resetState clears everything", function () {
        FiberPool::setDefaultSize(50);
        $pool = FiberPool::global();
        $pool->run(fn() => 1);

        FiberPool::resetState();

        assert_eq(10, FiberPool::getDefaultSize(), " — default size should reset");
        // New call should create a fresh instance
        $newPool = FiberPool::global();
        $stats = $newPool->getStats();
        assert_eq(0, $stats['created'], " — new pool should have 0 created");
    });
});

// ═════════════════════════════════════════════════════════════════════════════
// FEATURE 3: Actor Model
// ═════════════════════════════════════════════════════════════════════════════

echo "\n\n--- FEATURE 3: Actor Model ---";

main(function () {
    test("Message creation and properties", function () {
        $msg = new Message('greet', 'World', 'sender-a');
        assert_eq('greet', $msg->type);
        assert_eq('World', $msg->payload);
        assert_eq('sender-a', $msg->sender);
        assert_true($msg->timestamp > 0, " — timestamp should be set");
    });

    test("Message::of factory", function () {
        $msg = Message::of('ping', 42);
        assert_eq('ping', $msg->type);
        assert_eq(42, $msg->payload);
    });

    test("Message::poison creates poison pill", function () {
        $msg = Message::poison('supervisor');
        assert_true($msg->isPoison(), " — should be poison pill");
        assert_eq('supervisor', $msg->sender);
    });

    test("Actor receives and processes messages", function () {
        $received = [];

        $actor = new class ('test-actor') extends Actor {
            public array $log = [];

            protected function receive(Message $msg): void
            {
                $this->log[] = $msg->type . ':' . $msg->payload;
            }
        };

        RunBlocking::new(function () use ($actor, &$received) {
            $actor->start();

            // Send some messages
            $actor->send(Message::of('task', 'A'));
            $actor->send(Message::of('task', 'B'));
            $actor->send(Message::of('task', 'C'));

            // Give actor time to process
            Delay::new(50);

            // Stop the actor
            $actor->stop();

            // Wait for everything to complete
            Thread::await();

            $received = $actor->log;
        });

        assert_eq(3, count($received), " — should have processed 3 messages");
        assert_eq('task:A', $received[0]);
        assert_eq('task:B', $received[1]);
        assert_eq('task:C', $received[2]);
    });

    test("Actor lifecycle hooks (preStart/postStop)", function () {
        $lifecycle = [];

        $actor = new class ('lifecycle-actor') extends Actor {
            public array $events;

            public function __construct(string $name)
            {
                parent::__construct($name);
                $this->events = [];
            }

            protected function preStart(): void
            {
                $this->events[] = 'pre-start';
            }

            protected function postStop(): void
            {
                $this->events[] = 'post-stop';
            }

            protected function receive(Message $msg): void
            {
                $this->events[] = 'received:' . $msg->type;
            }
        };

        RunBlocking::new(function () use ($actor, &$lifecycle) {
            $actor->start();

            $actor->send(Message::of('ping'));
            Delay::new(30);

            $actor->stop();
            Thread::await();

            $lifecycle = $actor->events;
        });

        assert_true(
            in_array('pre-start', $lifecycle),
            " — preStart should be called",
        );
        assert_true(
            in_array('received:ping', $lifecycle),
            " — should receive message",
        );
        assert_true(
            in_array('post-stop', $lifecycle),
            " — postStop should be called",
        );
    });

    test("ActorSystem registers and manages actors", function () {
        $system = ActorSystem::new();

        $actor1 = new class ('actor-1') extends Actor {
            public int $count = 0;
            protected function receive(Message $msg): void
            {
                $this->count++;
            }
        };

        $actor2 = new class ('actor-2') extends Actor {
            public int $count = 0;
            protected function receive(Message $msg): void
            {
                $this->count++;
            }
        };

        $system->register($actor1)->register($actor2);

        assert_eq(2, $system->count(), " — should have 2 actors");
        assert_true($system->has('actor-1'), " — should find actor-1");
        assert_true($system->has('actor-2'), " — should find actor-2");
        assert_true($system->get('actor-1') === $actor1);
    });

    test("ActorSystem send routes messages", function () {
        $system = ActorSystem::new();

        $actor = new class ('router-test') extends Actor {
            public int $msgCount = 0;
            protected function receive(Message $msg): void
            {
                $this->msgCount++;
            }
        };

        $system->register($actor);

        RunBlocking::new(function () use ($system, $actor) {
            $system->startAll();

            $system->send('router-test', Message::of('hello'));
            $system->send('router-test', Message::of('world'));

            Delay::new(50);

            $system->stopAll();
            Thread::await();
        });

        assert_gt(
            $actor->msgCount,
            0,
            " — actor should have received messages",
        );
    });

    test("ActorSystem throws on unknown actor", function () {
        $system = ActorSystem::new();
        $thrown = false;

        try {
            $system->send('nonexistent', Message::of('test'));
        } catch (\RuntimeException $e) {
            $thrown = true;
        }

        assert_true($thrown, " — should throw on unknown actor");
    });
});

// ═════════════════════════════════════════════════════════════════════════════
// FEATURE 4: Supervisor Tree
// ═════════════════════════════════════════════════════════════════════════════

echo "\n\n--- FEATURE 4: Supervisor Tree ---";

main(function () {
    test("RestartStrategy enum values exist", function () {
        assert_true(
            RestartStrategy::ONE_FOR_ONE instanceof RestartStrategy,
            " — ONE_FOR_ONE should be a RestartStrategy",
        );
        assert_true(
            RestartStrategy::ONE_FOR_ALL instanceof RestartStrategy,
        );
        assert_true(
            RestartStrategy::REST_FOR_ONE instanceof RestartStrategy,
        );
    });

    test("ChildSpec stores parameters", function () {
        $spec = new ChildSpec(
            id: 'worker-1',
            factory: fn() => null,
            maxRestarts: 3,
            maxRestartWindow: 30.0,
        );

        assert_eq('worker-1', $spec->id);
        assert_eq(3, $spec->maxRestarts);
        assert_eq(30.0, $spec->maxRestartWindow);
    });

    test("Supervisor::new creates with strategy", function () {
        $sup = Supervisor::new(RestartStrategy::ONE_FOR_ONE);
        assert_true(!$sup->isRunning(), " — should not be running initially");
    });

    test("Supervisor child() fluent API", function () {
        $sup = Supervisor::new()
            ->child(fn() => null, 'a')
            ->child(fn() => null, 'b')
            ->child(fn() => null, 'c');

        // Should not throw — fluent chaining works
        assert_true(!$sup->isRunning());
    });

    test("Supervisor ONE_FOR_ONE starts children", function () {
        $completed = [];

        RunBlocking::new(function () use (&$completed) {
            $sup = Supervisor::new(RestartStrategy::ONE_FOR_ONE)
                ->child(function () use (&$completed) {
                    $completed[] = 'child-a';
                    Delay::new(30);
                }, 'child-a')
                ->child(function () use (&$completed) {
                    $completed[] = 'child-b';
                    Delay::new(30);
                }, 'child-b');

            $sup->start();

            // Give children time to run
            Delay::new(80);

            $sup->stop();
            Thread::await();
        });

        assert_true(
            in_array('child-a', $completed),
            " — child-a should have started",
        );
        assert_true(
            in_array('child-b', $completed),
            " — child-b should have started",
        );
    });

    test("Supervisor ONE_FOR_ONE restarts crashed child", function () {
        $runCount = 0;

        RunBlocking::new(function () use (&$runCount) {
            $sup = Supervisor::new(RestartStrategy::ONE_FOR_ONE)
                ->child(
                    function () use (&$runCount) {
                        $runCount++;
                        if ($runCount <= 2) {
                            throw new \RuntimeException("Simulated crash #$runCount");
                        }
                        // Third time: succeed and stay alive briefly
                        Delay::new(50);
                    },
                    'crasher',
                    maxRestarts: 5,
                    maxRestartWindow: 10.0,
                );

            $sup->start();

            // Wait for restart cycles
            Delay::new(600);

            $sup->stop();
            Thread::await();
        });

        assert_gt(
            $runCount,
            1,
            " — child should have been restarted at least once, ran {$runCount} times",
        );
    });

    test("Supervisor stop() halts supervision", function () {
        RunBlocking::new(function () {
            $sup = Supervisor::new(RestartStrategy::ONE_FOR_ONE)
                ->child(function () {
                    Delay::new(5000); // long-running
                }, 'worker');

            $sup->start();
            assert_true($sup->isRunning(), " — should be running after start");

            Delay::new(20);

            $sup->stop();
            assert_true(!$sup->isRunning(), " — should not be running after stop");

            Thread::await();
        });
    });
});

// ═════════════════════════════════════════════════════════════════════════════
// Summary
// ═════════════════════════════════════════════════════════════════════════════

echo "\n\n+--------------------------------------------------------------+\n";
echo "| Results: {$passed} passed, {$failed} failed" .
    str_repeat(" ", 43 - strlen("{$passed} passed, {$failed} failed")) .
    "|\n";
echo "+--------------------------------------------------------------+\n\n";

exit($failed > 0 ? 1 : 0);
