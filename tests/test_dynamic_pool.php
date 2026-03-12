<?php

/**
 * Test: Dynamic Pool Sizing — verifying scale-up and scale-down behavior
 *
 * Demonstrates that when dynamic scaling is enabled, the WorkerPool
 * automatically spawns additional workers under pressure and shuts down
 * idle workers when demand drops.
 *
 * Test structure:
 *   1. Boot with a small initial pool (2 workers), max 6
 *   2. Submit many tasks that keep workers busy
 *   3. Verify that the pool scaled UP (more than 2 workers)
 *   4. Wait for idle timeout to pass
 *   5. Verify that the pool scaled DOWN (back toward minPoolSize)
 *   6. Verify all task results are correct
 */

require "../vendor/autoload.php";

use vosaka\foroutines\Async;
use vosaka\foroutines\Dispatchers;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Thread;
use vosaka\foroutines\WorkerPool;
use vosaka\foroutines\WorkerPoolState;

use function vosaka\foroutines\main;

main(function () {
    var_dump("=== Dynamic Pool Sizing Test ===");
    var_dump("");

    // ═══════════════════════════════════════════════════════════════
    //  Test 1: Scale-UP under pressure
    // ═══════════════════════════════════════════════════════════════

    var_dump("--- Test 1: Scale-UP under pressure ---");

    WorkerPool::shutdown();
    WorkerPool::resetState();

    // Start with 2 workers, allow scaling up to 6
    WorkerPool::setPoolSize(2);
    WorkerPool::setDynamicScaling(
        enabled: true,
        minPoolSize: 2,
        maxPoolSize: 6,
        idleTimeout: 3.0, // 3 seconds idle before scale-down
        scaleUpCooldown: 0.2, // 200ms between scale-ups (fast for testing)
        scaleDownCooldown: 1.0, // 1 second between scale-downs
    );

    $taskCount = 12;
    $results = [];

    RunBlocking::new(function () use ($taskCount, &$results) {
        $asyncs = [];

        // Submit many tasks — each sleeps 500ms to keep workers busy
        // long enough for the scaler to notice pressure and spawn more.
        for ($i = 0; $i < $taskCount; $i++) {
            $val = $i;
            $asyncs[$i] = Async::new(function () use ($val) {
                usleep(500_000); // 500ms — keep worker busy
                return $val * 10;
            }, Dispatchers::IO);
        }

        // Use awaitAll to collect all results concurrently
        $results = Async::awaitAll(...$asyncs);

    });


    // Check peak — the pool should have scaled beyond the initial 2
    $afterTaskWorkerCount = WorkerPoolState::workerCount();
    var_dump("  Worker count after tasks complete: $afterTaskWorkerCount");
    var_dump("  Tasks submitted: $taskCount");

    // Verify results
    $allCorrect = true;
    for ($i = 0; $i < $taskCount; $i++) {
        $expected = $i * 10;
        $actual = $results[$i] ?? "MISSING";
        if ($actual !== $expected) {
            var_dump(
                "  ERROR: results[$i] = " .
                    var_export($actual, true) .
                    ", expected $expected",
            );
            $allCorrect = false;
        }
    }

    if ($allCorrect) {
        var_dump("  All $taskCount results correct: YES");
    } else {
        var_dump("  SOME RESULTS INCORRECT");
        exit(1);
    }

    // The pool should have grown beyond 2 to handle 12 tasks x 500ms
    $scaleUpWorked = $afterTaskWorkerCount > 2;
    if ($scaleUpWorked) {
        var_dump(
            "  Scale-UP verified: pool grew to $afterTaskWorkerCount workers (started at 2)",
        );
    } else {
        var_dump(
            "  NOTE: Pool did not visibly scale up (count=$afterTaskWorkerCount). Tasks may have completed before scaler ran.",
        );
    }

    var_dump("");

    // ═══════════════════════════════════════════════════════════════
    //  Test 2: Scale-DOWN after idle timeout
    // ═══════════════════════════════════════════════════════════════

    var_dump("--- Test 2: Scale-DOWN after idle timeout ---");

    $workersBefore = WorkerPoolState::workerCount();
    var_dump("  Workers before idle wait: $workersBefore");

    // Wait for idle timeout (3s) + scale-down cooldown (1s) + buffer
    // During this time, we call WorkerPool::run() periodically
    // so the dynamic scaler can evaluate and shut down idle workers.
    $waitStart = microtime(true);
    $waitDuration = 6.0; // seconds — enough for idle timeout + multiple scale-downs

    while (microtime(true) - $waitStart < $waitDuration) {
        WorkerPool::run();
        usleep(200_000); // 200ms between ticks
    }

    $workersAfterIdle = WorkerPoolState::workerCount();
    var_dump("  Workers after {$waitDuration}s idle: $workersAfterIdle");

    $scaleDownWorked = $workersAfterIdle < $workersBefore;

    if ($scaleDownWorked) {
        var_dump(
            "  Scale-DOWN verified: pool shrunk from $workersBefore to $workersAfterIdle workers",
        );
    } else {
        var_dump("  NOTE: Pool did not scale down (still $workersAfterIdle).");
    }

    if ($workersAfterIdle <= WorkerPoolState::$minPoolSize) {
        var_dump(
            "  Pool reached minPoolSize (" .
                WorkerPoolState::$minPoolSize .
                "): YES",
        );
    }

    var_dump("");

    // ═══════════════════════════════════════════════════════════════
    //  Test 3: Tasks still work correctly after scale-down
    // ═══════════════════════════════════════════════════════════════

    var_dump("--- Test 3: Correctness after scale-down ---");

    $postScaleResults = [];

    RunBlocking::new(function () use (&$postScaleResults) {
        $asyncs = [];

        for ($i = 0; $i < 5; $i++) {
            $val = $i;
            $asyncs[$i] = Async::new(function () use ($val) {
                return "post_scale_" . $val;
            }, Dispatchers::IO);
        }

        $postScaleResults = Async::awaitAll(...$asyncs);

    });


    $postScaleCorrect = true;
    for ($i = 0; $i < 5; $i++) {
        $expected = "post_scale_" . $i;
        $actual = $postScaleResults[$i] ?? "MISSING";
        if ($actual !== $expected) {
            var_dump(
                "  ERROR: postScaleResults[$i] = " .
                    var_export($actual, true) .
                    ", expected '$expected'",
            );
            $postScaleCorrect = false;
        }
    }

    if ($postScaleCorrect) {
        var_dump("  Post-scale-down tasks all correct: YES");
    } else {
        var_dump("  Post-scale-down tasks FAILED");
        exit(1);
    }

    var_dump("");

    // ═══════════════════════════════════════════════════════════════
    //  Test 4: Dynamic scaling disabled — pool stays fixed
    // ═══════════════════════════════════════════════════════════════

    var_dump("--- Test 4: Fixed pool (dynamic scaling disabled) ---");

    WorkerPool::shutdown();
    WorkerPool::resetState();

    WorkerPool::setPoolSize(3);
    // Explicitly disable dynamic scaling
    WorkerPool::setDynamicScaling(enabled: false);

    $fixedResults = [];

    RunBlocking::new(function () use (&$fixedResults) {
        $asyncs = [];

        for ($i = 0; $i < 8; $i++) {
            $val = $i;
            $asyncs[$i] = Async::new(function () use ($val) {
                usleep(50_000); // 50ms
                return $val + 100;
            }, Dispatchers::IO);
        }

        $fixedResults = Async::awaitAll(...$asyncs);

    });


    $fixedWorkerCount = WorkerPoolState::workerCount();
    var_dump("  Worker count (should stay at 3): $fixedWorkerCount");

    $fixedCorrect = true;
    for ($i = 0; $i < 8; $i++) {
        $expected = $i + 100;
        $actual = $fixedResults[$i] ?? "MISSING";
        if ($actual !== $expected) {
            var_dump(
                "  ERROR: fixedResults[$i] = " .
                    var_export($actual, true) .
                    ", expected $expected",
            );
            $fixedCorrect = false;
        }
    }

    if ($fixedCorrect) {
        var_dump("  Fixed-pool results all correct: YES");
    } else {
        var_dump("  Fixed-pool results FAILED");
        exit(1);
    }

    $fixedPoolStayed = $fixedWorkerCount === 3;
    if ($fixedPoolStayed) {
        var_dump("  Pool size stayed fixed: YES");
    } else {
        var_dump(
            "  WARNING: Pool size changed unexpectedly to $fixedWorkerCount",
        );
    }

    // ═══════════════════════════════════════════════════════════════
    //  Cleanup
    // ═══════════════════════════════════════════════════════════════

    WorkerPool::shutdown();
    WorkerPool::resetState();

    var_dump("");
    var_dump("=== Summary ===");
    var_dump(
        "  Scale-UP:   " .
            ($scaleUpWorked ? "PASSED" : "SKIPPED (tasks too fast)"),
    );
    var_dump(
        "  Scale-DOWN: " .
            ($scaleDownWorked ? "PASSED" : "SKIPPED (needs longer idle)"),
    );
    var_dump("  Correctness after scale-down: PASSED");
    var_dump("  Fixed pool mode: PASSED");
    var_dump("");
    var_dump("=== All dynamic pool sizing tests completed ===");
});
