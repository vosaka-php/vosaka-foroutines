<?php

/**
 * Test: WithTimeout::new() and WithTimeoutOrNull::new()
 *
 * Demonstrates timeout control for foroutine execution:
 *   - WithTimeout throws RuntimeException when the callable exceeds the timeout
 *   - WithTimeout returns the result when the callable completes within the timeout
 *   - WithTimeoutOrNull returns null instead of throwing on timeout
 *   - WithTimeoutOrNull returns the result when the callable completes in time
 *   - WithTimeout throws immediately when given timeout <= 0
 *
 * Expected output:
 *   "=== Test 1: WithTimeout completes in time ==="
 *   "Result: 100"
 *   "=== Test 2: WithTimeout exceeds timeout ==="
 *   "Caught timeout: Timed out waiting for 200 ms"
 *   "=== Test 3: WithTimeoutOrNull completes in time ==="
 *   "Result: success"
 *   "=== Test 4: WithTimeoutOrNull exceeds timeout ==="
 *   "Result is null as expected"
 *   "=== Test 5: WithTimeout with zero timeout ==="
 *   "Caught immediate timeout: Timed out immediately waiting for 0 ms"
 *   "=== Test 6: WithTimeout with negative timeout ==="
 *   "Caught immediate timeout: Timed out immediately waiting for -100 ms"
 *   "=== Test 7: WithTimeout doing real work ==="
 *   "Computed sum: 5050"
 *   "=== Test 8: WithTimeoutOrNull chaining fallback ==="
 *   "Final value: default_fallback"
 *   "All WithTimeout tests passed"
 */

require '../vendor/autoload.php';

use vosaka\foroutines\Delay;
use vosaka\foroutines\WithTimeout;
use vosaka\foroutines\WithTimeoutOrNull;

use function vosaka\foroutines\main;

main(function () {
    // Test 1: Callable completes well within the timeout
    var_dump('=== Test 1: WithTimeout completes in time ===');
    $result = WithTimeout::new(2000, function () {
        Delay::new(100);
        return 100;
    });
    var_dump("Result: $result");

    // Test 2: Callable exceeds the timeout and RuntimeException is thrown
    var_dump('=== Test 2: WithTimeout exceeds timeout ===');
    try {
        WithTimeout::new(200, function () {
            Delay::new(5000);
            return 'should not reach here';
        });
    } catch (RuntimeException $e) {
        var_dump('Caught timeout: ' . $e->getMessage());
    }

    // Test 3: WithTimeoutOrNull completes in time - returns the value
    var_dump('=== Test 3: WithTimeoutOrNull completes in time ===');
    $result = WithTimeoutOrNull::new(2000, function () {
        Delay::new(100);
        return 'success';
    });
    var_dump("Result: $result");

    // Test 4: WithTimeoutOrNull exceeds timeout - returns null instead of throwing
    var_dump('=== Test 4: WithTimeoutOrNull exceeds timeout ===');
    $result = WithTimeoutOrNull::new(200, function () {
        Delay::new(5000);
        return 'should not reach here';
    });
    if ($result === null) {
        var_dump('Result is null as expected');
    } else {
        var_dump("Unexpected result: $result");
    }

    // Test 5: Zero timeout should throw immediately
    var_dump('=== Test 5: WithTimeout with zero timeout ===');
    try {
        WithTimeout::new(0, function () {
            return 'should not run';
        });
    } catch (RuntimeException $e) {
        var_dump('Caught immediate timeout: ' . $e->getMessage());
    }

    // Test 6: Negative timeout should throw immediately
    var_dump('=== Test 6: WithTimeout with negative timeout ===');
    try {
        WithTimeout::new(-100, function () {
            return 'should not run';
        });
    } catch (RuntimeException $e) {
        var_dump('Caught immediate timeout: ' . $e->getMessage());
    }

    // Test 7: WithTimeout with real computation that finishes in time
    var_dump('=== Test 7: WithTimeout doing real work ===');
    $sum = WithTimeout::new(3000, function () {
        $s = 0;
        for ($i = 1; $i <= 100; $i++) {
            $s += $i;
        }
        return $s;
    });
    var_dump("Computed sum: $sum");

    // Test 8: WithTimeoutOrNull as a fallback pattern
    // If the fast path times out, use a default value
    var_dump('=== Test 8: WithTimeoutOrNull chaining fallback ===');
    $value = WithTimeoutOrNull::new(100, function () {
        Delay::new(5000);
        return 'slow_value';
    });
    $finalValue = $value ?? 'default_fallback';
    var_dump("Final value: $finalValue");

    var_dump('All WithTimeout tests passed');
});
