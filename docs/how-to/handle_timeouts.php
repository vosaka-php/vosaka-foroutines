<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use function vosaka\foroutines\main;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\WithTimeout;
use vosaka\foroutines\WithTimeoutOrNull;
use vosaka\foroutines\Delay;

/**
 * How-to: Handling Timeouts
 * 
 * Learn how to set limits on how long a task can run.
 */

main(function () {
    RunBlocking::new(function () {
        
        echo "--- WithTimeout (throws exception) ---\n";
        try {
            WithTimeout::new(1000, function () {
                echo "Task starts (will take 2s)...\n";
                Delay::new(2000);
                return "Completed";
            });
        } catch (\RuntimeException $e) {
            echo "Caught expected timeout: " . $e->getMessage() . "\n";
        }

        echo "\n--- WithTimeoutOrNull (returns null) ---\n";
        $result = WithTimeoutOrNull::new(1000, function () {
            echo "Task starts (will take 2s)...\n";
            Delay::new(2000);
            return "Completed";
        });

        if ($result === null) {
            echo "Task timed out as expected.\n";
        }
    });
});
