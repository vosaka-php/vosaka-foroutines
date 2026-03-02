<?php

/**
 * Minimal test for the new true WorkerPool implementation.
 *
 * Tests:
 *   1. Basic IO dispatch via Async::new with Dispatchers::IO
 *   2. Multiple concurrent IO tasks
 *   3. WorkerPool returns complex data
 *   4. WorkerPool returns null correctly
 *   5. WorkerPool shutdown and cleanup
 */

require '../vendor/autoload.php';

use vosaka\foroutines\Async;
use vosaka\foroutines\Dispatchers;
use vosaka\foroutines\Launch;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Thread;
use vosaka\foroutines\WorkerPool;

use function vosaka\foroutines\main;

main(function () {

    // ================================================================
    // Test 0: Direct WorkerPool::add + WorkerPool::run (lowest level)
    // ================================================================
    echo "=== Test 0: Direct WorkerPool add + run ===\n";

    $startTime = microtime(true);

    RunBlocking::new(function () {
        $async = WorkerPool::addAsync(function () {
            return 42;
        });

        $result = $async->await();
        echo "Direct WorkerPool result: {$result}\n";
        assert($result === 42, "Expected 42, got {$result}");

        Thread::await();
    });
    Thread::await();

    $elapsed = round((microtime(true) - $startTime) * 1000);
    echo "  (completed in {$elapsed}ms)\n\n";

    // ================================================================
    // Test 1: Basic IO dispatch via Async::new(Dispatchers::IO)
    // ================================================================
    echo "=== Test 1: Basic Dispatchers::IO ===\n";

    $startTime = microtime(true);

    RunBlocking::new(function () {
        $async = Async::new(function () {
            return 999;
        }, Dispatchers::IO);

        $result = $async->await();
        echo "IO async result: {$result}\n";
        assert($result === 999, "Expected 999, got {$result}");

        Thread::await();
    });
    Thread::await();

    $elapsed = round((microtime(true) - $startTime) * 1000);
    echo "  (completed in {$elapsed}ms)\n\n";

    // ================================================================
    // Test 2: Multiple concurrent IO tasks
    // ================================================================
    echo "=== Test 2: Multiple concurrent IO tasks ===\n";

    $startTime = microtime(true);

    RunBlocking::new(function () {
        $asyncs = [];
        for ($i = 0; $i < 4; $i++) {
            $val = $i;
            $asyncs[$i] = Async::new(function () use ($val) {
                // Simulate some work
                usleep(50_000); // 50ms
                return $val * $val;
            }, Dispatchers::IO);
        }

        $results = [];
        foreach ($asyncs as $i => $async) {
            $results[$i] = $async->await();
            echo "  IO task {$i} result: {$results[$i]}\n";
        }

        assert($results[0] === 0, "Expected 0");
        assert($results[1] === 1, "Expected 1");
        assert($results[2] === 4, "Expected 4");
        assert($results[3] === 9, "Expected 9");

        Thread::await();
    });
    Thread::await();

    $elapsed = round((microtime(true) - $startTime) * 1000);
    echo "  (completed in {$elapsed}ms)\n\n";

    // ================================================================
    // Test 3: IO returns complex data (array)
    // ================================================================
    echo "=== Test 3: IO returns complex data ===\n";

    RunBlocking::new(function () {
        $async = Async::new(function () {
            return ['name' => 'vosaka', 'version' => 1, 'items' => [1, 2, 3]];
        }, Dispatchers::IO);

        $result = $async->await();
        echo "  Array keys: " . implode(', ', array_keys($result)) . "\n";
        echo "  Name: {$result['name']}\n";
        assert($result['name'] === 'vosaka');
        assert($result['version'] === 1);
        assert($result['items'] === [1, 2, 3]);

        Thread::await();
    });
    Thread::await();
    echo "\n";

    // ================================================================
    // Test 4: IO returns null correctly
    // ================================================================
    echo "=== Test 4: IO returns null correctly ===\n";

    RunBlocking::new(function () {
        $async = Async::new(function () {
            return null;
        }, Dispatchers::IO);

        $result = $async->await();
        $isNull = $result === null ? 'yes' : 'no';
        echo "  Result is null: {$isNull}\n";
        assert($result === null, "Expected null");

        Thread::await();
    });
    Thread::await();
    echo "\n";

    // ================================================================
    // Test 5: IO with Launch
    // ================================================================
    echo "=== Test 5: IO with Launch ===\n";

    RunBlocking::new(function () {
        Launch::new(function () {
            // This runs in a child process
            return 'launched';
        }, Dispatchers::IO);

        Thread::await();
        echo "  IO Launch completed\n";
    });
    Thread::await();
    echo "\n";

    // ================================================================
    // Test 6: Second batch reuses existing workers (pool stays alive)
    // ================================================================
    echo "=== Test 6: Worker reuse (second batch) ===\n";

    $startTime = microtime(true);

    RunBlocking::new(function () {
        $async1 = Async::new(function () {
            return 'batch2_a';
        }, Dispatchers::IO);

        $async2 = Async::new(function () {
            return 'batch2_b';
        }, Dispatchers::IO);

        $r1 = $async1->await();
        $r2 = $async2->await();
        echo "  Result 1: {$r1}\n";
        echo "  Result 2: {$r2}\n";
        assert($r1 === 'batch2_a');
        assert($r2 === 'batch2_b');

        Thread::await();
    });
    Thread::await();

    $elapsed = round((microtime(true) - $startTime) * 1000);
    echo "  (completed in {$elapsed}ms - should be faster due to reuse)\n\n";

    // ================================================================
    // Test 7: File I/O from worker process
    // ================================================================
    echo "=== Test 7: File I/O from worker ===\n";

    $testFile = __DIR__ . DIRECTORY_SEPARATOR . 'worker_pool_test.tmp';
    if (file_exists($testFile)) {
        unlink($testFile);
    }

    RunBlocking::new(function () use ($testFile) {
        $async = Async::new(function () use ($testFile) {
            file_put_contents($testFile, 'hello_from_worker');
            return true;
        }, Dispatchers::IO);

        $result = $async->await();
        assert($result === true);

        Thread::await();
    });
    Thread::await();

    if (file_exists($testFile)) {
        $content = file_get_contents($testFile);
        echo "  File content: {$content}\n";
        assert($content === 'hello_from_worker');
        unlink($testFile);
    } else {
        echo "  ERROR: File was not created\n";
    }
    echo "\n";

    // ================================================================
    // Shutdown
    // ================================================================
    echo "=== Shutting down WorkerPool ===\n";
    WorkerPool::shutdown();
    echo "  Pool shut down cleanly.\n\n";

    echo "All WorkerPool tests passed!\n";
});
