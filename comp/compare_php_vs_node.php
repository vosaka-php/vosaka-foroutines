<?php

/**
 * PHP vs Node.js — Side-by-Side Benchmark Comparison Runner
 *
 * Runs all PHP benchmarks (bench_01..bench_06) and the Node.js benchmark suite,
 * captures structured timing data from both, then prints a unified comparison
 * table showing how VOsaka Foroutines compares to Node.js on identical workloads.
 *
 * Usage:
 *   cd comp/
 *   php compare_php_vs_node.php
 *
 * Requirements:
 *   - PHP 8.1+ with Fiber support
 *   - Node.js >= 16 (for worker_threads, performance.now, etc.)
 *   - All bench_*.php files in the same directory
 *   - node_compare.mjs in the same directory
 *
 * Environment variables:
 *   BENCH_FILTER=01,03       Run only specific benchmarks (comma-separated)
 *   BENCH_NO_COLOR=1         Disable ANSI colors
 *   BENCH_TIMEOUT=120        Per-benchmark timeout in seconds (default: 180)
 *   NODE_BIN=node            Path to Node.js binary (default: node)
 *   PHP_BIN=php              Path to PHP binary (default: current PHP_BINARY)
 */

declare(strict_types=1);

require __DIR__ . "/../vendor/autoload.php";
require __DIR__ . "/BenchHelper.php";

use comp\BenchHelper;

// ─── ANSI color helpers ──────────────────────────────────────────────────

final class Color
{
    public const RESET = "\033[0m";
    public const BOLD = "\033[1m";
    public const DIM = "\033[2m";
    public const RED = "\033[31m";
    public const GREEN = "\033[32m";
    public const YELLOW = "\033[33m";
    public const CYAN = "\033[36m";
    public const MAGENTA = "\033[35m";
    public const WHITE = "\033[37m";
    public const BG_CYAN = "\033[46m";

    private static bool $enabled = true;

    public static function disable(): void
    {
        self::$enabled = false;
    }

    public static function c(string $color, string $text): string
    {
        if (!self::$enabled) {
            return $text;
        }
        return $color . $text . self::RESET;
    }
}

// ─── Configuration ───────────────────────────────────────────────────────

$noColor = (bool) getenv("BENCH_NO_COLOR");
$filter = getenv("BENCH_FILTER") ?: "";
$timeout = (int) (getenv("BENCH_TIMEOUT") ?: 180);
$nodeBin = getenv("NODE_BIN") ?: "node";
$phpBin = getenv("PHP_BIN") ?: (PHP_BINARY ?: "php");

if ($noColor) {
    Color::disable();
    BenchHelper::disableColor();
}

// ─── Utility functions ───────────────────────────────────────────────────

/**
 * Run a command and return [stdout, stderr, exitCode, elapsedMs].
 */
function runCommand(string $command, string $cwd, int $timeoutSec): array
{
    $descriptors = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"],
    ];

    $start = microtime(true);
    $process = proc_open($command, $descriptors, $pipes, $cwd);

    if (!is_resource($process)) {
        return ["", "Failed to start process", -1, 0.0];
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = "";
    $stderr = "";
    $timedOut = false;

    while (true) {
        $status = proc_get_status($process);

        $chunk = stream_get_contents($pipes[1]);
        if ($chunk !== false && $chunk !== "") {
            $stdout .= $chunk;
        }

        $errChunk = stream_get_contents($pipes[2]);
        if ($errChunk !== false && $errChunk !== "") {
            $stderr .= $errChunk;
        }

        if (!$status["running"]) {
            $remaining = stream_get_contents($pipes[1]);
            if ($remaining !== false && $remaining !== "") {
                $stdout .= $remaining;
            }
            $errRemaining = stream_get_contents($pipes[2]);
            if ($errRemaining !== false && $errRemaining !== "") {
                $stderr .= $errRemaining;
            }
            break;
        }

        if (microtime(true) - $start > $timeoutSec) {
            $timedOut = true;
            if (strtoupper(substr(PHP_OS, 0, 3)) === "WIN") {
                $pid = $status["pid"];
                exec("taskkill /F /T /PID {$pid} 2>NUL");
            } else {
                proc_terminate($process, 9);
            }
            break;
        }

        usleep(50000);
    }

    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    $elapsedMs = (microtime(true) - $start) * 1000.0;

    if ($timedOut) {
        return [$stdout, "TIMEOUT after {$timeoutSec}s", -2, $elapsedMs];
    }

    return [$stdout, $stderr, $exitCode, $elapsedMs];
}

/**
 * Format milliseconds nicely.
 */
function fmtMs(float $ms): string
{
    if ($ms < 0.001) {
        return "~0 µs";
    }
    if ($ms < 1.0) {
        return round($ms * 1000, 1) . " µs";
    }
    if ($ms < 1000.0) {
        return round($ms, 2) . " ms";
    }
    return round($ms / 1000.0, 3) . " s";
}

/**
 * Strip all ANSI escape sequences from a string.
 */
function stripAnsi(string $text): string
{
    return preg_replace('/\033\[[0-9;]*m/', "", $text);
}

/**
 * Parse timing data from benchmark output.
 * Returns an associative array of test_name => elapsed_ms.
 *
 * Looks for summary table rows like:
 *   "  5×200ms delay                      1.026 s    207.63 ms   4.94x ▲    basic concurrency"
 *   "  TOTAL                              13.541 s      1.793 s   7.55x ▲"
 *
 * Also parses Node.js summary rows (same format but slightly different spacing).
 */
function parseTimings(string $output): array
{
    $timings = [];

    // Strip ANSI color codes FIRST so regex works on clean text
    $clean = stripAnsi($output);

    $lines = explode("\n", $clean);

    $inSummary = false;
    $benchName = "";

    foreach ($lines as $line) {
        // Detect benchmark header — PHP: "Benchmark 01:", Node: "Benchmark 01:"
        if (preg_match("/Benchmark\s+(\d+)/i", $line, $m)) {
            $benchName = "bench_" . $m[1];
        }

        // Detect summary section start
        if (
            str_contains($line, "BENCHMARK SUMMARY") ||
            str_contains($line, "SUMMARY")
        ) {
            // Only enter summary if we see the actual table header pattern
            // (avoid false positives from other "SUMMARY" text)
            if (preg_match("/SUMMARY/", $line)) {
                $inSummary = true;
            }
            continue;
        }

        // End of summary section
        if (
            $inSummary &&
            (str_contains($line, "Environment:") ||
                str_contains($line, "Interpretation") ||
                str_contains($line, "Where ") ||
                str_contains($line, "Key "))
        ) {
            $inSummary = false;
            continue;
        }

        // Skip table headers and separator lines
        if ($inSummary) {
            $trimmed = trim($line);

            // Skip empty, separator, or header lines
            if (
                $trimmed === "" ||
                preg_match('/^[─═\-]+$/', $trimmed) ||
                str_starts_with($trimmed, "Test ") ||
                str_starts_with($trimmed, "Note")
            ) {
                continue;
            }

            // Parse summary table row.
            // The format is: "  TestName<spaces>TIME1<spaces>TIME2<spaces>SPEEDUPx ARROW<spaces>note"
            // TIME patterns: "123.45 ms", "1.234 s", "456.7 µs", "43.2 µs"
            // SPEEDUP pattern: "4.94x ▲" or "229.08x ▼" or "1.04x ▲"
            //
            // Use a more flexible regex that captures:
            //   1) test name (non-greedy, up to first multi-space gap before a number)
            //   2) first time value
            //   3) second time value
            //   4) speedup
            $timePattern = "[\d.]+\s*(?:µs|ms|s)";
            $speedupPattern = "[\d.]+x\s*[▲▼≈]";

            if (
                preg_match(
                    "/^\s{1,4}(\S.+?)\s{2,}(" .
                        $timePattern .
                        ")\s{2,}(" .
                        $timePattern .
                        ")\s{2,}(" .
                        $speedupPattern .
                        ")/u",
                    $line,
                    $m,
                )
            ) {
                $testName = trim($m[1]);
                $blockingMs = parseTimeToMs($m[2]);
                $asyncMs = parseTimeToMs($m[3]);

                // Skip actual table header row
                if ($testName === "Test" || $testName === "Benchmark") {
                    continue;
                }

                if ($testName === "TOTAL") {
                    $timings[$benchName . "/TOTAL"] = [
                        "blocking" => $blockingMs,
                        "async" => $asyncMs,
                    ];
                } else {
                    $key = $benchName . "/" . $testName;
                    $timings[$key] = [
                        "blocking" => $blockingMs,
                        "async" => $asyncMs,
                    ];
                }
            }
        }
    }

    return $timings;
}

/**
 * Convert a time string like "123.45 ms", "1.234 s", "456.7 µs" to milliseconds.
 */
function parseTimeToMs(string $timeStr): float
{
    $timeStr = trim($timeStr);

    // µs (microseconds) — must check before "s" to avoid false match
    if (preg_match("/([\d.]+)\s*(?:µs|us)/", $timeStr, $m)) {
        return (float) $m[1] / 1000.0;
    }
    // ms (milliseconds)
    if (preg_match("/([\d.]+)\s*ms/", $timeStr, $m)) {
        return (float) $m[1];
    }
    // s (seconds) — must be last, only match bare "s" not preceded by m or µ/u
    if (preg_match("/([\d.]+)\s*s\b/", $timeStr, $m)) {
        return (float) $m[1] * 1000.0;
    }

    return 0.0;
}

/**
 * Format a PHP/Node ratio with color coding.
 */
function formatRatio(float $ratio): string
{
    if ($ratio <= 0) {
        return "—";
    }

    if ($ratio > 10.0) {
        return Color::c(Color::RED, sprintf("%.1fx (PHP slower)", $ratio));
    } elseif ($ratio > 2.0) {
        return Color::c(Color::RED, sprintf("%.1fx (PHP slower)", $ratio));
    } elseif ($ratio > 1.15) {
        return Color::c(Color::YELLOW, sprintf("%.1fx", $ratio));
    } elseif ($ratio >= 0.87) {
        return Color::c(Color::GREEN, sprintf("~%.1fx (comparable)", $ratio));
    } else {
        return Color::c(Color::GREEN, sprintf("%.1fx (PHP faster)", $ratio));
    }
}

// ─── Banner ──────────────────────────────────────────────────────────────

$divider = str_repeat("═", 90);
$thinDiv = str_repeat("─", 90);

echo "\n";
echo Color::c(Color::BOLD . Color::CYAN, $divider) . "\n";
echo Color::c(
    Color::BOLD . Color::CYAN,
    "  VOsaka Foroutines PHP vs Node.js — Side-by-Side Benchmark Comparison",
) . "\n";
echo Color::c(Color::BOLD . Color::CYAN, $divider) . "\n";
echo "\n";
echo "  PHP Version:   " . PHP_VERSION . "\n";
echo "  PHP Binary:    " . $phpBin . "\n";
echo "  OS:            " . PHP_OS . " (" . php_uname("m") . ")\n";
echo "  Fibers:        " . (class_exists("Fiber") ? "yes" : "NO") . "\n";
echo "  pcntl_fork:    " .
    (function_exists("pcntl_fork") ? "yes" : "no") .
    "\n";
echo "  shmop:         " . (extension_loaded("shmop") ? "yes" : "no") . "\n";

// Check Node.js
$nodeVersion = trim(
    shell_exec(escapeshellarg($nodeBin) . " --version 2>&1") ?? "",
);
if (!str_starts_with($nodeVersion, "v")) {
    echo Color::c(Color::RED, "\n  ERROR: Node.js not found at '{$nodeBin}'") .
        "\n";
    echo "  Set NODE_BIN environment variable to the correct path.\n\n";
    exit(1);
}
echo "  Node.js:       " . $nodeVersion . "\n";
echo "  Node Binary:   " . $nodeBin . "\n";
echo "  Timeout:       {$timeout}s per benchmark\n";
if ($filter !== "") {
    echo "  Filter:        " . $filter . "\n";
}
echo "\n";

// ─── Discover PHP benchmark files ────────────────────────────────────────

$benchDir = __DIR__;
$phpFiles = glob($benchDir . DIRECTORY_SEPARATOR . "bench_*.php");

if (!$phpFiles || count($phpFiles) === 0) {
    echo Color::c(Color::RED, "  No PHP benchmark files found!") . "\n";
    exit(1);
}

sort($phpFiles);

// Apply filter
if ($filter !== "") {
    $allowedPrefixes = array_map("trim", explode(",", $filter));
    $phpFiles = array_filter($phpFiles, function (string $file) use (
        $allowedPrefixes,
    ): bool {
        $basename = basename($file);
        foreach ($allowedPrefixes as $prefix) {
            if (str_contains($basename, "bench_{$prefix}")) {
                return true;
            }
        }
        return false;
    });
    $phpFiles = array_values($phpFiles);
}

echo Color::c(Color::DIM, $thinDiv) . "\n";
echo "  Found " . count($phpFiles) . " PHP benchmark(s)\n";
echo Color::c(Color::DIM, $thinDiv) . "\n";
echo "\n";

// ═════════════════════════════════════════════════════════════════════════
//  PHASE 1: Run PHP Benchmarks
// ═════════════════════════════════════════════════════════════════════════

echo Color::c(
    Color::BOLD . Color::MAGENTA,
    "  ╔══════════════════════════════════════════════════╗",
) . "\n";
echo Color::c(
    Color::BOLD . Color::MAGENTA,
    "  ║   PHASE 1: Running PHP Benchmarks (VOsaka)      ║",
) . "\n";
echo Color::c(
    Color::BOLD . Color::MAGENTA,
    "  ╚══════════════════════════════════════════════════╝",
) . "\n\n";

$phpResults = [];
$phpTimings = [];
$phpTotalMs = 0.0;
$phpPassed = 0;
$phpFailed = 0;

foreach ($phpFiles as $index => $file) {
    $basename = basename($file);
    $number = $index + 1;
    $total = count($phpFiles);

    echo Color::c(
        Color::YELLOW,
        "  [{$number}/{$total}] Running: {$basename}",
    ) . "\n";

    $cmd = escapeshellarg($phpBin) . " " . escapeshellarg($file);
    [$stdout, $stderr, $exitCode, $elapsedMs] = runCommand(
        $cmd,
        $benchDir,
        $timeout,
    );

    $phpTotalMs += $elapsedMs;

    if ($exitCode === -2) {
        echo Color::c(Color::RED, "    ✗ TIMEOUT after {$timeout}s") . "\n";
        $phpFailed++;
        $phpResults[] = [
            "file" => $basename,
            "status" => "TIMEOUT",
            "ms" => $elapsedMs,
        ];
    } elseif ($exitCode !== 0) {
        echo Color::c(Color::RED, "    ✗ FAILED (exit code {$exitCode})") .
            "\n";
        if ($stderr !== "") {
            $errLines = array_slice(explode("\n", trim($stderr)), -3);
            foreach ($errLines as $line) {
                echo Color::c(Color::RED, "      {$line}") . "\n";
            }
        }
        $phpFailed++;
        $phpResults[] = [
            "file" => $basename,
            "status" => "FAIL",
            "ms" => $elapsedMs,
        ];
    } else {
        echo Color::c(Color::GREEN, "    ✓ PASSED in " . fmtMs($elapsedMs)) .
            "\n";
        $phpPassed++;
        $phpResults[] = [
            "file" => $basename,
            "status" => "PASS",
            "ms" => $elapsedMs,
        ];

        // Parse timings from output
        $parsed = parseTimings($stdout);
        $phpTimings = array_merge($phpTimings, $parsed);
    }
}

echo "\n";
echo Color::c(
    Color::CYAN,
    "  PHP Phase Complete: {$phpPassed} passed, {$phpFailed} failed, total " .
        fmtMs($phpTotalMs),
) . "\n\n";

// ═════════════════════════════════════════════════════════════════════════
//  PHASE 2: Run Node.js Benchmarks
// ═════════════════════════════════════════════════════════════════════════

echo Color::c(
    Color::BOLD . Color::MAGENTA,
    "  ╔══════════════════════════════════════════════════╗",
) . "\n";
echo Color::c(
    Color::BOLD . Color::MAGENTA,
    "  ║   PHASE 2: Running Node.js Benchmarks            ║",
) . "\n";
echo Color::c(
    Color::BOLD . Color::MAGENTA,
    "  ╚══════════════════════════════════════════════════╝",
) . "\n\n";

$nodeScript = $benchDir . DIRECTORY_SEPARATOR . "node_compare.mjs";

if (!file_exists($nodeScript)) {
    echo Color::c(
        Color::RED,
        "  ERROR: node_compare.mjs not found at {$nodeScript}",
    ) . "\n";
    exit(1);
}

echo Color::c(Color::YELLOW, "  [1/1] Running: node_compare.mjs") . "\n";

$cmd = escapeshellarg($nodeBin) . " " . escapeshellarg($nodeScript);
[$nodeStdout, $nodeStderr, $nodeExitCode, $nodeElapsedMs] = runCommand(
    $cmd,
    $benchDir,
    $timeout * 3,
);

$nodeTimings = [];
$nodeStatus = "PASS";

if ($nodeExitCode === -2) {
    echo Color::c(Color::RED, "    ✗ TIMEOUT after " . $timeout * 3 . "s") .
        "\n";
    $nodeStatus = "TIMEOUT";
} elseif ($nodeExitCode !== 0) {
    echo Color::c(Color::RED, "    ✗ FAILED (exit code {$nodeExitCode})") .
        "\n";
    if ($nodeStderr !== "") {
        $errLines = array_slice(explode("\n", trim($nodeStderr)), -5);
        foreach ($errLines as $line) {
            echo Color::c(Color::RED, "      {$line}") . "\n";
        }
    }
    $nodeStatus = "FAIL";
} else {
    echo Color::c(Color::GREEN, "    ✓ PASSED in " . fmtMs($nodeElapsedMs)) .
        "\n";
    $nodeTimings = parseTimings($nodeStdout);
}

echo "\n";
echo Color::c(
    Color::CYAN,
    "  Node.js Phase Complete: {$nodeStatus}, total " . fmtMs($nodeElapsedMs),
) . "\n\n";

// ═════════════════════════════════════════════════════════════════════════
//  PHASE 3: Side-by-Side Comparison
// ═════════════════════════════════════════════════════════════════════════

echo Color::c(
    Color::BOLD . Color::MAGENTA,
    "  ╔══════════════════════════════════════════════════╗",
) . "\n";
echo Color::c(
    Color::BOLD . Color::MAGENTA,
    "  ║   PHASE 3: PHP vs Node.js Comparison             ║",
) . "\n";
echo Color::c(
    Color::BOLD . Color::MAGENTA,
    "  ╚══════════════════════════════════════════════════╝",
) . "\n\n";

// ─── Known corresponding test names ──────────────────────────────────────
// Maps PHP benchmark test names to Node.js test names for direct comparison.
// Each entry: [category, php_bench_key_pattern, node_bench_key_pattern, description]

$comparisons = [
    // Bench 01: Concurrent Delay
    [
        "Concurrent Delay",
        "bench_01",
        "bench_01",
        [
            [
                "5×200ms delay",
                "5×200ms delay",
                "5×200ms delay",
                "5 tasks × 200ms",
            ],
            [
                "10×100ms delay",
                "10×100ms delay",
                "10×100ms delay",
                "10 tasks × 100ms",
            ],
            [
                "20×50ms delay",
                "20×50ms delay",
                "20×50ms delay",
                "20 tasks × 50ms",
            ],
            [
                "50×100ms delay",
                "50×100ms delay",
                "50×100ms delay",
                "50 tasks × 100ms",
            ],
            [
                "Nested 3×3×80ms",
                "Nested 3×3×80ms",
                "Nested 3×3×80ms",
                "Structured concurrency",
            ],
        ],
    ],

    // Bench 02: CPU-Bound
    [
        "CPU-Bound",
        "bench_02",
        "bench_02",
        [
            [
                "Single sumPrimes",
                "Single sumPrimes",
                "Single sumPrimes",
                "sumPrimes(10000)",
            ],
            [
                "5×sumPrimes(5000)",
                "5×sumPrimes(5000)",
                "5×sumPrimes(5000)",
                "5 independent computations",
            ],
            [
                "50×50 matmul",
                "50×50 matmul",
                "50×50 matmul",
                "Matrix multiplication",
            ],
            [
                "3×SHA-256 chains",
                "3×SHA-256 chains",
                "3×SHA-256 chains",
                "Hash chain 3×5000",
            ],
            [
                "Mixed CPU+I/O",
                "Mixed CPU+I/O",
                "Mixed CPU+I/O",
                "CPU work + delay overlap",
            ],
        ],
    ],

    // Bench 03: Channel Throughput
    [
        "Channel Throughput",
        "bench_03",
        "bench_03",
        [
            [
                "1×1 channel 1000",
                "1×1 channel 1000 ints",
                "Unbuf 1000 msgs",
                "1000 int messages",
            ],
            [
                "Fan-in 5→1",
                "Fan-in 5→1 (1000)",
                "Fan-in 900 msgs",
                "Multiple producers",
            ],
            [
                "Pipeline 3×500",
                "Pipeline 3×500",
                "Pipeline 500 msgs",
                "3-stage pipeline",
            ],
            [
                "Pipeline + I/O",
                "Pipeline + I/O",
                "",
                "Stage overlap with delays",
            ],
        ],
    ],

    // Bench 04: I/O-Bound
    [
        "I/O-Bound",
        "bench_04",
        "bench_04",
        [
            ["5×100ms network", "5×100ms network", "", "Simulated API calls"],
            [
                "Fan-out 10 ep",
                "Fan-out 10 endpoints",
                "",
                "10 endpoints 50-500ms",
            ],
            [
                "Write+notify",
                "Write+notify 8×50ms",
                "",
                "File write + notification",
            ],
        ],
    ],

    // Bench 05: Flow / Streams
    [
        "Flow / Streams",
        "bench_05",
        "bench_05",
        [
            ["Array vs Flow", "", "Array vs async gen", "2000 items baseline"],
            ["Transform", "", "Array.map vs async map", "1000 items ×3+1"],
        ],
    ],
];

// ─── Per-benchmark execution time comparison ─────────────────────────────

echo Color::c(
    Color::BOLD . Color::CYAN,
    "  ┌─────────────────────────────────────────────────────────────────────────────────────┐",
) . "\n";
echo Color::c(
    Color::BOLD . Color::CYAN,
    "  │  Benchmark Execution Time Comparison (total wall-clock time)                        │",
) . "\n";
echo Color::c(
    Color::BOLD . Color::CYAN,
    "  └─────────────────────────────────────────────────────────────────────────────────────┘",
) . "\n\n";

echo Color::c(
    Color::BOLD,
    sprintf(
        "  %-35s %15s %15s %15s",
        "Benchmark",
        "PHP (VOsaka)",
        "Node.js",
        "Ratio",
    ),
) . "\n";
echo "  " . str_repeat("─", 82) . "\n";

$phpTotalWall = 0.0;

foreach ($phpResults as $phpResult) {
    $basename = $phpResult["file"];
    $phpMs = $phpResult["ms"];
    $phpTotalWall += $phpMs;
    $status = $phpResult["status"];

    $statusIcon =
        $status === "PASS"
            ? Color::c(Color::GREEN, "✓")
            : Color::c(Color::RED, "✗");

    echo sprintf("  %-35s %15s  %s", $basename, fmtMs($phpMs), $statusIcon) .
        "\n";
}

echo "  " . str_repeat("─", 82) . "\n";

$wallRatio =
    $nodeElapsedMs > 0 ? sprintf("%.2fx", $phpTotalWall / $nodeElapsedMs) : "—";
echo Color::c(
    Color::BOLD,
    sprintf("  %-35s %15s", "PHP Total:", fmtMs($phpTotalWall)),
) . "\n";
echo Color::c(
    Color::BOLD,
    sprintf("  %-35s %15s", "Node.js Total:", fmtMs($nodeElapsedMs)),
) . "\n";
echo Color::c(
    Color::BOLD,
    sprintf("  %-35s %15s", "Wall Clock Ratio (PHP/Node):", $wallRatio),
) . "\n\n";

// Debug: show how many timings were parsed
echo Color::c(
    Color::DIM,
    "  Parsed " .
        count($phpTimings) .
        " PHP timing entries, " .
        count($nodeTimings) .
        " Node timing entries",
) . "\n\n";

// ─── Async speedup comparison (blocking vs async) ────────────────────────

echo Color::c(
    Color::BOLD . Color::CYAN,
    "  ┌─────────────────────────────────────────────────────────────────────────────────────┐",
) . "\n";
echo Color::c(
    Color::BOLD . Color::CYAN,
    "  │  Async Speedup: Blocking → Async (within each runtime)                             │",
) . "\n";
echo Color::c(
    Color::BOLD . Color::CYAN,
    "  └─────────────────────────────────────────────────────────────────────────────────────┘",
) . "\n\n";

echo "  Shows how much faster async is compared to blocking, within each runtime.\n";
echo "  Higher is better. Both runtimes should show similar ratios for I/O-bound tasks.\n\n";

echo Color::c(
    Color::BOLD,
    sprintf(
        "  %-35s %18s %18s  %s",
        "Test",
        "PHP Speedup",
        "Node Speedup",
        "Notes",
    ),
) . "\n";
echo "  " . str_repeat("─", 88) . "\n";

// Collect TOTAL entries for each bench
$phpTotals = [];
$nodeTotals = [];

foreach ($phpTimings as $key => $data) {
    if (str_ends_with($key, "/TOTAL")) {
        $benchId = str_replace("/TOTAL", "", $key);
        $phpTotals[$benchId] = $data;
    }
}

foreach ($nodeTimings as $key => $data) {
    if (str_ends_with($key, "/TOTAL")) {
        $benchId = str_replace("/TOTAL", "", $key);
        $nodeTotals[$benchId] = $data;
    }
}

$benchLabels = [
    "bench_01" => "Concurrent Delay",
    "bench_02" => "CPU-Bound",
    "bench_03" => "Channel Throughput",
    "bench_04" => "I/O-Bound",
    "bench_05" => "Flow / Streams",
    "bench_06" => "Pool vs Legacy vs File IPC",
];

foreach ($benchLabels as $benchId => $label) {
    $phpSpeedup = "—";
    $nodeSpeedup = "—";
    $notes = "";

    if (isset($phpTotals[$benchId])) {
        $b = $phpTotals[$benchId]["blocking"];
        $a = $phpTotals[$benchId]["async"];
        if ($a > 0) {
            $ratio = $b / $a;
            $arrow = $ratio >= 1.0 ? "▲" : "▼";
            $phpSpeedup = sprintf(
                "%.2fx %s",
                $ratio >= 1.0 ? $ratio : 1.0 / $ratio,
                $arrow,
            );
            if ($ratio >= 1.5) {
                $phpSpeedup = Color::c(Color::GREEN, $phpSpeedup);
            } elseif ($ratio >= 0.95) {
                $phpSpeedup = Color::c(Color::YELLOW, $phpSpeedup);
            } else {
                $phpSpeedup = Color::c(Color::RED, $phpSpeedup);
            }
        }
    }

    if (isset($nodeTotals[$benchId])) {
        $b = $nodeTotals[$benchId]["blocking"];
        $a = $nodeTotals[$benchId]["async"];
        if ($a > 0) {
            $ratio = $b / $a;
            $arrow = $ratio >= 1.0 ? "▲" : "▼";
            $nodeSpeedup = sprintf(
                "%.2fx %s",
                $ratio >= 1.0 ? $ratio : 1.0 / $ratio,
                $arrow,
            );
            if ($ratio >= 1.5) {
                $nodeSpeedup = Color::c(Color::GREEN, $nodeSpeedup);
            } elseif ($ratio >= 0.95) {
                $nodeSpeedup = Color::c(Color::YELLOW, $nodeSpeedup);
            } else {
                $nodeSpeedup = Color::c(Color::RED, $nodeSpeedup);
            }
        }
    }

    // Add context notes
    $notes = match ($benchId) {
        "bench_01" => "I/O overlap — higher is better",
        "bench_02" => "CPU work — async adds overhead",
        "bench_03" => "Channel — data structure cost",
        "bench_04" => "I/O — real-world pattern",
        "bench_05" => "Reactive streams",
        "bench_06" => "Pool vs Legacy vs File IPC",
        default => "",
    };

    echo sprintf(
        "  %-35s %18s %18s  %s",
        $label,
        $phpSpeedup,
        $nodeSpeedup,
        Color::c(Color::DIM, $notes),
    ) . "\n";
}

echo "\n";

// ─── Per-test async time comparison (PHP async vs Node async) ────────────

echo Color::c(
    Color::BOLD . Color::CYAN,
    "  ┌─────────────────────────────────────────────────────────────────────────────────────┐",
) . "\n";
echo Color::c(
    Color::BOLD . Color::CYAN,
    "  │  Absolute Async Time: PHP vs Node.js (lower is faster)                              │",
) . "\n";
echo Color::c(
    Color::BOLD . Color::CYAN,
    "  └─────────────────────────────────────────────────────────────────────────────────────┘",
) . "\n\n";

echo "  Compares the absolute async execution time between PHP VOsaka and Node.js.\n";
echo "  Lower time = faster. Ratio shows how many times slower PHP is than Node.\n\n";

echo Color::c(
    Color::BOLD,
    sprintf(
        "  %-35s %14s %14s %14s",
        "Test",
        "PHP Async",
        "Node Async",
        "PHP/Node",
    ),
) . "\n";
echo "  " . str_repeat("─", 80) . "\n";

// ─── Step 1: Collect TOTAL (per-benchmark) comparisons ───────────────
$matchedTotals = [];

foreach ($benchLabels as $benchId => $label) {
    $phpAsync = $phpTotals[$benchId]["async"] ?? null;
    $nodeAsync = $nodeTotals[$benchId]["async"] ?? null;

    if ($phpAsync !== null && $nodeAsync !== null) {
        $matchedTotals[] = [
            "label" => $label . " (Total)",
            "phpMs" => $phpAsync,
            "nodeMs" => $nodeAsync,
            "isTotals" => true,
        ];
    }
}

// ─── Step 2: Collect per-test comparisons via explicit name mapping ───
// We define known correspondences. This is more reliable than fuzzy matching
// because PHP and Node test names differ slightly.
$knownPairs = [
    // bench_01: Concurrent Delay
    ["bench_01", "5×200ms delay", "5×200ms delay"],
    ["bench_01", "10×100ms delay", "10×100ms delay"],
    ["bench_01", "20×50ms delay", "20×50ms delay"],
    ["bench_01", "Staggered delays", "Staggered delays"],
    ["bench_01", "50×100ms delay", "50×100ms delay"],
    ["bench_01", "Nested 3×3×80ms", "Nested 3×3×80ms"],
    ["bench_01", "100×1ms overhead", "100×1ms overhead"],
    // bench_02: CPU-Bound
    ["bench_02", "Single sumPrimes", "Single sumPrimes"],
    ["bench_02", "50×50 matmul", "50×50 matmul"],
    ["bench_02", "Mixed CPU+I/O", "Mixed CPU+I/O"],
    // bench_03: Channel Throughput
    ["bench_03", "Pipeline + I/O", null], // no node equivalent
];

// Also try fuzzy matching for anything not explicitly listed
$matchedTests = [];
$usedPhpKeys = [];
$usedNodeKeys = [];

// First: exact known pairs
foreach ($knownPairs as [$benchId, $phpName, $nodeName]) {
    $phpKey = $benchId . "/" . $phpName;
    if (!isset($phpTimings[$phpKey])) {
        continue;
    }

    if ($nodeName === null) {
        // PHP-only test — include with no Node data
        $matchedTests[] = [
            "label" => substr($phpName, 0, 35),
            "phpMs" => $phpTimings[$phpKey]["async"],
            "nodeMs" => null,
            "isTotals" => false,
        ];
        $usedPhpKeys[$phpKey] = true;
        continue;
    }

    $nodeKey = $benchId . "/" . $nodeName;
    if (!isset($nodeTimings[$nodeKey])) {
        continue;
    }

    $matchedTests[] = [
        "label" => substr($phpName, 0, 35),
        "phpMs" => $phpTimings[$phpKey]["async"],
        "nodeMs" => $nodeTimings[$nodeKey]["async"],
        "isTotals" => false,
    ];
    $usedPhpKeys[$phpKey] = true;
    $usedNodeKeys[$nodeKey] = true;
}

// Second: fuzzy match remaining PHP tests against remaining Node tests
foreach ($phpTimings as $phpKey => $phpData) {
    if (str_ends_with($phpKey, "/TOTAL")) {
        continue;
    }
    if (isset($usedPhpKeys[$phpKey])) {
        continue;
    }

    $phpBench = substr($phpKey, 0, 8);
    $phpTestName = preg_replace("/^bench_\d+\//", "", $phpKey);

    $bestMatch = null;
    $bestPct = 0;

    foreach ($nodeTimings as $nodeKey => $nodeData) {
        if (str_ends_with($nodeKey, "/TOTAL")) {
            continue;
        }
        if (isset($usedNodeKeys[$nodeKey])) {
            continue;
        }

        $nodeBench = substr($nodeKey, 0, 8);
        if ($phpBench !== $nodeBench) {
            continue;
        }

        $nodeTestName = preg_replace("/^bench_\d+\//", "", $nodeKey);
        similar_text(strtolower($phpTestName), strtolower($nodeTestName), $pct);

        if ($pct > $bestPct && $pct > 55) {
            $bestPct = $pct;
            $bestMatch = $nodeKey;
        }
    }

    if ($bestMatch !== null) {
        $matchedTests[] = [
            "label" => substr($phpTestName, 0, 35),
            "phpMs" => $phpData["async"],
            "nodeMs" => $nodeTimings[$bestMatch]["async"],
            "isTotals" => false,
        ];
        $usedPhpKeys[$phpKey] = true;
        $usedNodeKeys[$bestMatch] = true;
    }
}

// ─── Step 3: Print totals first, then per-test details ───────────────
// Print totals
foreach ($matchedTotals as $test) {
    $phpMs = $test["phpMs"];
    $nodeMs = $test["nodeMs"];
    $ratio = $nodeMs > 0 ? $phpMs / $nodeMs : 0;

    $ratioStr = formatRatio($ratio);

    echo Color::c(Color::BOLD, sprintf("  %-35s", $test["label"])) .
        sprintf(" %14s %14s ", fmtMs($phpMs), fmtMs($nodeMs)) .
        $ratioStr .
        "\n";
}

if (!empty($matchedTotals) && !empty($matchedTests)) {
    echo "  " . str_repeat("─", 80) . "\n";
    echo Color::c(Color::DIM, "  Per-test breakdown:") . "\n";
}

// Print per-test
$printed = [];
foreach ($matchedTests as $test) {
    $key = $test["label"];
    if (isset($printed[$key])) {
        continue;
    }
    $printed[$key] = true;

    $phpMs = $test["phpMs"];
    $nodeMs = $test["nodeMs"];

    if ($nodeMs !== null && $nodeMs > 0) {
        $ratio = $phpMs / $nodeMs;
        $ratioStr = formatRatio($ratio);
    } else {
        $ratioStr = Color::c(Color::DIM, "— (PHP only)");
    }

    echo sprintf(
        "    %-33s %14s %14s ",
        $key,
        fmtMs($phpMs),
        $nodeMs !== null ? fmtMs($nodeMs) : "—",
    ) .
        $ratioStr .
        "\n";
}

if (empty($matchedTotals) && empty($matchedTests)) {
    echo Color::c(
        Color::DIM,
        "  No matching tests found between PHP and Node.js outputs.",
    ) . "\n";
    echo Color::c(
        Color::DIM,
        "  PHP parsed " .
            count($phpTimings) .
            " entries, Node parsed " .
            count($nodeTimings) .
            " entries.",
    ) . "\n";
}

echo "\n";

// ═════════════════════════════════════════════════════════════════════════
//  PHASE 4: Summary & Interpretation
// ═════════════════════════════════════════════════════════════════════════

echo Color::c(
    Color::BOLD . Color::MAGENTA,
    "  ╔══════════════════════════════════════════════════════════════════════════════════════╗",
) . "\n";
echo Color::c(
    Color::BOLD . Color::MAGENTA,
    "  ║   SUMMARY & INTERPRETATION                                                         ║",
) . "\n";
echo Color::c(
    Color::BOLD . Color::MAGENTA,
    "  ╚══════════════════════════════════════════════════════════════════════════════════════╝",
) . "\n\n";

echo "  " . Color::c(Color::BOLD, "Environment:") . "\n";
echo "    PHP " . PHP_VERSION . " | " . PHP_OS . " | " . php_uname("m") . "\n";
echo "    Node.js " . $nodeVersion . " | V8 JIT\n";
echo "    Peak PHP memory: " . BenchHelper::peakMemoryUsage() . "\n\n";

echo "  " . Color::c(Color::BOLD, "Concurrency Speedup Comparison:") . "\n";
echo "    ┌──────────────────────────────────────────────────────────────────────────────┐\n";
echo "    │ CATEGORY              │ PHP VOsaka                  │ Node.js                │\n";
echo "    ├──────────────────────────────────────────────────────────────────────────────┤\n";
echo "    │ Concurrent Delay      │ ~5-50x speedup (Fibers)     │ ~5-50x speedup (libuv) │\n";
echo "    │ CPU-Bound             │ ~1x (overhead only)         │ ~1x (overhead only)    │\n";
echo "    │ Channel Throughput    │ ~2.5x (pipeline w/ I/O)     │ ~1.5-3x (async chan)   │\n";
echo "    │ I/O-Bound             │ ~5-10x (wait time overlap)  │ ~5-10x (libuv async)   │\n";
echo "    │ Flow / Streams        │ ~1-2x (reactive overhead)   │ ~1-2x (stream overhead)│\n";
echo "    │ IPC Channel (pool)    │ Pool 120-200x > File        │ MsgPort ~2-5x > File   │\n";
echo "    │ IPC Chan creation     │ Pool ~16ms vs Legacy ~600ms │ Worker ~50ms            │\n";
echo "    │ IPC Multi-channel     │ Pool 22-28x > Legacy(N proc)│ N Workers (N threads)  │\n";
echo "    └──────────────────────────────────────────────────────────────────────────────┘\n\n";

echo "  " . Color::c(Color::BOLD, "Absolute Performance (PHP vs Node):") . "\n";
echo "    ┌──────────────────────────────────────────────────────────────────────────────┐\n";
echo "    │ ASPECT                  │ RATIO (PHP / Node)           │ WHY                 │\n";
echo "    ├──────────────────────────────────────────────────────────────────────────────┤\n";
echo "    │ Raw CPU computation     │ PHP ~2-10x slower            │ V8 JIT vs interp    │\n";
echo "    │ Fiber/Promise overhead  │ PHP ~10-50x higher           │ Fiber ~12µs vs ~1µs │\n";
echo "    │ In-process channel      │ PHP ~10-50x slower           │ SplQueue vs V8 opt  │\n";
echo "    │ IPC per-msg (pool)      │ PHP ~2-10x slower            │ TCP+prefix vs clone │\n";
echo "    │ IPC chan creation (pool) │ PHP ~0.3x (pool FASTER)     │ Worker spawn ~50ms  │\n";
echo "    │ IPC chan creation (lgcy) │ PHP ~10x slower              │ proc_open vs Worker │\n";
echo "    │ Delay/sleep concurrency │ ~same speedup ratios         │ Both scheduler-bound│\n";
echo "    │ I/O overlap benefit     │ ~same speedup ratios         │ Wait time dominates │\n";
echo "    └──────────────────────────────────────────────────────────────────────────────┘\n\n";

echo "  " .
    Color::c(Color::BOLD . Color::GREEN, "Where PHP VOsaka Excels:") .
    "\n";
echo "    • I/O-bound workloads where wait time >> scheduling overhead\n";
echo "    • Applications already in PHP ecosystem (Laravel, Symfony, WordPress, etc.)\n";
echo "    • Scenarios with < 10,000 concurrent tasks (Fiber overhead acceptable)\n";
echo "    • Developer productivity: familiar PHP syntax + Kotlin-inspired coroutine API\n";
echo "    • Pool-based IPC channels: 120-200x faster than file-based, ~16ms/create vs ~600ms legacy\n";
echo "    • Multi-channel scaling: N channels share 1 process (pool mode) — 22-28x faster than N processes\n";
echo "    • Channel creation with pool is FASTER than Node.js Worker spawn (~16ms vs ~50ms)\n";
echo "    • Pipeline pattern with I/O: stages overlap for real speedup\n\n";

echo "  " .
    Color::c(
        Color::BOLD . Color::YELLOW,
        "Where Node.js Has Clear Advantage:",
    ) .
    "\n";
echo "    • CPU-intensive computation (V8 JIT compilation)\n";
echo "    • Per-message IPC throughput (structured clone ~10-50µs vs TCP ~100-130µs)\n";
echo "    • Very high concurrency (> 100k simultaneous tasks)\n";
echo "    • Streaming / real-time data processing\n";
echo "    • Native non-blocking I/O at OS level via libuv\n\n";

echo "  " . Color::c(Color::BOLD . Color::CYAN, "Key Takeaway:") . "\n";
echo "    VOsaka Foroutines brings Kotlin-style structured concurrency to PHP.\n";
echo "    For I/O-bound web applications (the majority of PHP use-cases),\n";
echo "    the concurrency SPEEDUP RATIOS are comparable to Node.js.\n";
echo "    With pool mode (default), IPC channel creation is now FASTER than\n";
echo "    Node.js Worker spawn (~16ms vs ~50ms), and N channels share 1 process.\n";
echo "    Per-message throughput is ~2-10x slower than Node.js structured clone,\n";
echo "    but the gap has narrowed dramatically from the old per-channel broker era.\n\n";

// ─── Final status ────────────────────────────────────────────────────────

$totalTime = $phpTotalWall + $nodeElapsedMs;

echo Color::c(Color::BOLD . Color::CYAN, $divider) . "\n";
echo sprintf(
    "  Total comparison time: %s (PHP: %s + Node: %s)\n",
    fmtMs($totalTime),
    fmtMs($phpTotalWall),
    fmtMs($nodeElapsedMs),
) . "";
echo Color::c(Color::BOLD . Color::CYAN, $divider) . "\n\n";

if ($phpFailed === 0 && $nodeStatus === "PASS") {
    echo Color::c(
        Color::BOLD . Color::GREEN,
        "  ✓ All benchmarks completed successfully!",
    ) . "\n";
} else {
    if ($phpFailed > 0) {
        echo Color::c(Color::RED, "  ✗ {$phpFailed} PHP benchmark(s) failed.") .
            "\n";
    }
    if ($nodeStatus !== "PASS") {
        echo Color::c(Color::RED, "  ✗ Node.js benchmark {$nodeStatus}.") .
            "\n";
    }
}

echo "\n";
exit($phpFailed > 0 || $nodeStatus !== "PASS" ? 1 : 0);
