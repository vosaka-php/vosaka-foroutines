<?php

/**
 * Test: 4 worker pool size, 7 IO tasks — proves pool queuing
 *
 * This test sets the WorkerPool size to 4 but submits 7 tasks,
 * each sleeping for 30 seconds. If the pool truly limits concurrency:
 *
 *   Batch 1: tasks 1-4 run immediately       (0s  → 30s)
 *   Batch 2: tasks 5-7 run after batch 1     (30s → 60s)
 *   Total wall-clock time ≈ 60s
 *
 * If there were NO pool (all 7 run at once): ≈ 30s
 * If sequential (no concurrency at all):     ≈ 210s
 *
 * So 60s proves both that:
 *   1. Workers run in PARALLEL (not 210s)
 *   2. Pool LIMITS concurrency to 4 (not 30s)
 *
 * While running, open Task Manager or run:
 *   Windows:  tasklist | findstr php
 *   Linux:    ps aux | grep php
 *   macOS:    ps aux | grep php
 *
 * You should see exactly 4 child php processes at any given time
 * (plus 1 parent process).
 *
 * Usage:
 *   cd tests
 *   php test_worker_processes.php
 */

require "../vendor/autoload.php";

use vosaka\foroutines\Dispatchers;
use vosaka\foroutines\Launch;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Thread;
use vosaka\foroutines\WorkerPool;

use function vosaka\foroutines\main;

main(function () {
    $poolSize = 4;
    $taskCount = 7;
    $sleepSeconds = 30;

    WorkerPool::setPoolSize($poolSize);

    echo "╔══════════════════════════════════════════════════════════════╗\n";
    echo "║   WorkerPool Queuing Test                                  ║\n";
    echo "║                                                            ║\n";
    echo "║   Pool size:  {$poolSize} workers                                    ║\n";
    echo "║   Tasks:      {$taskCount} (each sleeps {$sleepSeconds}s)                          ║\n";
    echo "║                                                            ║\n";
    echo "║   Expected:                                                ║\n";
    echo "║     Batch 1 → tasks 1-4 start immediately    (0s  → 30s)  ║\n";
    echo "║     Batch 2 → tasks 5-7 start after batch 1  (30s → 60s)  ║\n";
    echo "║     Total ≈ 60s (proves pool limits to {$poolSize} concurrent)      ║\n";
    echo "║                                                            ║\n";
    echo "║   Check Task Manager — you should see exactly {$poolSize} child     ║\n";
    echo "║   php processes at any time, NOT {$taskCount}.                        ║\n";
    echo "║                                                            ║\n";
    echo "║   Windows:  tasklist | findstr php                         ║\n";
    echo "║   Linux:    ps aux | grep php                              ║\n";
    echo "╚══════════════════════════════════════════════════════════════╝\n\n";

    $startTime = microtime(true);

    RunBlocking::new(function () use (
        $poolSize,
        $taskCount,
        $sleepSeconds,
        $startTime,
    ) {
        $results = [];

        for ($i = 1; $i <= $taskCount; $i++) {
            $taskNum = $i;
            $sleep = $sleepSeconds;

            Launch::new(function () use ($taskNum, $sleep) {
                $pid = getmypid();
                $startedAt = date("H:i:s");

                // Sleep 30s — keeps the process alive for observation
                sleep($sleep);

                $finishedAt = date("H:i:s");

                return [
                    "task" => $taskNum,
                    "pid" => $pid,
                    "started" => $startedAt,
                    "finished" => $finishedAt,
                ];
            }, Dispatchers::IO);
        }

        echo "✓ All {$taskCount} IO tasks submitted at " . date("H:i:s") . "\n";
        echo "  Pool size is {$poolSize} → first 4 run now, remaining 3 queued.\n";
        echo "  Check Task Manager NOW!\n\n";

        // Live progress bar
        $expectedTotal = $sleepSeconds * 2; // 2 batches
        $lastPrinted = -1;

        while (!WorkerPool::isEmpty()) {
            $elapsed = (int) (microtime(true) - $startTime);

            if ($elapsed !== $lastPrinted) {
                $lastPrinted = $elapsed;

                // Show which batch is running
                $batch =
                    $elapsed < $sleepSeconds
                        ? "Batch 1 (tasks 1-4)"
                        : "Batch 2 (tasks 5-7)";
                $barLen = 40;
                $filled = min(
                    $barLen,
                    (int) (($elapsed / $expectedTotal) * $barLen),
                );
                $bar =
                    str_repeat("█", $filled) .
                    str_repeat("░", $barLen - $filled);

                printf("\r  [%s] %ds elapsed | %s   ", $bar, $elapsed, $batch);
            }

            \vosaka\foroutines\Pause::new();
        }

        echo "\n\n";
    });


    $totalTime = round(microtime(true) - $startTime, 2);

    echo "═══════════════════════════════════════════════════════════════\n\n";

    // Analyze results
    $expectedMin = $sleepSeconds * 2 - 5; // ~55s
    $expectedMax = $sleepSeconds * 2 + 10; // ~70s

    if ($totalTime >= $expectedMin && $totalTime <= $expectedMax) {
        echo "  ✓ PASS: Total time = {$totalTime}s (expected ≈ " .
            $sleepSeconds * 2 .
            "s)\n\n";
        echo "  This proves:\n";
        echo "    1. Workers run in PARALLEL   (not " .
            $sleepSeconds * $taskCount .
            "s sequential)\n";
        echo "    2. Pool LIMITS concurrency   (not {$sleepSeconds}s if all 7 ran at once)\n";
        echo "    3. Queued tasks waited for a free worker slot\n";
    } elseif ($totalTime < $expectedMin) {
        echo "  ✗ UNEXPECTED: Total time = {$totalTime}s — too fast!\n";
        echo "    Pool may not be limiting concurrency to {$poolSize}.\n";
        echo "    All {$taskCount} tasks might have run at once.\n";
    } else {
        echo "  ✗ WARN: Total time = {$totalTime}s — slower than expected.\n";
        echo "    Workers may not have run fully in parallel.\n";
    }

    echo "\n  Shutting down WorkerPool...\n";
    WorkerPool::shutdown();
    echo "  Done. All child processes should be gone from Task Manager.\n";
});
