<?php

/**
 * Test: Task Batching — comparing single-task vs batch mode performance
 *
 * Demonstrates that when batchSize > 1, multiple tasks are grouped into
 * a single BATCH: message sent to each worker, reducing IPC round-trips
 * and improving throughput for many small/fast tasks.
 *
 * Test structure:
 *   1. Run N small tasks with batchSize = 1 (original behavior)
 *   2. Run N small tasks with batchSize = 5
 *   3. Run N small tasks with batchSize = 10
 *   4. Verify all results are correct in every mode
 *   5. Compare elapsed times
 *
 * Expected behavior:
 *   - All modes produce identical, correct results
 *   - Batch modes should be faster due to fewer IPC round-trips
 *   - Larger batch sizes should show more improvement (up to a point)
 */

require __DIR__ . "/../vendor/autoload.php";

use vosaka\foroutines\Async;
use vosaka\foroutines\Dispatchers;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Thread;
use vosaka\foroutines\WorkerPool;
use vosaka\foroutines\WorkerPoolState;

use function vosaka\foroutines\main;

const TASK_COUNT = 20;

/**
 * Run a batch of small tasks and return [elapsed_ms, results].
 *
 * @param int $batchSize The batch size to use.
 * @return array{float, array<int, int>}
 */
function runBatchTest(int $batchSize): array
{
    // Shut down existing pool so we start fresh
    WorkerPool::shutdown();
    WorkerPool::resetState();

    WorkerPool::setPoolSize(4);
    WorkerPool::setBatchSize($batchSize);

    $start = hrtime(true);
    $results = [];

    RunBlocking::new(function () use (&$results) {
        $asyncs = [];

        for ($i = 0; $i < TASK_COUNT; $i++) {
            $val = $i;
            $asyncs[$i] = Async::new(function () use ($val) {
                // Small computation — the task itself is trivial,
                // so IPC overhead dominates total time.
                return $val * $val;
            }, Dispatchers::IO);
        }

        // Await all concurrently
        $results = Async::awaitAll(...$asyncs);

    });


    $elapsed = (hrtime(true) - $start) / 1_000_000; // ms

    // Clean up
    WorkerPool::shutdown();
    WorkerPool::resetState();

    return [$elapsed, $results];
}

/**
 * Verify that all results are correct (result[i] == i*i).
 *
 * @param array<int, int> $results
 * @return bool
 */
function verifyResults(array $results): bool
{
    if (count($results) !== TASK_COUNT) {
        var_dump("ERROR: Expected " . TASK_COUNT . " results, got " . count($results));
        return false;
    }

    for ($i = 0; $i < TASK_COUNT; $i++) {
        $expected = $i * $i;
        $actual = $results[$i] ?? "MISSING";
        if ($actual !== $expected) {
            var_dump("ERROR: results[$i] = $actual, expected $expected");
            return false;
        }
    }

    return true;
}

// ═══════════════════════════════════════════════════════════════════════
//  Main test execution
// ═══════════════════════════════════════════════════════════════════════

main(function () {
    var_dump("=== Task Batching Test ===");
    var_dump("Task count: " . TASK_COUNT);
    var_dump("");

    // ── Test 1: batchSize = 1 (original behavior) ─────────────────
    var_dump("--- batchSize = 1 (no batching) ---");
    [$time1, $results1] = runBatchTest(1);
    $ok1 = verifyResults($results1);
    var_dump(sprintf("  Time: %.0fms | Correct: %s", $time1, $ok1 ? "YES" : "NO"));
    var_dump("");

    // ── Test 2: batchSize = 5 ─────────────────────────────────────
    var_dump("--- batchSize = 5 ---");
    [$time5, $results5] = runBatchTest(5);
    $ok5 = verifyResults($results5);
    var_dump(sprintf("  Time: %.0fms | Correct: %s", $time5, $ok5 ? "YES" : "NO"));
    var_dump("");

    // ── Test 3: batchSize = 10 ────────────────────────────────────
    var_dump("--- batchSize = 10 ---");
    [$time10, $results10] = runBatchTest(10);
    $ok10 = verifyResults($results10);
    var_dump(sprintf("  Time: %.0fms | Correct: %s", $time10, $ok10 ? "YES" : "NO"));
    var_dump("");

    // ── Summary ───────────────────────────────────────────────────
    var_dump("=== Summary ===");
    var_dump(sprintf("  batchSize=1  : %.0fms", $time1));
    var_dump(sprintf("  batchSize=5  : %.0fms", $time5));
    var_dump(sprintf("  batchSize=10 : %.0fms", $time10));

    if ($time5 > 0 && $time1 > 0) {
        $speedup5 = $time1 / $time5;
        var_dump(sprintf("  Speedup (batch=5  vs 1): %.2fx", $speedup5));
    }
    if ($time10 > 0 && $time1 > 0) {
        $speedup10 = $time1 / $time10;
        var_dump(sprintf("  Speedup (batch=10 vs 1): %.2fx", $speedup10));
    }

    var_dump("");

    // ── Correctness assertions ────────────────────────────────────
    $allCorrect = $ok1 && $ok5 && $ok10;

    if ($allCorrect) {
        var_dump("ALL TESTS PASSED — all batch sizes produce correct results");
    } else {
        var_dump("SOME TESTS FAILED — check results above");
        exit(1);
    }

    // ── Test 4: Mixed batch — verify error handling in batch mode ──
    var_dump("");
    var_dump("--- Test 4: Error handling in batch mode ---");

    WorkerPool::shutdown();
    WorkerPool::resetState();
    WorkerPool::setPoolSize(2);
    WorkerPool::setBatchSize(5);

    $errorResults = [];

    RunBlocking::new(function () use (&$errorResults) {
        $asyncOk = Async::new(function () {
            return "success";
        }, Dispatchers::IO);

        $asyncFail = Async::new(function () {
            throw new \RuntimeException("intentional test error");
        }, Dispatchers::IO);

        $asyncOk2 = Async::new(function () {
            return 42;
        }, Dispatchers::IO);

        try {
            $errorResults[0] = $asyncOk->await();
        } catch (\Throwable $e) {}
        try {
            $errorResults[1] = $asyncFail->await();
        } catch (\Throwable $e) {
            $errorResults[1] = "Error: " . $e->getMessage();
        }
        try {
            $errorResults[2] = $asyncOk2->await();
        } catch (\Throwable $e) {}

    });


    WorkerPool::shutdown();
    WorkerPool::resetState();

    $okResult = $errorResults[0] ?? "MISSING";
    $errResult = $errorResults[1] ?? "MISSING";
    $ok2Result = $errorResults[2] ?? "MISSING";

    $errorTestPassed = true;

    if ($okResult !== "success") {
        var_dump("  ERROR: First task should be 'success', got: " . var_export($okResult, true));
        $errorTestPassed = false;
    } else {
        var_dump("  First task: success (correct)");
    }

    if (!is_string($errResult) || !str_contains($errResult, "Error:")) {
        var_dump("  ERROR: Second task should be an error string, got: " . var_export($errResult, true));
        $errorTestPassed = false;
    } else {
        var_dump("  Second task: error caught correctly");
    }

    if ($ok2Result !== 42) {
        var_dump("  ERROR: Third task should be 42, got: " . var_export($ok2Result, true));
        $errorTestPassed = false;
    } else {
        var_dump("  Third task: 42 (correct)");
    }

    if ($errorTestPassed) {
        var_dump("  Error handling test PASSED");
    } else {
        var_dump("  Error handling test FAILED");
        exit(1);
    }

    var_dump("");
    var_dump("=== All task batching tests completed successfully ===");
});
