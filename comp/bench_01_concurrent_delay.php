<?php

/**
 * Benchmark 01: Concurrent Delay
 *
 * Compares blocking sequential sleep() vs VOsaka async parallel Delay::new().
 *
 * Scenario:
 *   We have N tasks, each needing to "wait" for a certain duration (simulating
 *   I/O latency, API calls, database queries, etc.).
 *
 *   - Blocking approach:  Execute sleep() sequentially → total time = sum of all waits.
 *   - Async approach:     Launch all delays concurrently via VOsaka → total time ≈ max(waits).
 *
 * This is the most fundamental async benchmark — if concurrency works at all,
 * the async approach should be dramatically faster when tasks overlap.
 *
 * Expected result:
 *   Async should be ~Nx faster where N = number of concurrent tasks, because
 *   all delays run in parallel instead of sequentially.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/BenchHelper.php';

use comp\BenchHelper;
use vosaka\foroutines\Async;
use vosaka\foroutines\Delay;
use vosaka\foroutines\Dispatchers;
use vosaka\foroutines\Launch;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Thread;

use function vosaka\foroutines\main;

main(function () {
    BenchHelper::header('Benchmark 01: Concurrent Delay');
    BenchHelper::info('Blocking sequential sleep vs VOsaka async parallel Delay');
    BenchHelper::info('PHP ' . PHP_VERSION . ' | ' . PHP_OS);
    BenchHelper::separator();

    // ─────────────────────────────────────────────────────────────────
    // Test 1: 5 tasks × 200ms each
    // Blocking: ~1000ms  |  Async: ~200ms  |  Expected speedup: ~5x
    // ─────────────────────────────────────────────────────────────────
    BenchHelper::subHeader('Test 1: 5 tasks × 200ms delay');

    $taskCount = 5;
    $delayMs   = 200;

    // --- Blocking approach: sequential usleep ---
    [, $blockingMs] = BenchHelper::measure(function () use ($taskCount, $delayMs) {
        for ($i = 0; $i < $taskCount; $i++) {
            usleep($delayMs * 1000); // simulate blocking wait
        }
    });
    BenchHelper::timing('Blocking (sequential usleep):', $blockingMs);

    // --- Async approach: concurrent Delay::new ---
    [, $asyncMs] = BenchHelper::measure(function () use ($taskCount, $delayMs) {
        RunBlocking::new(function () use ($taskCount, $delayMs) {
            for ($i = 0; $i < $taskCount; $i++) {
                Launch::new(function () use ($delayMs) {
                    Delay::new($delayMs);
                });
            }
            Thread::await();
        });
        Thread::await();
    });
    BenchHelper::timing('Async (concurrent Delay):', $asyncMs);

    BenchHelper::comparison('5×200ms', $blockingMs, $asyncMs);
    BenchHelper::record('5×200ms delay', $blockingMs, $asyncMs, 'basic concurrency');

    // ─────────────────────────────────────────────────────────────────
    // Test 2: 10 tasks × 100ms each
    // Blocking: ~1000ms  |  Async: ~100ms  |  Expected speedup: ~10x
    // ─────────────────────────────────────────────────────────────────
    BenchHelper::subHeader('Test 2: 10 tasks × 100ms delay');

    $taskCount = 10;
    $delayMs   = 100;

    [, $blockingMs] = BenchHelper::measure(function () use ($taskCount, $delayMs) {
        for ($i = 0; $i < $taskCount; $i++) {
            usleep($delayMs * 1000);
        }
    });
    BenchHelper::timing('Blocking (sequential usleep):', $blockingMs);

    [, $asyncMs] = BenchHelper::measure(function () use ($taskCount, $delayMs) {
        RunBlocking::new(function () use ($taskCount, $delayMs) {
            for ($i = 0; $i < $taskCount; $i++) {
                Launch::new(function () use ($delayMs) {
                    Delay::new($delayMs);
                });
            }
            Thread::await();
        });
        Thread::await();
    });
    BenchHelper::timing('Async (concurrent Delay):', $asyncMs);

    BenchHelper::comparison('10×100ms', $blockingMs, $asyncMs);
    BenchHelper::record('10×100ms delay', $blockingMs, $asyncMs, 'more tasks');

    // ─────────────────────────────────────────────────────────────────
    // Test 3: 20 tasks × 50ms each
    // Blocking: ~1000ms  |  Async: ~50ms  |  Expected speedup: ~20x
    // ─────────────────────────────────────────────────────────────────
    BenchHelper::subHeader('Test 3: 20 tasks × 50ms delay');

    $taskCount = 20;
    $delayMs   = 50;

    [, $blockingMs] = BenchHelper::measure(function () use ($taskCount, $delayMs) {
        for ($i = 0; $i < $taskCount; $i++) {
            usleep($delayMs * 1000);
        }
    });
    BenchHelper::timing('Blocking (sequential usleep):', $blockingMs);

    [, $asyncMs] = BenchHelper::measure(function () use ($taskCount, $delayMs) {
        RunBlocking::new(function () use ($taskCount, $delayMs) {
            for ($i = 0; $i < $taskCount; $i++) {
                Launch::new(function () use ($delayMs) {
                    Delay::new($delayMs);
                });
            }
            Thread::await();
        });
        Thread::await();
    });
    BenchHelper::timing('Async (concurrent Delay):', $asyncMs);

    BenchHelper::comparison('20×50ms', $blockingMs, $asyncMs);
    BenchHelper::record('20×50ms delay', $blockingMs, $asyncMs, 'many small tasks');

    // ─────────────────────────────────────────────────────────────────
    // Test 4: Staggered delays (different durations)
    // Tasks: 100ms, 200ms, 300ms, 400ms, 500ms
    // Blocking: ~1500ms  |  Async: ~500ms  |  Expected speedup: ~3x
    // ─────────────────────────────────────────────────────────────────
    BenchHelper::subHeader('Test 4: Staggered delays (100..500ms)');

    $delays = [100, 200, 300, 400, 500];

    [, $blockingMs] = BenchHelper::measure(function () use ($delays) {
        foreach ($delays as $ms) {
            usleep($ms * 1000);
        }
    });
    BenchHelper::timing('Blocking (sequential):', $blockingMs);

    [, $asyncMs] = BenchHelper::measure(function () use ($delays) {
        RunBlocking::new(function () use ($delays) {
            foreach ($delays as $ms) {
                Launch::new(function () use ($ms) {
                    Delay::new($ms);
                });
            }
            Thread::await();
        });
        Thread::await();
    });
    BenchHelper::timing('Async (concurrent):', $asyncMs);

    BenchHelper::comparison('Staggered 100-500ms', $blockingMs, $asyncMs);
    BenchHelper::record('Staggered delays', $blockingMs, $asyncMs, '100+200+300+400+500ms');

    // ─────────────────────────────────────────────────────────────────
    // Test 5: Async::new + await pattern (return values)
    // 5 tasks × 150ms, each returns a value → collect via ->await()
    // ─────────────────────────────────────────────────────────────────
    BenchHelper::subHeader('Test 5: Async/Await with return values (5×150ms)');

    $taskCount = 5;
    $delayMs   = 150;

    [, $blockingMs] = BenchHelper::measure(function () use ($taskCount, $delayMs) {
        $results = [];
        for ($i = 0; $i < $taskCount; $i++) {
            usleep($delayMs * 1000);
            $results[] = $i * $i;
        }
        return $results;
    });
    BenchHelper::timing('Blocking (sequential):', $blockingMs);

    [$asyncResults, $asyncMs] = BenchHelper::measure(function () use ($taskCount, $delayMs) {
        $results = [];
        RunBlocking::new(function () use ($taskCount, $delayMs, &$results) {
            $asyncHandles = [];
            for ($i = 0; $i < $taskCount; $i++) {
                $asyncHandles[] = Async::new(function () use ($i, $delayMs) {
                    Delay::new($delayMs);
                    return $i * $i;
                });
            }
            foreach ($asyncHandles as $handle) {
                $results[] = $handle->await();
            }
            Thread::await();
        });
        Thread::await();
        return $results;
    });
    BenchHelper::timing('Async (concurrent await):', $asyncMs);

    BenchHelper::comparison('5×150ms async/await', $blockingMs, $asyncMs);
    BenchHelper::assert('All results collected', count($asyncResults) === $taskCount);
    BenchHelper::assert('Results are correct', $asyncResults === [0, 1, 4, 9, 16]);
    BenchHelper::record('Async/Await 5×150ms', $blockingMs, $asyncMs, 'with return values');

    // ─────────────────────────────────────────────────────────────────
    // Test 6: High concurrency — 50 tasks × 100ms
    // Blocking: ~5000ms  |  Async: ~100ms  |  Expected speedup: ~50x
    // ─────────────────────────────────────────────────────────────────
    BenchHelper::subHeader('Test 6: High concurrency — 50 tasks × 100ms');

    $taskCount = 50;
    $delayMs   = 100;

    [, $blockingMs] = BenchHelper::measure(function () use ($taskCount, $delayMs) {
        for ($i = 0; $i < $taskCount; $i++) {
            usleep($delayMs * 1000);
        }
    });
    BenchHelper::timing('Blocking (sequential):', $blockingMs);

    [, $asyncMs] = BenchHelper::measure(function () use ($taskCount, $delayMs) {
        RunBlocking::new(function () use ($taskCount, $delayMs) {
            for ($i = 0; $i < $taskCount; $i++) {
                Launch::new(function () use ($delayMs) {
                    Delay::new($delayMs);
                });
            }
            Thread::await();
        });
        Thread::await();
    });
    BenchHelper::timing('Async (concurrent):', $asyncMs);

    BenchHelper::comparison('50×100ms', $blockingMs, $asyncMs);
    BenchHelper::record('50×100ms delay', $blockingMs, $asyncMs, 'high concurrency');

    // ─────────────────────────────────────────────────────────────────
    // Test 7: Nested Launch — parent waits for children
    // 3 parent tasks, each spawns 3 children with 80ms delay
    // Blocking: 9 × 80ms = ~720ms  |  Async: ~160ms (two levels of parallelism)
    // ─────────────────────────────────────────────────────────────────
    BenchHelper::subHeader('Test 7: Nested Launch (3 parents × 3 children × 80ms)');

    $parentCount = 3;
    $childCount  = 3;
    $delayMs     = 80;

    [, $blockingMs] = BenchHelper::measure(function () use ($parentCount, $childCount, $delayMs) {
        for ($p = 0; $p < $parentCount; $p++) {
            for ($c = 0; $c < $childCount; $c++) {
                usleep($delayMs * 1000);
            }
        }
    });
    BenchHelper::timing('Blocking (sequential):', $blockingMs);

    [, $asyncMs] = BenchHelper::measure(function () use ($parentCount, $childCount, $delayMs) {
        RunBlocking::new(function () use ($parentCount, $childCount, $delayMs) {
            for ($p = 0; $p < $parentCount; $p++) {
                Launch::new(function () use ($childCount, $delayMs) {
                    for ($c = 0; $c < $childCount; $c++) {
                        Launch::new(function () use ($delayMs) {
                            Delay::new($delayMs);
                        });
                    }
                });
            }
            Thread::await();
        });
        Thread::await();
    });
    BenchHelper::timing('Async (nested concurrent):', $asyncMs);

    BenchHelper::comparison('Nested 3×3×80ms', $blockingMs, $asyncMs);
    BenchHelper::record('Nested 3×3×80ms', $blockingMs, $asyncMs, 'structured concurrency');

    // ─────────────────────────────────────────────────────────────────
    // Test 8: Overhead measurement — 100 tasks × 1ms (near-zero delay)
    // This tests the scheduler overhead itself when delays are tiny.
    // ─────────────────────────────────────────────────────────────────
    BenchHelper::subHeader('Test 8: Scheduler overhead — 100 tasks × 1ms');

    $taskCount = 100;
    $delayMs   = 1;

    [, $blockingMs] = BenchHelper::measure(function () use ($taskCount, $delayMs) {
        for ($i = 0; $i < $taskCount; $i++) {
            usleep($delayMs * 1000);
        }
    });
    BenchHelper::timing('Blocking (sequential):', $blockingMs);

    [, $asyncMs] = BenchHelper::measure(function () use ($taskCount, $delayMs) {
        RunBlocking::new(function () use ($taskCount, $delayMs) {
            for ($i = 0; $i < $taskCount; $i++) {
                Launch::new(function () use ($delayMs) {
                    Delay::new($delayMs);
                });
            }
            Thread::await();
        });
        Thread::await();
    });
    BenchHelper::timing('Async (concurrent):', $asyncMs);

    BenchHelper::comparison('100×1ms (overhead)', $blockingMs, $asyncMs);
    BenchHelper::record('100×1ms overhead', $blockingMs, $asyncMs, 'scheduler overhead test');

    // ─────────────────────────────────────────────────────────────────
    //  Summary
    // ─────────────────────────────────────────────────────────────────
    BenchHelper::printSummary();

    BenchHelper::info('');
    BenchHelper::info('Interpretation:');
    BenchHelper::info('  • Blocking total time = sum of all delays (sequential)');
    BenchHelper::info('  • Async total time ≈ max(delays) + scheduler overhead');
    BenchHelper::info('  • Speedup should approach N (task count) for equal-duration delays');
    BenchHelper::info('  • High overhead in Test 8 = scheduler cost per fiber context switch');
    BenchHelper::info('  • Node.js would show similar speedup ratios for delay-based tests,');
    BenchHelper::info('    but with lower absolute async times due to native libuv event loop.');
    BenchHelper::info('');
});
