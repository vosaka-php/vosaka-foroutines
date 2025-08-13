<?php

use vosaka\foroutines\Launch;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Dispatchers;
use function vosaka\foroutines\main;

require '../vendor/autoload.php';

echo "=== FIBER DEBUG TEST ===" . PHP_EOL;

main(function () {
    RunBlocking::new(function () {
        echo "Main: RunBlocking started" . PHP_EOL;

        $test1Complete = false;
        $test2Complete = false;
        $test3Complete = false;

        // Test 1: Default dispatcher
        echo "Test 1: Launching fiber with DEFAULT dispatcher..." . PHP_EOL;
        Launch::new(function () use (&$test1Complete) {
            echo "Fiber 1: Hello from DEFAULT dispatcher!" . PHP_EOL;
            $test1Complete = true;
        }); // No dispatcher = default

        // Test 2: IO dispatcher
        echo "Test 2: Launching fiber with IO dispatcher..." . PHP_EOL;
        Launch::new(function () use (&$test2Complete) {
            echo "Fiber 2: Hello from IO dispatcher!" . PHP_EOL;
            $test2Complete = true;
        }, Dispatchers::IO);

        // Test 3: Default dispatcher with operations
        echo "Test 3: Launching fiber with operations..." . PHP_EOL;
        Launch::new(function () use (&$test3Complete) {
            echo "Fiber 3: Starting operations..." . PHP_EOL;

            // Simple operations
            for ($i = 1; $i <= 3; $i++) {
                echo "Fiber 3: Operation $i" . PHP_EOL;
                usleep(10000); // 10ms
            }

            echo "Fiber 3: Operations completed!" . PHP_EOL;
            $test3Complete = true;
        });

        echo "All fibers launched, waiting..." . PHP_EOL;

        // Wait and check
        for ($i = 1; $i <= 5; $i++) {
            sleep(1);
            echo "Check $i: Test1=" . ($test1Complete ? "✓" : "⏳") .
                " Test2=" . ($test2Complete ? "✓" : "⏳") .
                " Test3=" . ($test3Complete ? "✓" : "⏳") . PHP_EOL;

            if ($test1Complete && $test2Complete && $test3Complete) {
                echo "✓ All tests completed!" . PHP_EOL;
                break;
            }
        }

        if (!$test1Complete || !$test2Complete || !$test3Complete) {
            echo "⚠️ Some fibers did not complete!" . PHP_EOL;
            echo "This indicates a problem with the fiber framework on Windows" . PHP_EOL;
        }

        echo "Main: RunBlocking finished" . PHP_EOL;
    });
});

echo "=== FIBER DEBUG COMPLETED ===" . PHP_EOL;
