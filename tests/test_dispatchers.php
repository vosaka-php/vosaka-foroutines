<?php

/**
 * Test: Dispatchers - DEFAULT, IO, and MAIN
 *
 * Demonstrates the three dispatcher modes available in the library:
 *
 *   Dispatchers::DEFAULT
 *     - Runs the task in a fiber within the current process
 *     - Best for CPU-light, cooperative async work
 *     - Tasks interleave via Pause/Delay yielding
 *
 *   Dispatchers::IO
 *     - Runs the task in a separate child process (via WorkerPool/Process)
 *     - Best for blocking I/O operations (file writes, network, sleep)
 *     - Output from the child process is captured and printed in the parent
 *     - Return values are serialized back to the parent
 *
 *   Dispatchers::MAIN
 *     - Schedules the task on the main EventLoop (SplPriorityQueue)
 *     - Tasks run during the shutdown phase or when EventLoop::runNext is called
 *     - Useful for deferred execution on the main thread
 *
 * Expected output (order may vary slightly for IO due to process scheduling):
 *   "=== Test 1: Dispatchers::DEFAULT with Launch ==="
 *   "DEFAULT task A started"
 *   "DEFAULT task B started"
 *   "DEFAULT task B done"
 *   "DEFAULT task A done"
 *   "=== Test 2: Dispatchers::DEFAULT with Async returns value ==="
 *   "Async DEFAULT result: 256"
 *   "=== Test 3: Dispatchers::IO with Launch ==="
 *   "IO task says hello"
 *   "IO launch completed"
 *   "=== Test 4: Dispatchers::IO with Async returns value ==="
 *   "IO async result: 999"
 *   "=== Test 5: Dispatchers::IO file write from child process ==="
 *   "File written by IO task"
 *   "File content: dispatchers_io_test_data"
 *   "=== Test 6: Dispatchers::DEFAULT multiple concurrent tasks ==="
 *   "Counter after 5 concurrent increments: 5"
 *   "=== Test 7: Dispatchers::IO with RunBlocking ==="
 *   "RunBlocking IO: task ran"
 *   "RunBlocking IO completed"
 *   "=== Test 8: Dispatchers::DEFAULT with RunBlocking ==="
 *   "RunBlocking DEFAULT: task ran"
 *   "=== Test 9: Mixed dispatchers in single RunBlocking ==="
 *   "Mixed: DEFAULT task done"
 *   "Mixed: all done"
 *   "=== Test 10: Dispatchers::IO returns complex data ==="
 *   "IO returned array count: 5"
 *   "IO returned array sum: 15"
 *   "=== Test 11: Dispatchers::DEFAULT Async with Delay ==="
 *   "DEFAULT async with delay: 42"
 *   "=== Test 12: Dispatchers::IO returns null correctly ==="
 *   "IO null result is null: yes"
 *   "All Dispatcher tests passed"
 */

require '../vendor/autoload.php';

use vosaka\foroutines\Async;
use vosaka\foroutines\Delay;
use vosaka\foroutines\Dispatchers;
use vosaka\foroutines\Launch;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Thread;

use function vosaka\foroutines\main;

main(function () {
    // ================================================================
    // Test 1: Dispatchers::DEFAULT with Launch - fiber-based concurrency
    // ================================================================
    var_dump('=== Test 1: Dispatchers::DEFAULT with Launch ===');
    RunBlocking::new(function () {
        Launch::new(function () {
            var_dump('DEFAULT task A started');
            Delay::new(500);
            var_dump('DEFAULT task A done');
        }, Dispatchers::DEFAULT);

        Launch::new(function () {
            var_dump('DEFAULT task B started');
            Delay::new(200);
            var_dump('DEFAULT task B done');
        }, Dispatchers::DEFAULT);

        Thread::wait();
    });
    Thread::wait();

    // ================================================================
    // Test 2: Dispatchers::DEFAULT with Async - returns a computed value
    // ================================================================
    var_dump('=== Test 2: Dispatchers::DEFAULT with Async returns value ===');
    RunBlocking::new(function () {
        $async = Async::new(function () {
            $val = 16 * 16;
            return $val;
        }, Dispatchers::DEFAULT);

        $result = $async->wait();
        var_dump("Async DEFAULT result: $result");

        Thread::wait();
    });
    Thread::wait();

    // ================================================================
    // Test 3: Dispatchers::IO with Launch - runs in child process
    // ================================================================
    var_dump('=== Test 3: Dispatchers::IO with Launch ===');
    RunBlocking::new(function () {
        Launch::new(function () {
            echo "IO task says hello\n";
        }, Dispatchers::IO);

        Thread::wait();
        var_dump('IO launch completed');
    });
    Thread::wait();

    // ================================================================
    // Test 4: Dispatchers::IO with Async - returns value from child process
    // ================================================================
    var_dump('=== Test 4: Dispatchers::IO with Async returns value ===');
    RunBlocking::new(function () {
        $async = Async::new(function () {
            return 999;
        }, Dispatchers::IO);

        $result = $async->wait();
        var_dump("IO async result: $result");

        Thread::wait();
    });
    Thread::wait();

    // ================================================================
    // Test 5: Dispatchers::IO performing file I/O in child process
    // ================================================================
    var_dump('=== Test 5: Dispatchers::IO file write from child process ===');
    $testFile = __DIR__ . DIRECTORY_SEPARATOR . 'dispatchers_io_test.tmp';

    // Clean up any leftover file
    if (file_exists($testFile)) {
        unlink($testFile);
    }

    RunBlocking::new(function () use ($testFile) {
        $async = Async::new(function () use ($testFile) {
            file_put_contents($testFile, 'dispatchers_io_test_data');
            return true;
        }, Dispatchers::IO);

        $async->wait();
        var_dump('File written by IO task');

        Thread::wait();
    });
    Thread::wait();

    if (file_exists($testFile)) {
        $content = file_get_contents($testFile);
        var_dump("File content: $content");
        unlink($testFile);
    } else {
        var_dump('ERROR: File was not created by IO task');
    }

    // ================================================================
    // Test 6: Dispatchers::DEFAULT with multiple concurrent tasks
    // ================================================================
    var_dump('=== Test 6: Dispatchers::DEFAULT multiple concurrent tasks ===');
    RunBlocking::new(function () {
        $counter = 0;

        // Launch 5 tasks that each increment the counter
        for ($i = 0; $i < 5; $i++) {
            Launch::new(function () use (&$counter) {
                Delay::new(50);
                $counter++;
            }, Dispatchers::DEFAULT);
        }

        Thread::wait();
        var_dump("Counter after 5 concurrent increments: $counter");
    });
    Thread::wait();

    // ================================================================
    // Test 7: Dispatchers::IO with RunBlocking
    // ================================================================
    var_dump('=== Test 7: Dispatchers::IO with RunBlocking ===');
    RunBlocking::new(function () {
        echo "RunBlocking IO: task ran\n";
    }, Dispatchers::IO);
    Thread::wait();
    var_dump('RunBlocking IO completed');

    // ================================================================
    // Test 8: Dispatchers::DEFAULT with RunBlocking (standard fiber)
    // ================================================================
    var_dump('=== Test 8: Dispatchers::DEFAULT with RunBlocking ===');
    RunBlocking::new(function () {
        var_dump('RunBlocking DEFAULT: task ran');
    }, Dispatchers::DEFAULT);
    Thread::wait();

    // ================================================================
    // Test 9: Mixed dispatchers in a single RunBlocking block
    // ================================================================
    var_dump('=== Test 9: Mixed dispatchers in single RunBlocking ===');
    RunBlocking::new(function () {
        // DEFAULT dispatcher task
        Launch::new(function () {
            Delay::new(100);
            var_dump('Mixed: DEFAULT task done');
        }, Dispatchers::DEFAULT);

        Thread::wait();
        var_dump('Mixed: all done');
    });
    Thread::wait();

    // ================================================================
    // Test 10: Dispatchers::IO returning complex data (array)
    // ================================================================
    var_dump('=== Test 10: Dispatchers::IO returns complex data ===');
    RunBlocking::new(function () {
        $async = Async::new(function () {
            $data = [];
            for ($i = 1; $i <= 5; $i++) {
                $data[] = $i;
            }
            return $data;
        }, Dispatchers::IO);

        $result = $async->wait();
        var_dump('IO returned array count: ' . count($result));
        var_dump('IO returned array sum: ' . array_sum($result));

        Thread::wait();
    });
    Thread::wait();

    // ================================================================
    // Test 11: Dispatchers::DEFAULT Async with Delay inside
    // ================================================================
    var_dump('=== Test 11: Dispatchers::DEFAULT Async with Delay ===');
    RunBlocking::new(function () {
        $async = Async::new(function () {
            Delay::new(200);
            return 42;
        }, Dispatchers::DEFAULT);

        $result = $async->wait();
        var_dump("DEFAULT async with delay: $result");

        Thread::wait();
    });
    Thread::wait();

    // ================================================================
    // Test 12: Dispatchers::IO returns null correctly
    // (Regression test: isset() vs array_key_exists() for null returns)
    // ================================================================
    var_dump('=== Test 12: Dispatchers::IO returns null correctly ===');
    RunBlocking::new(function () {
        $async = Async::new(function () {
            return null;
        }, Dispatchers::IO);

        $result = $async->wait();
        var_dump('IO null result is null: ' . ($result === null ? 'yes' : 'no'));

        Thread::wait();
    });
    Thread::wait();

    var_dump('All Dispatcher tests passed');
});
