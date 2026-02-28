<?php

/**
 * Test: Delay::new() precision and behavior inside/outside fibers
 *
 * Demonstrates the Delay utility which pauses execution for a given number
 * of milliseconds, working both inside and outside of fiber contexts:
 *
 *   - Delay::new(ms) inside a fiber cooperatively yields via Pause::new()
 *   - Delay::new(ms) outside a fiber drives the scheduler manually
 *   - Multiple concurrent delays interleave correctly (shortest finishes first)
 *   - Delay precision is reasonable (within tolerance)
 *   - Delay works inside Async, Launch, RunBlocking, and WithTimeout contexts
 *
 * Expected output:
 *   "=== Test 1: Delay outside fiber ==="
 *   "Delay 200ms completed"
 *   "Elapsed is reasonable: yes"
 *   "=== Test 2: Delay inside fiber via RunBlocking ==="
 *   "Inside fiber delay done"
 *   "Elapsed is reasonable: yes"
 *   "=== Test 3: Delay inside Launch ==="
 *   "Short delay done"
 *   "Long delay done"
 *   "Short finished before long: yes"
 *   "=== Test 4: Delay inside Async ==="
 *   "Async delay returned: 77"
 *   "=== Test 5: Zero-ish delay completes immediately ==="
 *   "Tiny delay done"
 *   "Elapsed under 200ms: yes"
 *   "=== Test 6: Multiple concurrent delays finish in parallel ==="
 *   "All 4 delays done"
 *   "Total elapsed under 1500ms: yes"
 *   "=== Test 7: Delay inside WithTimeout completes in time ==="
 *   "WithTimeout delay result: ok"
 *   "=== Test 8: Delay accuracy for several durations ==="
 *   "100ms delay - elapsed reasonable: yes"
 *   "300ms delay - elapsed reasonable: yes"
 *   "500ms delay - elapsed reasonable: yes"
 *   "=== Test 9: Delay does not block other Launch tasks ==="
 *   "Quick task finished"
 *   "Slow task finished"
 *   "Quick ran before slow: yes"
 *   "All Delay tests passed"
 */

require '../vendor/autoload.php';

use vosaka\foroutines\Async;
use vosaka\foroutines\Delay;
use vosaka\foroutines\Dispatchers;
use vosaka\foroutines\Launch;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Thread;
use vosaka\foroutines\WithTimeout;

use function vosaka\foroutines\main;

main(function () {
    // ==========================================================
    // Test 1: Delay outside fiber context
    // ==========================================================
    var_dump('=== Test 1: Delay outside fiber ===');
    $start = microtime(true);
    Delay::new(200);
    $elapsed = (microtime(true) - $start) * 1000;
    var_dump('Delay 200ms completed');
    var_dump('Elapsed is reasonable: ' . ($elapsed >= 150 && $elapsed < 1000 ? 'yes' : 'no (' . round($elapsed) . 'ms)'));

    // ==========================================================
    // Test 2: Delay inside fiber via RunBlocking
    // ==========================================================
    var_dump('=== Test 2: Delay inside fiber via RunBlocking ===');
    $start = microtime(true);
    RunBlocking::new(function () {
        Delay::new(300);
        var_dump('Inside fiber delay done');
    });
    Thread::wait();
    $elapsed = (microtime(true) - $start) * 1000;
    var_dump('Elapsed is reasonable: ' . ($elapsed >= 250 && $elapsed < 1500 ? 'yes' : 'no (' . round($elapsed) . 'ms)'));

    // ==========================================================
    // Test 3: Delay inside Launch - shorter delay finishes first
    // ==========================================================
    var_dump('=== Test 3: Delay inside Launch ===');
    $order = [];
    RunBlocking::new(function () use (&$order) {
        Launch::new(function () use (&$order) {
            Delay::new(500);
            $order[] = 'long';
            var_dump('Long delay done');
        });

        Launch::new(function () use (&$order) {
            Delay::new(100);
            $order[] = 'short';
            var_dump('Short delay done');
        });

        Thread::wait();
    });
    Thread::wait();
    $shortFirst = (count($order) >= 2 && $order[0] === 'short');
    var_dump('Short finished before long: ' . ($shortFirst ? 'yes' : 'no'));

    // ==========================================================
    // Test 4: Delay inside Async with return value
    // ==========================================================
    var_dump('=== Test 4: Delay inside Async ===');
    RunBlocking::new(function () {
        $async = Async::new(function () {
            Delay::new(150);
            return 77;
        });

        $result = $async->wait();
        var_dump("Async delay returned: $result");

        Thread::wait();
    });
    Thread::wait();

    // ==========================================================
    // Test 5: Very small delay completes quickly
    // ==========================================================
    var_dump('=== Test 5: Zero-ish delay completes immediately ===');
    $start = microtime(true);
    RunBlocking::new(function () {
        Delay::new(1);
        var_dump('Tiny delay done');
    });
    Thread::wait();
    $elapsed = (microtime(true) - $start) * 1000;
    var_dump('Elapsed under 200ms: ' . ($elapsed < 200 ? 'yes' : 'no (' . round($elapsed) . 'ms)'));

    // ==========================================================
    // Test 6: Multiple concurrent delays finish in parallel
    // Total should be close to the longest (800ms), not sum (800+600+400+200=2000ms)
    // ==========================================================
    var_dump('=== Test 6: Multiple concurrent delays finish in parallel ===');
    $start = microtime(true);
    RunBlocking::new(function () {
        Launch::new(function () {
            Delay::new(800);
        });
        Launch::new(function () {
            Delay::new(600);
        });
        Launch::new(function () {
            Delay::new(400);
        });
        Launch::new(function () {
            Delay::new(200);
        });

        Thread::wait();
    });
    Thread::wait();
    $elapsed = (microtime(true) - $start) * 1000;
    var_dump('All 4 delays done');
    var_dump('Total elapsed under 1500ms: ' . ($elapsed < 1500 ? 'yes' : 'no (' . round($elapsed) . 'ms)'));

    // ==========================================================
    // Test 7: Delay inside WithTimeout completes in time
    // ==========================================================
    var_dump('=== Test 7: Delay inside WithTimeout completes in time ===');
    $result = WithTimeout::new(2000, function () {
        Delay::new(200);
        return 'ok';
    });
    var_dump("WithTimeout delay result: $result");

    // ==========================================================
    // Test 8: Delay accuracy for several durations
    // ==========================================================
    var_dump('=== Test 8: Delay accuracy for several durations ===');
    $durations = [100, 300, 500];
    foreach ($durations as $ms) {
        $start = microtime(true);
        RunBlocking::new(function () use ($ms) {
            Delay::new($ms);
        });
        Thread::wait();
        $elapsed = (microtime(true) - $start) * 1000;
        // Allow generous tolerance: at least 60% of target, no more than target + 1000ms
        $lower = $ms * 0.6;
        $upper = $ms + 1000;
        $reasonable = ($elapsed >= $lower && $elapsed <= $upper);
        var_dump("{$ms}ms delay - elapsed reasonable: " . ($reasonable ? 'yes' : 'no (' . round($elapsed) . 'ms)'));
    }

    // ==========================================================
    // Test 9: Delay does not block other Launch tasks
    // A quick task and a slow task launched together - quick should finish first
    // ==========================================================
    var_dump('=== Test 9: Delay does not block other Launch tasks ===');
    $finishOrder = [];
    RunBlocking::new(function () use (&$finishOrder) {
        Launch::new(function () use (&$finishOrder) {
            Delay::new(600);
            $finishOrder[] = 'slow';
            var_dump('Slow task finished');
        });

        Launch::new(function () use (&$finishOrder) {
            Delay::new(100);
            $finishOrder[] = 'quick';
            var_dump('Quick task finished');
        });

        Thread::wait();
    });
    Thread::wait();
    $quickFirst = (count($finishOrder) >= 2 && $finishOrder[0] === 'quick');
    var_dump('Quick ran before slow: ' . ($quickFirst ? 'yes' : 'no'));

    var_dump('All Delay tests passed');
});
