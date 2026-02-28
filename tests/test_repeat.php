<?php

/**
 * Test: Repeat::new() with various counts and error handling
 *
 * Demonstrates the Repeat utility which executes a callable a specified
 * number of times. Also tests that:
 *   - The callable receives the correct number of invocations
 *   - Side effects accumulate properly across iterations
 *   - InvalidArgumentException is thrown for count <= 0
 *
 * Expected output:
 *   "=== Test 1: Basic repeat ==="
 *   "Iteration 1"
 *   "Iteration 2"
 *   "Iteration 3"
 *   "=== Test 2: Accumulation ==="
 *   "Final counter: 10"
 *   "=== Test 3: Repeat with array building ==="
 *   "Built array: [0, 1, 4, 9, 16]"
 *   "=== Test 4: Single iteration ==="
 *   "Executed once"
 *   "=== Test 5: Error on zero count ==="
 *   "Caught expected error: Count must be greater than zero."
 *   "=== Test 6: Error on negative count ==="
 *   "Caught expected error: Count must be greater than zero."
 *   "All Repeat tests passed"
 */

require '../vendor/autoload.php';

use vosaka\foroutines\Repeat;

use function vosaka\foroutines\main;

main(function () {
    // Test 1: Basic repeat - prints iteration numbers
    var_dump('=== Test 1: Basic repeat ===');
    $iteration = 0;
    Repeat::new(3, function () use (&$iteration) {
        $iteration++;
        var_dump("Iteration $iteration");
    });

    // Test 2: Accumulation across iterations
    var_dump('=== Test 2: Accumulation ===');
    $counter = 0;
    Repeat::new(10, function () use (&$counter) {
        $counter++;
    });
    var_dump("Final counter: $counter");

    // Test 3: Building an array with repeated calls
    var_dump('=== Test 3: Repeat with array building ===');
    $squares = [];
    $index = 0;
    Repeat::new(5, function () use (&$squares, &$index) {
        $squares[] = $index * $index;
        $index++;
    });
    var_dump('Built array: [' . implode(', ', $squares) . ']');

    // Test 4: Single iteration
    var_dump('=== Test 4: Single iteration ===');
    Repeat::new(1, function () {
        var_dump('Executed once');
    });

    // Test 5: Error handling - zero count should throw
    var_dump('=== Test 5: Error on zero count ===');
    try {
        Repeat::new(0, function () {
            var_dump('This should not run');
        });
    } catch (InvalidArgumentException $e) {
        var_dump('Caught expected error: ' . $e->getMessage());
    }

    // Test 6: Error handling - negative count should throw
    var_dump('=== Test 6: Error on negative count ===');
    try {
        Repeat::new(-5, function () {
            var_dump('This should not run');
        });
    } catch (InvalidArgumentException $e) {
        var_dump('Caught expected error: ' . $e->getMessage());
    }

    var_dump('All Repeat tests passed');
});
