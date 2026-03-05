<?php
declare(strict_types=1);

/**
 * Focused stress test: Launch many cooperative tasks and log progress.
 *
 * Usage:
 *   php tests/stress_launch.php [task_count] [pool_initial] [pool_max]
 *
 * Defaults:
 *   task_count  = 50
 *   pool_initial = 16
 *   pool_max     = 128
 *
 * The test uses RuntimeFiberPool via Launch::new() (DEFAULT dispatcher).
 * Each task performs a small cooperative loop that yields periodically
 * (Pause::new()/Pause::force()) so we exercise suspend/resume behavior.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use vosaka\foroutines\RuntimeFiberPool;
use vosaka\foroutines\Launch;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Pause;

$argc = $_SERVER['argc'] ?? 1;
$argv = $_SERVER['argv'] ?? [];

$taskCount = isset($argv[1]) ? (int) $argv[1] : 50;
$poolInitial = isset($argv[2]) ? (int) $argv[2] : 16;
$poolMax = isset($argv[3]) ? (int) $argv[3] : 128;

// Sanity bounds
$taskCount = max(1, min(2000, $taskCount));
$poolInitial = max(1, min(1000, $poolInitial));
$poolMax = max($poolInitial, min(10000, $poolMax));

echo "Stress test — Launch cooperative tasks\n";
echo "  tasks:           {$taskCount}\n";
echo "  pool initial:    {$poolInitial}\n";
echo "  pool max:        {$poolMax}\n";
echo "  PHP version:     " . PHP_VERSION . PHP_EOL;
echo str_repeat('-', 60) . PHP_EOL;

// Configure the runtime pool before boot
RuntimeFiberPool::configure(initialSize: $poolInitial, maxSize: $poolMax, autoScaling: true);
$pool = RuntimeFiberPool::getInstance();

echo "RuntimeFiberPool booted. current size: {$pool->size()}, idle: {$pool->idleCount()}\n\n";

$start = microtime(true);

$jobs = [];

// Create tasks
for ($i = 0; $i < $taskCount; $i++) {
    $idx = $i;
    $jobs[] = Launch::new(function () use ($idx) {
        // Cooperative small workload:
        // compute sum with periodic yields so fiber suspends/resumes.
        $sum = 0;
        $iters = 20; // increase to make tasks longer if desired
        for ($j = 0; $j < $iters; $j++) {
            $sum += ($idx + $j) & 0xFFFF;
            // Periodically yield cooperatively. Use force once in a while
            // so the scheduler sees an immediate suspend.
            if (($j % 5) === 0) {
                Pause::new();
            } elseif (($j % 7) === 0) {
                Pause::force();
            }
        }
        return $sum;
    });
}

// Now consume (join) tasks while logging progress
$completed = 0;
$lastLogAt = 0;
$logInterval = max(1, (int) floor($taskCount / 20)); // ~20 logs across the run
$logInterval = max(1, $logInterval);

echo "Submitted {$taskCount} tasks. Beginning joins and progress logging...\n\n";

RunBlocking::new(function () use (&$jobs, &$completed, &$lastLogAt, $taskCount, $logInterval, $pool, $start) {
    $total = count($jobs);
    foreach ($jobs as $i => $job) {
        // join blocks until the job's result is available (driven cooperatively)
        $result = $job->join();
        $completed++;

        // occasional progress logs
        if ($completed % $logInterval === 0 || $completed === $total) {
            $now = microtime(true);
            $elapsed = $now - $start;
            $avg = $elapsed > 0 ? ($completed / $elapsed) : 0;
            $stats = $pool->stats();
            $idle = $stats['idleCount'] ?? $pool->idleCount();
            $curSize = $stats['currentSize'] ?? $pool->size();
            $cooperativeActive = $stats['cooperativeActive'] ?? $pool->cooperativeActiveCount();
            $pending = $stats['pendingTasks'] ?? $pool->pendingCount();

            $pct = round(($completed / $total) * 100, 2);
            echo sprintf(
                "[%3d/%d] %5.1f%% | elapsed: %5.2fs | rate: %5.2f tasks/s | pool size: %d | idle: %d | coopActive: %d | pending: %d\n",
                $completed,
                $total,
                $pct,
                $elapsed,
                $avg,
                $curSize,
                $idle,
                $cooperativeActive,
                $pending
            );
            // flush stdout in case buffered
            @flush();
        }
    }

    // final stats inside the scheduler
    $now = microtime(true);
    $elapsed = $now - $start;
    echo PHP_EOL;
    echo "All joins complete inside RunBlocking.\n";
    echo sprintf("  total elapsed: %5.3fs\n", $elapsed);
    echo sprintf("  avg throughput: %5.2f tasks/s\n", $taskCount / max(1e-6, $elapsed));
});

// After RunBlocking returns, we are fully drained. Print pool stats.
$end = microtime(true);
$totalTime = $end - $start;
$stats = $pool->stats();

echo str_repeat('-', 60) . PHP_EOL;
echo "Stress test complete\n";
echo "  tasks:         {$taskCount}\n";
echo sprintf("  total time:    %5.3fs\n", $totalTime);
echo sprintf("  avg rate:      %5.2f tasks/s\n", $taskCount / max(1e-6, $totalTime));
echo "  pool current:  " . ($stats['currentSize'] ?? $pool->size()) . PHP_EOL;
echo "  pool idle:     " . ($stats['idleCount'] ?? $pool->idleCount()) . PHP_EOL;
echo "  coop active:   " . ($stats['cooperativeActive'] ?? $pool->cooperativeActiveCount()) . PHP_EOL;
echo "  pending tasks: " . ($stats['pendingTasks'] ?? $pool->pendingCount()) . PHP_EOL;
echo "  total spawned: " . ($stats['totalSpawned'] ?? 0) . PHP_EOL;
echo "  total recycled:" . ($stats['totalRecycled'] ?? 0) . PHP_EOL;
echo PHP_EOL;
