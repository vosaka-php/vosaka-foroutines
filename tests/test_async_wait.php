<?php

/**
 * Test: Async::new() + wait() to retrieve return values
 *
 * Demonstrates creating async tasks that compute values and using
 * wait() to block until the result is available. Shows that multiple
 * Async tasks run concurrently and their results can be collected.
 *
 * Expected output:
 *   "Starting async tasks..."
 *   "Result A: 42"
 *   "Result B: hello world"
 *   "Result C: 300"
 *   "Sum of A + C: 342"
 *   "All async tasks completed"
 */

require '../vendor/autoload.php';

use vosaka\foroutines\Async;
use vosaka\foroutines\Delay;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Thread;

use function vosaka\foroutines\main;

main(function () {
    RunBlocking::new(function () {
        var_dump('Starting async tasks...');

        // Async that returns an integer after a short delay
        $asyncA = Async::new(function () {
            Delay::new(500);
            return 42;
        });

        // Async that returns a string after a longer delay
        $asyncB = Async::new(function () {
            Delay::new(800);
            return 'hello world';
        });

        // Async that does a computation
        $asyncC = Async::new(function () {
            Delay::new(300);
            $sum = 0;
            for ($i = 1; $i <= 24; $i++) {
                $sum += $i;
            }
            return $sum; // 300
        });

        // Wait for all results - they run concurrently so total time
        // should be ~800ms (the longest), not 500+800+300=1600ms
        $resultA = $asyncA->wait();
        var_dump("Result A: $resultA");

        $resultB = $asyncB->wait();
        var_dump("Result B: $resultB");

        $resultC = $asyncC->wait();
        var_dump("Result C: $resultC");

        var_dump('Sum of A + C: ' . ($resultA + $resultC));

        Thread::wait();
    });

    Thread::wait();

    var_dump('All async tasks completed');
});
