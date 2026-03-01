<?php

/**
 * Run All Benchmarks
 *
 * Executes all benchmark scripts in the comp/ directory sequentially,
 * collecting overall timing and providing a unified summary.
 *
 * Usage:
 *   cd comp/
 *   php run_all.php
 *
 * Options (via environment variables):
 *   BENCH_FILTER=01,03    Run only bench_01 and bench_03
 *   BENCH_NO_COLOR=1      Disable ANSI color output
 *   BENCH_TIMEOUT=300     Per-benchmark timeout in seconds (default: 120)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/BenchHelper.php';

use comp\BenchHelper;

// ─── Configuration ───────────────────────────────────────────────────────

$noColor  = (bool) getenv('BENCH_NO_COLOR');
$filter   = getenv('BENCH_FILTER') ?: '';
$timeout  = (int) (getenv('BENCH_TIMEOUT') ?: 120);

if ($noColor) {
    BenchHelper::disableColor();
}

// ─── Discover benchmark files ────────────────────────────────────────────

$benchDir = __DIR__;
$pattern  = $benchDir . DIRECTORY_SEPARATOR . 'bench_*.php';
$files    = glob($pattern);

if ($files === false || count($files) === 0) {
    echo "No benchmark files found matching: {$pattern}\n";
    exit(1);
}

sort($files);

// Apply filter if provided (e.g. BENCH_FILTER=01,03)
if ($filter !== '') {
    $allowedPrefixes = array_map('trim', explode(',', $filter));
    $files = array_filter($files, function (string $file) use ($allowedPrefixes): bool {
        $basename = basename($file);
        foreach ($allowedPrefixes as $prefix) {
            if (str_contains($basename, "bench_{$prefix}")) {
                return true;
            }
        }
        return false;
    });
    $files = array_values($files);
}

if (count($files) === 0) {
    echo "No benchmark files matched the filter: {$filter}\n";
    exit(1);
}

// ─── Banner ──────────────────────────────────────────────────────────────

$divider = str_repeat('═', 80);
$thinDiv = str_repeat('─', 80);

echo "\n";
echo "\033[1;36m{$divider}\033[0m\n";
echo "\033[1;36m  VOsaka Foroutines — Full Benchmark Suite\033[0m\n";
echo "\033[1;36m{$divider}\033[0m\n";
echo "\n";
echo "  PHP Version:   " . PHP_VERSION . "\n";
echo "  OS:            " . PHP_OS . " (" . php_uname('m') . ")\n";
echo "  Fibers:        " . (class_exists('Fiber') ? 'yes' : 'NO — PHP 8.1+ required') . "\n";
echo "  pcntl_fork:    " . (function_exists('pcntl_fork') ? 'yes' : 'no (Windows fallback: symfony/process)') . "\n";
echo "  shmop:         " . (extension_loaded('shmop') ? 'yes' : 'no') . "\n";
echo "  Benchmarks:    " . count($files) . " file(s)\n";
echo "  Timeout:       {$timeout}s per benchmark\n";
if ($filter !== '') {
    echo "  Filter:        {$filter}\n";
}
echo "\n";
echo "\033[2m{$thinDiv}\033[0m\n";

// ─── Run each benchmark ──────────────────────────────────────────────────

$overallStart = microtime(true);
$results      = [];
$passed       = 0;
$failed       = 0;
$skipped      = 0;

foreach ($files as $index => $file) {
    $basename = basename($file);
    $number   = $index + 1;
    $total    = count($files);

    echo "\n";
    echo "\033[1;33m  [{$number}/{$total}] Running: {$basename}\033[0m\n";
    echo "\033[2m  " . str_repeat('─', 60) . "\033[0m\n";

    $benchStart = microtime(true);

    // Run as a separate process so each benchmark gets a clean state
    // (no leftover fibers, Launch queue, WorkerPool, etc.)
    $phpBin = PHP_BINARY ?: 'php';

    $descriptors = [
        0 => ['pipe', 'r'],  // stdin
        1 => ['pipe', 'w'],  // stdout
        2 => ['pipe', 'w'],  // stderr
    ];

    $env = null; // inherit parent environment

    $process = proc_open(
        [$phpBin, $file],
        $descriptors,
        $pipes,
        $benchDir,
        $env
    );

    if (!is_resource($process)) {
        echo "\033[31m  ✗ Failed to start process for: {$basename}\033[0m\n";
        $failed++;
        $results[] = [
            'file'   => $basename,
            'time'   => 0.0,
            'status' => 'FAIL',
            'error'  => 'proc_open failed',
        ];
        continue;
    }

    // Close stdin — benchmarks don't need input
    fclose($pipes[0]);

    // Set non-blocking so we can implement a timeout
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $timedOut = false;

    while (true) {
        $status = proc_get_status($process);

        // Read available output
        $chunk = stream_get_contents($pipes[1]);
        if ($chunk !== false && $chunk !== '') {
            $stdout .= $chunk;
            // Stream output in real-time
            echo $chunk;
        }

        $errChunk = stream_get_contents($pipes[2]);
        if ($errChunk !== false && $errChunk !== '') {
            $stderr .= $errChunk;
        }

        // Check if process has exited
        if (!$status['running']) {
            // Drain remaining output
            $remaining = stream_get_contents($pipes[1]);
            if ($remaining !== false && $remaining !== '') {
                $stdout .= $remaining;
                echo $remaining;
            }
            $errRemaining = stream_get_contents($pipes[2]);
            if ($errRemaining !== false && $errRemaining !== '') {
                $stderr .= $errRemaining;
            }
            break;
        }

        // Check timeout
        $elapsed = microtime(true) - $benchStart;
        if ($elapsed > $timeout) {
            $timedOut = true;
            // Kill the process
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $pid = $status['pid'];
                exec("taskkill /F /T /PID {$pid} 2>NUL");
            } else {
                proc_terminate($process, 9); // SIGKILL
            }
            break;
        }

        // Small sleep to avoid busy-wait
        usleep(50000); // 50ms
    }

    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    $benchElapsed = microtime(true) - $benchStart;

    if ($timedOut) {
        echo "\n\033[31m  ✗ TIMEOUT after {$timeout}s: {$basename}\033[0m\n";
        $failed++;
        $results[] = [
            'file'   => $basename,
            'time'   => $benchElapsed,
            'status' => 'TIMEOUT',
            'error'  => "Exceeded {$timeout}s limit",
        ];
    } elseif ($exitCode !== 0) {
        echo "\n\033[31m  ✗ FAILED (exit code {$exitCode}): {$basename}\033[0m\n";
        if ($stderr !== '') {
            // Show last few lines of stderr
            $errLines = explode("\n", trim($stderr));
            $lastLines = array_slice($errLines, -5);
            foreach ($lastLines as $line) {
                echo "\033[31m    {$line}\033[0m\n";
            }
        }
        $failed++;
        $results[] = [
            'file'   => $basename,
            'time'   => $benchElapsed,
            'status' => 'FAIL',
            'error'  => "exit code {$exitCode}",
        ];
    } else {
        $timeFormatted = BenchHelper::formatMs($benchElapsed * 1000.0);
        echo "\n\033[32m  ✓ PASSED in {$timeFormatted}: {$basename}\033[0m\n";
        $passed++;
        $results[] = [
            'file'   => $basename,
            'time'   => $benchElapsed,
            'status' => 'PASS',
            'error'  => '',
        ];
    }
}

// ─── Overall summary ─────────────────────────────────────────────────────

$overallElapsed = microtime(true) - $overallStart;

echo "\n";
echo "\033[1;36m{$divider}\033[0m\n";
echo "\033[1;36m  OVERALL RESULTS\033[0m\n";
echo "\033[1;36m{$divider}\033[0m\n";
echo "\n";

// Results table
echo sprintf(
    "  \033[1m%-40s %10s %10s\033[0m\n",
    'Benchmark', 'Time', 'Status'
);
echo "  " . str_repeat('─', 64) . "\n";

foreach ($results as $r) {
    $timeStr = BenchHelper::formatMs($r['time'] * 1000.0);

    $statusColor = match ($r['status']) {
        'PASS'    => "\033[32m",
        'FAIL'    => "\033[31m",
        'TIMEOUT' => "\033[33m",
        default   => "\033[0m",
    };

    $errorSuffix = $r['error'] !== '' ? " ({$r['error']})" : '';

    echo sprintf(
        "  %-40s %10s %s%s%s\033[0m\n",
        $r['file'],
        $timeStr,
        $statusColor,
        $r['status'],
        $errorSuffix
    );
}

echo "  " . str_repeat('─', 64) . "\n";

$totalTimeStr = BenchHelper::formatMs($overallElapsed * 1000.0);

echo "\n";
echo "  Total benchmarks:  " . count($results) . "\n";
echo "  \033[32mPassed:\033[0m           {$passed}\n";
if ($failed > 0) {
    echo "  \033[31mFailed:\033[0m           {$failed}\n";
}
if ($skipped > 0) {
    echo "  \033[33mSkipped:\033[0m          {$skipped}\n";
}
echo "  Total time:        {$totalTimeStr}\n";
echo "  Peak memory:       " . BenchHelper::peakMemoryUsage() . " (runner process)\n";
echo "\n";

// Final status line
if ($failed === 0) {
    echo "\033[1;32m  ✓ All benchmarks passed successfully!\033[0m\n";
} else {
    echo "\033[1;31m  ✗ {$failed} benchmark(s) failed.\033[0m\n";
}

echo "\n";

// Exit with error code if any benchmarks failed
exit($failed > 0 ? 1 : 0);
