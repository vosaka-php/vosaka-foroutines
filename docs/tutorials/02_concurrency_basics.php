<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use function vosaka\foroutines\main;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Launch;
use vosaka\foroutines\Async;
use vosaka\foroutines\Delay;

/**
 * Tutorial 02: Concurrency Basics (Launch vs Async)
 * 
 * - Launch: Fire-and-forget. It returns a Job object, but you don't wait for a result.
 * - Async: Returns a result. You use await() to get the return value.
 */

main(function () {
    RunBlocking::new(function () {
        echo "--- Launch Example ---\n";
        
        Launch::new(function () {
            Delay::new(500);
            echo "Task A (Launch) finished\n";
        });

        echo "Task A was launched, moving on immediately...\n";

        echo "\n--- Async Example ---\n";
        
        $deferred = Async::new(function () {
            Delay::new(1000);
            return "Result from Task B";
        });

        echo "Task B is running, now we await it...\n";
        $result = $deferred->await();
        echo "Task B finished: " . $result . "\n";
    });
});
