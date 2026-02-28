<?php

/**
 * Test: Launch with multiple concurrent delays (DEFAULT dispatcher)
 *
 * Demonstrates that multiple Launch jobs run concurrently using fibers.
 * Each job uses Delay::new() to simulate async waiting, and they should
 * interleave execution rather than running sequentially.
 *
 * Expected output order:
 *   "Start" (immediate)
 *   "Fast done" (~500ms)
 *   "Medium done" (~1000ms)
 *   "Slow done" (~1500ms)
 *   "All launches completed"
 */

require '../vendor/autoload.php';

use vosaka\foroutines\Delay;
use vosaka\foroutines\Launch;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Thread;

use function vosaka\foroutines\main;

main(function () {
    $time = microtime(true);

    RunBlocking::new(function () {
        var_dump('Start');

        Launch::new(function () {
            Delay::new(1500);
            var_dump('Slow done');
        });

        Launch::new(function () {
            Delay::new(500);
            var_dump('Fast done');
        });

        Launch::new(function () {
            Delay::new(1000);
            var_dump('Medium done');
        });

        Thread::wait();
    });

    Thread::wait();

    $elapsed = round(microtime(true) - $time, 2);
    var_dump('All launches completed');
    var_dump("Elapsed: ~{$elapsed}s");
});
