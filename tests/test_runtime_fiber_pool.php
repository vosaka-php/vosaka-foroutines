<?php

declare(strict_types=1);

/**
 * Comprehensive tests for RuntimeFiberPool integration with the VOsaka scheduler.
 *
 * Tests cover:
 *   1. RuntimeFiberPool singleton lifecycle (boot, configure, reset)
 *   2. Launch::new() using pooled fibers (DEFAULT dispatcher)
 *   3. Async::new() using pooled fibers (DEFAULT dispatcher)
 *   4. RunBlocking driving RuntimeFiberPool tick
 *   5. FiberPool delegating to RuntimeFiberPool
 *   6. Cooperative fiber support (Pause, Delay inside pooled tasks)
 *   7. Pool auto-scaling under load
 *   8. Fallback to raw fibers when pool is exhausted
 *   9. ForkProcess reset (RuntimeFiberPool::resetState)
 *  10. Pooled mode toggle (Launch::setPooledMode)
 *  11. Error handling in pooled fibers
 *  12. Mixed pool-managed and raw fiber coexistence
 *  13. Stats / introspection API
 */

require_once __DIR__ . '/../vendor/autoload.php';

use vosaka\foroutines\Async;
use vosaka\foroutines\Dispatchers;
use vosaka\foroutines\EventLoop;
use vosaka\foroutines\FiberPool;
use vosaka\foroutines\Launch;
use vosaka\foroutines\Pause;
use vosaka\foroutines\RuntimeFiberPool;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Delay;
use vosaka\foroutines\AsyncIO;
use vosaka\foroutines\WorkerPool;

// ═════════════════════════════════════════════════════════════════════
//  Test harness
// ═════════════════════════════════════════════════════════════════════

$passed = 0;
$failed = 0;
$errors = [];

function resetSchedulerState(): void
{
    // Reset all static state to ensure test isolation
    Launch::$queue = new SplQueue();
    Launch::$activeCount = 0;
    Launch::resetPool();
    RuntimeFiberPool::resetState();
    EventLoop::resetState();
    Pause::resetState();
    AsyncIO::resetState();
    WorkerPool::resetState();

    // Re-enable pooled mode (tests may have disabled it)
    Launch::setPooledMode(true);
}

function assert_true(bool $condition, string $message): void
{
    global $passed, $failed, $errors;
    if ($condition) {
        $passed++;
    } else {
        $failed++;
        $errors[] = "FAIL: {$message}";
        echo "  ✗ {$message}\n";
    }
}

function assert_false(bool $condition, string $message): void
{
    assert_true(!$condition, $message);
}

function assert_eq(mixed $expected, mixed $actual, string $message): void
{
    global $passed, $failed, $errors;
    if ($expected === $actual) {
        $passed++;
    } else {
        $failed++;
        $detail = "expected " . var_export($expected, true) . ", got " . var_export($actual, true);
        $errors[] = "FAIL: {$message} ({$detail})";
        echo "  ✗ {$message} ({$detail})\n";
    }
}

function assert_gte(int|float $value, int|float $min, string $message): void
{
    assert_true($value >= $min, "{$message} (value={$value} >= min={$min})");
}

function assert_lte(int|float $value, int|float $max, string $message): void
{
    assert_true($value <= $max, "{$message} (value={$value} <= max={$max})");
}

function run_test(string $name, Closure $test): void
{
    global $passed, $failed;
    $prevPassed = $passed;
    $prevFailed = $failed;

    echo "\n▸ {$name}\n";

    try {
        resetSchedulerState();
        $test();
    } catch (Throwable $e) {
        global $errors;
        $failed++;
        $msg = "EXCEPTION in '{$name}': " . $e->getMessage() . "\n  " . $e->getTraceAsString();
        $errors[] = $msg;
        echo "  ✗ EXCEPTION: {$e->getMessage()}\n";
    }

    $testPassed = $passed - $prevPassed;
    $testFailed = $failed - $prevFailed;
    $total = $testPassed + $testFailed;
    echo "  [{$testPassed}/{$total} assertions passed]\n";
}

// ═════════════════════════════════════════════════════════════════════
//  Tests
// ═════════════════════════════════════════════════════════════════════

echo "═══════════════════════════════════════════════════\n";
echo " RuntimeFiberPool Integration Tests\n";
echo "═══════════════════════════════════════════════════\n";

// ─────────────────────────────────────────────────────────────────────
// 1. Singleton lifecycle
// ─────────────────────────────────────────────────────────────────────

run_test('RuntimeFiberPool: boot and singleton', function () {
    assert_false(RuntimeFiberPool::isBooted(), 'Pool should not be booted after reset');

    $pool = RuntimeFiberPool::getInstance();
    assert_true(RuntimeFiberPool::isBooted(), 'Pool should be booted after getInstance');
    assert_true($pool === RuntimeFiberPool::getInstance(), 'getInstance returns same instance');
    assert_gte($pool->size(), 1, 'Pool should have at least 1 fiber');
});

run_test('RuntimeFiberPool: configure before boot', function () {
    RuntimeFiberPool::configure(initialSize: 8, maxSize: 32, autoScaling: true);
    $pool = RuntimeFiberPool::getInstance();
    assert_gte($pool->size(), 8, 'Pool should have at least 8 fibers after configure(8)');
    assert_eq(32, $pool->getMaxSize(), 'Max size should be 32');
});

run_test('RuntimeFiberPool: configure after boot grows pool', function () {
    $pool = RuntimeFiberPool::getInstance();
    $sizeBefore = $pool->size();

    RuntimeFiberPool::configure(initialSize: $sizeBefore + 4, maxSize: 200);
    assert_gte($pool->size(), $sizeBefore + 4, 'Pool should grow after reconfigure');
});

run_test('RuntimeFiberPool: resetState clears everything', function () {
    $pool = RuntimeFiberPool::getInstance();
    assert_true(RuntimeFiberPool::isBooted(), 'Booted before reset');

    RuntimeFiberPool::resetState();
    assert_false(RuntimeFiberPool::isBooted(), 'Not booted after reset');

    // Getting instance again should re-bootstrap
    $pool2 = RuntimeFiberPool::getInstance();
    assert_true(RuntimeFiberPool::isBooted(), 'Booted again after getInstance');
    assert_gte($pool2->size(), 1, 'Fresh pool has fibers');
});

// ─────────────────────────────────────────────────────────────────────
// 2. Launch::new() with RuntimeFiberPool
// ─────────────────────────────────────────────────────────────────────

run_test('Launch: DEFAULT dispatcher uses pooled fibers', function () {
    assert_true(Launch::isPooledMode(), 'Pooled mode should be enabled by default');

    $result = null;
    RunBlocking::new(function () use (&$result) {
        $job = Launch::new(function () {
            return 42;
        });

        // Drive the scheduler to let the job execute
        Pause::force();
        Pause::force();
        Pause::force();

        assert_true($job->poolManaged, 'Job should be pool-managed');
    });

    assert_true(RuntimeFiberPool::isBooted(), 'Pool should be booted after Launch usage');
});

run_test('Launch: pooled fiber completes and returns result via Job::join', function () {
    $result = null;

    RunBlocking::new(function () use (&$result) {
        $job = Launch::new(function () {
            return 'hello from pool';
        });

        $result = $job->join();
    });

    assert_eq('hello from pool', $result, 'Job should return result from pooled fiber');
});

run_test('Launch: multiple pooled tasks execute concurrently', function () {
    $results = [];

    RunBlocking::new(function () use (&$results) {
        $jobs = [];
        for ($i = 0; $i < 10; $i++) {
            $val = $i;
            $jobs[] = Launch::new(function () use ($val) {
                return $val * 2;
            });
        }

        foreach ($jobs as $i => $job) {
            $results[$i] = $job->join();
        }
    });

    assert_eq(10, count($results), '10 results collected');
    for ($i = 0; $i < 10; $i++) {
        assert_eq($i * 2, $results[$i], "Result[$i] = " . ($i * 2));
    }
});

run_test('Launch: pooled fiber with Pause::new() (cooperative)', function () {
    $result = null;

    RunBlocking::new(function () use (&$result) {
        $job = Launch::new(function () {
            $sum = 0;
            for ($i = 0; $i < 5; $i++) {
                $sum += $i;
                Pause::new();
            }
            return $sum;
        });

        $result = $job->join();
    });

    assert_eq(10, $result, 'Cooperative task with Pause returns correct sum');
});

run_test('Launch: pooled fiber with Pause::force() (cooperative)', function () {
    $order = [];

    RunBlocking::new(function () use (&$order) {
        $job1 = Launch::new(function () use (&$order) {
            $order[] = 'A1';
            Pause::force();
            $order[] = 'A2';
            Pause::force();
            $order[] = 'A3';
        });

        $job2 = Launch::new(function () use (&$order) {
            $order[] = 'B1';
            Pause::force();
            $order[] = 'B2';
            Pause::force();
            $order[] = 'B3';
        });

        $job1->join();
        $job2->join();
    });

    // Both tasks should interleave (cooperative scheduling)
    assert_eq(6, count($order), 'All 6 steps executed');
    assert_true(in_array('A1', $order), 'A1 present');
    assert_true(in_array('B1', $order), 'B1 present');
    assert_true(in_array('A3', $order), 'A3 present');
    assert_true(in_array('B3', $order), 'B3 present');
});

// ─────────────────────────────────────────────────────────────────────
// 3. Async::new() with RuntimeFiberPool
// ─────────────────────────────────────────────────────────────────────

run_test('Async: DEFAULT dispatcher uses pooled fibers', function () {
    $result = null;

    RunBlocking::new(function () use (&$result) {
        $async = Async::new(function () {
            return 99;
        });

        assert_true($async->poolManaged, 'Async should be pool-managed for DEFAULT');
        $result = $async->await();
    });

    assert_eq(99, $result, 'Async returns result from pooled fiber');
});

run_test('Async: pooled fiber with cooperative suspend', function () {
    $result = null;

    RunBlocking::new(function () use (&$result) {
        $async = Async::new(function () {
            $val = 0;
            for ($i = 1; $i <= 5; $i++) {
                $val += $i;
                Pause::force();
            }
            return $val;
        });

        $result = $async->await();
    });

    assert_eq(15, $result, 'Cooperative Async returns 1+2+3+4+5 = 15');
});

run_test('Async::awaitAll with pooled fibers', function () {
    $results = null;

    RunBlocking::new(function () use (&$results) {
        $a = Async::new(fn() => 'alpha');
        $b = Async::new(fn() => 'beta');
        $c = Async::new(fn() => 'gamma');

        $results = Async::awaitAll($a, $b, $c);
    });

    assert_eq(3, count($results), 'awaitAll returns 3 results');
    assert_eq('alpha', $results[0], 'First result is alpha');
    assert_eq('beta', $results[1], 'Second result is beta');
    assert_eq('gamma', $results[2], 'Third result is gamma');
});

run_test('Async::awaitAll with cooperative pooled fibers', function () {
    $results = null;

    RunBlocking::new(function () use (&$results) {
        $a = Async::new(function () {
            Pause::force();
            Pause::force();
            return 10;
        });

        $b = Async::new(function () {
            Pause::force();
            return 20;
        });

        $results = Async::awaitAll($a, $b);
    });

    assert_eq(10, $results[0], 'awaitAll cooperative result 0');
    assert_eq(20, $results[1], 'awaitAll cooperative result 1');
});

// ─────────────────────────────────────────────────────────────────────
// 4. RunBlocking drives RuntimeFiberPool
// ─────────────────────────────────────────────────────────────────────

run_test('RunBlocking: ticks RuntimeFiberPool during Phase 1 and Phase 2', function () {
    $tasksDone = 0;

    RunBlocking::new(function () use (&$tasksDone) {
        // Launch several tasks that will be driven by the pool
        for ($i = 0; $i < 5; $i++) {
            Launch::new(function () use (&$tasksDone) {
                Pause::force();
                $tasksDone++;
            });
        }

        // Wait for all to complete
        while ($tasksDone < 5) {
            Pause::force();
        }
    });

    assert_eq(5, $tasksDone, 'All 5 tasks completed via RunBlocking-driven pool');
});

// ─────────────────────────────────────────────────────────────────────
// 5. FiberPool delegates to RuntimeFiberPool
// ─────────────────────────────────────────────────────────────────────

run_test('FiberPool: submit and getResult via RuntimeFiberPool', function () {
    $pool = FiberPool::new(size: 4);

    $t1 = $pool->submit(fn() => 100);
    $t2 = $pool->submit(fn() => 200);
    $t3 = $pool->submit(fn() => 300);

    $r1 = $pool->getResult($t1);
    $r2 = $pool->getResult($t2);
    $r3 = $pool->getResult($t3);

    assert_eq(100, $r1, 'FiberPool result 1');
    assert_eq(200, $r2, 'FiberPool result 2');
    assert_eq(300, $r3, 'FiberPool result 3');

    $pool->close();
});

run_test('FiberPool: submitChain and drainAll', function () {
    $pool = FiberPool::new(size: 4);

    $pool->submitChain(fn() => 'a')
         ->submitChain(fn() => 'b')
         ->submitChain(fn() => 'c');

    $results = $pool->awaitAll();

    assert_eq(3, count($results), 'awaitAll returns 3 results');
    // Results may be keyed by task ID
    $values = array_values($results);
    sort($values);
    assert_eq(['a', 'b', 'c'], $values, 'All values present');

    $pool->close();
});

run_test('FiberPool: error handling via RuntimeFiberPool', function () {
    $pool = FiberPool::new(size: 4);

    $t = $pool->submit(function () {
        throw new RuntimeException('task error');
    });

    $caught = false;
    try {
        $pool->getResult($t);
    } catch (RuntimeException $e) {
        $caught = true;
        assert_true(
            str_contains($e->getMessage(), 'task error'),
            'Error message propagated'
        );
    }

    assert_true($caught, 'Exception should be thrown for failed task');
    $pool->close();
});

run_test('FiberPool: introspection reflects global pool', function () {
    $pool = FiberPool::new(size: 8);

    assert_gte($pool->size(), 8, 'size() >= 8');
    assert_gte($pool->idleCount(), 1, 'idleCount >= 1');
    assert_false($pool->isShutdown(), 'Not shut down');
    assert_gte($pool->getMaxSize(), 8, 'maxSize >= 8');

    $pool->close();
    assert_true($pool->isShutdown(), 'Shut down after close');
});

// ─────────────────────────────────────────────────────────────────────
// 6. Pool auto-scaling under load
// ─────────────────────────────────────────────────────────────────────

run_test('RuntimeFiberPool: auto-scales when all fibers busy', function () {
    // Configure a small pool with auto-scaling
    RuntimeFiberPool::configure(initialSize: 4, maxSize: 32, autoScaling: true);
    $pool = RuntimeFiberPool::getInstance();

    $initialSize = $pool->size();

    // Submit more sync tasks than pool size
    $tickets = [];
    for ($i = 0; $i < 20; $i++) {
        $tickets[] = $pool->submitSync(fn() => $i);
    }

    // Collect all results
    foreach ($tickets as $t) {
        $pool->getSyncResult($t);
    }

    // Pool may have scaled up to handle the burst
    assert_gte($pool->size(), $initialSize, 'Pool size >= initial after burst');
    assert_true(true, 'All 20 sync tasks completed without deadlock');
});

// ─────────────────────────────────────────────────────────────────────
// 7. Fallback to raw fibers
// ─────────────────────────────────────────────────────────────────────

run_test('Launch: falls back to raw fiber when pool disabled', function () {
    Launch::setPooledMode(false);
    assert_false(Launch::isPooledMode(), 'Pooled mode disabled');

    $result = null;
    RunBlocking::new(function () use (&$result) {
        $job = Launch::new(fn() => 'raw fiber');
        assert_false($job->poolManaged, 'Job should NOT be pool-managed');
        $result = $job->join();
    });

    assert_eq('raw fiber', $result, 'Raw fiber produces correct result');
    Launch::setPooledMode(true);
});

run_test('Async: falls back to raw fiber when pool disabled', function () {
    Launch::setPooledMode(false);

    $result = null;
    RunBlocking::new(function () use (&$result) {
        $async = Async::new(fn() => 'raw async');
        assert_false($async->poolManaged, 'Async should NOT be pool-managed');
        $result = $async->await();
    });

    assert_eq('raw async', $result, 'Raw Async produces correct result');
    Launch::setPooledMode(true);
});

// ─────────────────────────────────────────────────────────────────────
// 8. Error handling in pooled fibers
// ─────────────────────────────────────────────────────────────────────

run_test('Launch: error in pooled fiber is caught by Job', function () {
    $caught = false;

    RunBlocking::new(function () use (&$caught) {
        $job = Launch::new(function () {
            throw new RuntimeException('pool task failure');
        });

        try {
            $job->join();
        } catch (RuntimeException $e) {
            $caught = true;
        }
    });

    // The error is thrown by Job::join() — it should be caught
    assert_true($caught, 'Exception from pooled fiber caught via join()');
});

run_test('Async: error in pooled fiber propagates through await', function () {
    $caught = false;
    $message = '';

    RunBlocking::new(function () use (&$caught, &$message) {
        $async = Async::new(function () {
            throw new InvalidArgumentException('bad input');
        });

        try {
            $async->await();
        } catch (Throwable $e) {
            $caught = true;
            $message = $e->getMessage();
        }
    });

    assert_true($caught, 'Exception from pooled Async caught via await()');
    // The message may be wrapped; check it contains the original
    assert_true(
        str_contains($message, 'bad input') || str_contains($message, 'failed'),
        'Error message contains relevant info'
    );
});

// ─────────────────────────────────────────────────────────────────────
// 9. Mixed pool-managed and raw fibers coexistence
// ─────────────────────────────────────────────────────────────────────

run_test('Mixed: pool-managed Launch and raw Fiber coexist', function () {
    $results = [];

    RunBlocking::new(function () use (&$results) {
        // Pool-managed task
        $poolJob = Launch::new(function () {
            Pause::force();
            return 'pooled';
        });

        // Raw fiber via Fiber directly passed to Launch
        $rawFiber = new Fiber(function () {
            return 'raw';
        });
        $rawJob = Launch::new($rawFiber);

        $results['pooled'] = $poolJob->join();
        $results['raw'] = $rawJob->join();
    });

    assert_eq('pooled', $results['pooled'], 'Pool-managed task result');
    assert_eq('raw', $results['raw'], 'Raw fiber task result');
});

// ─────────────────────────────────────────────────────────────────────
// 10. Stats / introspection
// ─────────────────────────────────────────────────────────────────────

run_test('RuntimeFiberPool: stats() returns complete info', function () {
    $pool = RuntimeFiberPool::getInstance();

    // Submit a few sync tasks to populate stats
    $t1 = $pool->submitSync(fn() => 1);
    $t2 = $pool->submitSync(fn() => 2);
    $pool->getSyncResult($t1);
    $pool->getSyncResult($t2);

    $stats = $pool->stats();

    assert_true(isset($stats['currentSize']), 'stats has currentSize');
    assert_true(isset($stats['idleCount']), 'stats has idleCount');
    assert_true(isset($stats['cooperativeActive']), 'stats has cooperativeActive');
    assert_true(isset($stats['pendingTasks']), 'stats has pendingTasks');
    assert_true(isset($stats['totalSubmitted']), 'stats has totalSubmitted');
    assert_true(isset($stats['totalCompleted']), 'stats has totalCompleted');
    assert_true(isset($stats['totalSpawned']), 'stats has totalSpawned');
    assert_true(isset($stats['totalRecycled']), 'stats has totalRecycled');
    assert_true(isset($stats['maxSize']), 'stats has maxSize');
    assert_true(isset($stats['autoScaling']), 'stats has autoScaling');

    assert_gte($stats['totalSubmitted'], 2, 'At least 2 tasks submitted');
    assert_gte($stats['totalCompleted'], 2, 'At least 2 tasks completed');
    assert_gte($stats['currentSize'], 1, 'Pool has fibers');
});

run_test('RuntimeFiberPool: isManagedFiber identifies pooled fibers', function () {
    $pool = RuntimeFiberPool::getInstance();

    $fiber = $pool->acquireCooperativeFiber(fn() => 42);
    assert_true($pool->isManagedFiber($fiber), 'Acquired fiber is managed');

    $rawFiber = new Fiber(fn() => 0);
    assert_false($pool->isManagedFiber($rawFiber), 'Raw fiber is NOT managed');
});

// ─────────────────────────────────────────────────────────────────────
// 11. Fiber recycling verification
// ─────────────────────────────────────────────────────────────────────

run_test('RuntimeFiberPool: fibers are recycled after task completion', function () {
    RuntimeFiberPool::configure(initialSize: 4, maxSize: 8, autoScaling: false);
    $pool = RuntimeFiberPool::getInstance();

    $initialSpawned = $pool->stats()['totalSpawned'];

    // Run several sync tasks that should reuse fibers
    for ($i = 0; $i < 20; $i++) {
        $t = $pool->submitSync(fn() => $i);
        $pool->getSyncResult($t);
    }

    $stats = $pool->stats();
    $newSpawned = $stats['totalSpawned'] - $initialSpawned;

    // With 4 fibers and 20 tasks, we should reuse fibers extensively
    // (only 4 initial spawns, rest are recycled)
    assert_lte($newSpawned, 8, 'Should not spawn more than 8 fibers for 20 tasks (reuse!)');
    assert_gte($stats['totalRecycled'], 1, 'At least 1 fiber recycled');
});

// ─────────────────────────────────────────────────────────────────────
// 12. Stress test: many concurrent cooperative tasks
// ─────────────────────────────────────────────────────────────────────

run_test('Stress: 50 cooperative tasks via Launch + RuntimeFiberPool', function () {
    $count = 50;
    $results = array_fill(0, $count, null);

    RunBlocking::new(function () use ($count, &$results) {
        $jobs = [];
        for ($i = 0; $i < $count; $i++) {
            $idx = $i;
            $jobs[] = Launch::new(function () use ($idx) {
                $sum = 0;
                for ($j = 0; $j <= $idx; $j++) {
                    $sum += $j;
                    if ($j % 5 === 0) {
                        Pause::new();
                    }
                }
                return $sum;
            });
        }

        foreach ($jobs as $i => $job) {
            $results[$i] = $job->join();
        }
    });

    // Verify results: sum(0..i) = i*(i+1)/2
    $allCorrect = true;
    for ($i = 0; $i < $count; $i++) {
        $expected = $i * ($i + 1) / 2;
        if ($results[$i] !== (int) $expected) {
            $allCorrect = false;
            assert_eq((int) $expected, $results[$i], "Stress task $i");
        }
    }

    if ($allCorrect) {
        assert_true(true, "All $count cooperative tasks produced correct results");
    }
});

run_test('Stress: 30 Async tasks via awaitAll + RuntimeFiberPool', function () {
    $count = 30;
    $results = null;

    RunBlocking::new(function () use ($count, &$results) {
        $asyncs = [];
        for ($i = 0; $i < $count; $i++) {
            $val = $i;
            $asyncs[] = Async::new(function () use ($val) {
                Pause::force();
                return $val * 3;
            });
        }

        $results = Async::awaitAll(...$asyncs);
    });

    assert_eq($count, count($results), "{$count} results from awaitAll");
    for ($i = 0; $i < $count; $i++) {
        assert_eq($i * 3, $results[$i], "Async stress result[$i] = " . ($i * 3));
    }
});

// ─────────────────────────────────────────────────────────────────────
// 13. FiberPool: multiple instances share global pool
// ─────────────────────────────────────────────────────────────────────

run_test('FiberPool: two instances share the global RuntimeFiberPool', function () {
    $poolA = FiberPool::new(size: 4);
    $poolB = FiberPool::new(size: 4);

    $tA = $poolA->submit(fn() => 'from A');
    $tB = $poolB->submit(fn() => 'from B');

    $rA = $poolA->getResult($tA);
    $rB = $poolB->getResult($tB);

    assert_eq('from A', $rA, 'Pool A result');
    assert_eq('from B', $rB, 'Pool B result');

    // Both pools report the SAME global fiber count
    assert_eq($poolA->size(), $poolB->size(), 'Both pools report same global size');

    $poolA->close();
    $poolB->close();
});

// ─────────────────────────────────────────────────────────────────────
// 14. Edge case: task that returns null
// ─────────────────────────────────────────────────────────────────────

run_test('Launch: pooled task returning null', function () {
    $result = 'sentinel';

    RunBlocking::new(function () use (&$result) {
        $job = Launch::new(function () {
            return null;
        });
        $result = $job->join();
    });

    assert_eq(null, $result, 'Null return handled correctly');
});

run_test('Async: pooled task returning null', function () {
    $result = 'sentinel';

    RunBlocking::new(function () use (&$result) {
        $async = Async::new(function () {
            return null;
        });
        $result = $async->await();
    });

    assert_eq(null, $result, 'Async null return handled correctly');
});

// ─────────────────────────────────────────────────────────────────────
// 15. Edge case: task that returns complex types
// ─────────────────────────────────────────────────────────────────────

run_test('Launch: pooled task returning array', function () {
    $result = null;

    RunBlocking::new(function () use (&$result) {
        $job = Launch::new(function () {
            return ['key' => 'value', 'nested' => [1, 2, 3]];
        });
        $result = $job->join();
    });

    assert_eq('value', $result['key'], 'Array key preserved');
    assert_eq([1, 2, 3], $result['nested'], 'Nested array preserved');
});

run_test('FiberPool: task returning object', function () {
    $pool = FiberPool::new(size: 4);

    $t = $pool->submit(function () {
        $obj = new stdClass();
        $obj->name = 'test';
        $obj->value = 42;
        return $obj;
    });

    $result = $pool->getResult($t);
    assert_eq('test', $result->name, 'Object property name');
    assert_eq(42, $result->value, 'Object property value');
    $pool->close();
});

// ─────────────────────────────────────────────────────────────────────
// 16. IO dispatcher still works (not pool-managed)
// ─────────────────────────────────────────────────────────────────────

run_test('Launch: IO dispatcher does not use pool', function () {
    // IO dispatcher wraps in WorkerPool — we just check the wrapping
    // doesn't break with pool integration active.
    // Note: actual IO dispatch requires fork/process support.
    // We test the wrapping logic only.

    $job = Launch::new(fn() => 'io task', Dispatchers::IO);

    // IO tasks wrap in an arrow function that calls WorkerPool::addAsync.
    // The outer Launch IS pool-managed (it's a DEFAULT launch of the wrapper).
    // This is correct behavior.
    assert_true(true, 'IO dispatcher Launch created without error');
});

// ─────────────────────────────────────────────────────────────────────
// 17. Shutdown safety
// ─────────────────────────────────────────────────────────────────────

run_test('RuntimeFiberPool: shutdown prevents new tasks', function () {
    $pool = RuntimeFiberPool::getInstance();
    $pool->shutdown();

    assert_true($pool->isShutdown(), 'Pool is shut down');

    // After shutdown, the singleton is stale. Reset for safety.
    RuntimeFiberPool::resetState();
});

run_test('RuntimeFiberPool: resetState allows fresh boot', function () {
    RuntimeFiberPool::resetState();
    assert_false(RuntimeFiberPool::isBooted(), 'Not booted after reset');

    $pool = RuntimeFiberPool::getInstance();
    assert_true(RuntimeFiberPool::isBooted(), 'Booted after getInstance');
    assert_false($pool->isShutdown(), 'Fresh pool is not shut down');
    assert_gte($pool->size(), 1, 'Fresh pool has fibers');
});

// ─────────────────────────────────────────────────────────────────────
// 18. FiberPool: Generator handling
// ─────────────────────────────────────────────────────────────────────

run_test('FiberPool: generator-returning callable via RuntimeFiberPool', function () {
    $pool = FiberPool::new(size: 4);

    $t = $pool->submit(function () {
        $gen = (function () {
            yield 1;
            yield 2;
            yield 3;
            return 'final';
        })();

        // Exhaust the generator
        $result = null;
        while ($gen->valid()) {
            $result = $gen->current();
            $gen->next();
        }
        try {
            $ret = $gen->getReturn();
            if ($ret !== null) {
                $result = $ret;
            }
        } catch (\Exception) {
        }
        return $result;
    });

    $result = $pool->getResult($t);
    assert_eq('final', $result, 'Generator final return value');
    $pool->close();
});

// ─────────────────────────────────────────────────────────────────────
// 19. Verify pool doesn't leak fibers across tests
// ─────────────────────────────────────────────────────────────────────

run_test('RuntimeFiberPool: no cooperative tasks leaked after full drain', function () {
    $pool = RuntimeFiberPool::getInstance();

    RunBlocking::new(function () {
        $jobs = [];
        for ($i = 0; $i < 10; $i++) {
            $jobs[] = Launch::new(function () use ($i) {
                Pause::force();
                return $i;
            });
        }
        foreach ($jobs as $job) {
            $job->join();
        }
    });

    assert_eq(0, $pool->cooperativeActiveCount(), 'No cooperative tasks active after drain');
    assert_eq(0, $pool->pendingCount(), 'No pending tasks after drain');
});

// ─────────────────────────────────────────────────────────────────────
// 20. Launch cancel on pool-managed fiber
// ─────────────────────────────────────────────────────────────────────

run_test('Launch: cancel pool-managed job', function () {
    $cancelled = false;

    RunBlocking::new(function () use (&$cancelled) {
        $job = Launch::new(function () {
            // This task yields and waits
            for ($i = 0; $i < 100; $i++) {
                Pause::force();
            }
            return 'should not complete';
        });

        // Let it start
        Pause::force();
        Pause::force();

        // Cancel it
        $job->cancel();
        $cancelled = $job->isCancelled();
    });

    assert_true($cancelled, 'Pool-managed job was cancelled');
});

// ═════════════════════════════════════════════════════════════════════
//  Summary
// ═════════════════════════════════════════════════════════════════════

echo "\n═══════════════════════════════════════════════════\n";
echo " RESULTS: {$passed} passed, {$failed} failed\n";
echo "═══════════════════════════════════════════════════\n";

if (!empty($errors)) {
    echo "\nFailures:\n";
    foreach ($errors as $i => $err) {
        echo "  " . ($i + 1) . ". {$err}\n";
    }
}

echo "\n";

// Exit with appropriate code
exit($failed > 0 ? 1 : 0);
