<?php

declare(strict_types=1);

namespace comp;

/**
 * BenchHelper — Lightweight benchmark utility for VOsaka Foroutines comparison tests.
 *
 * Provides timing, memory tracking, result collection, and formatted output
 * for side-by-side comparison between blocking (traditional PHP) and
 * async (VOsaka Foroutines) approaches.
 */
final class BenchHelper
{
    // ─── ANSI colors for terminal output ─────────────────────────────
    private const C_RESET   = "\033[0m";
    private const C_BOLD    = "\033[1m";
    private const C_GREEN   = "\033[32m";
    private const C_YELLOW  = "\033[33m";
    private const C_CYAN    = "\033[36m";
    private const C_RED     = "\033[31m";
    private const C_MAGENTA = "\033[35m";
    private const C_DIM     = "\033[2m";

    /**
     * Collected benchmark results for the final summary table.
     * Each entry: ['name' => string, 'blocking' => float, 'async' => float, 'speedup' => float, 'note' => string]
     *
     * @var array<int, array{name: string, blocking: float, async: float, speedup: float, note: string}>
     */
    private static array $results = [];

    /**
     * Whether to use ANSI colors (auto-detected or overridden).
     */
    private static bool $useColor = true;

    // ─── Timing ──────────────────────────────────────────────────────

    /**
     * Returns the current high-resolution time in milliseconds.
     */
    public static function now(): float
    {
        return microtime(true) * 1000.0;
    }

    /**
     * Returns elapsed time in milliseconds since $startMs.
     */
    public static function elapsed(float $startMs): float
    {
        return self::now() - $startMs;
    }

    /**
     * Measure execution time of a callable. Returns [result, elapsedMs].
     *
     * @template T
     * @param callable(): T $fn
     * @return array{0: T, 1: float}
     */
    public static function measure(callable $fn): array
    {
        gc_collect_cycles();
        $start = self::now();
        $result = $fn();
        $elapsed = self::elapsed($start);
        return [$result, $elapsed];
    }

    /**
     * Measure execution time and peak memory delta of a callable.
     * Returns [result, elapsedMs, peakMemoryDeltaBytes].
     *
     * @template T
     * @param callable(): T $fn
     * @return array{0: T, 1: float, 2: int}
     */
    public static function measureWithMemory(callable $fn): array
    {
        gc_collect_cycles();
        $memBefore = memory_get_peak_usage(true);
        $start = self::now();
        $result = $fn();
        $elapsed = self::elapsed($start);
        $memAfter = memory_get_peak_usage(true);
        return [$result, $elapsed, $memAfter - $memBefore];
    }

    /**
     * Run a callable $iterations times, returning [avgMs, minMs, maxMs, totalMs].
     *
     * @param callable(): mixed $fn
     * @param int $iterations
     * @param int $warmup  Number of warmup iterations (not counted).
     * @return array{avg: float, min: float, max: float, total: float, iterations: int}
     */
    public static function benchmark(callable $fn, int $iterations = 10, int $warmup = 2): array
    {
        // Warmup
        for ($i = 0; $i < $warmup; $i++) {
            $fn();
        }
        gc_collect_cycles();

        $times = [];
        for ($i = 0; $i < $iterations; $i++) {
            $start = self::now();
            $fn();
            $times[] = self::elapsed($start);
        }

        $total = array_sum($times);
        return [
            'avg'        => $total / $iterations,
            'min'        => min($times),
            'max'        => max($times),
            'total'      => $total,
            'iterations' => $iterations,
        ];
    }

    // ─── Memory ──────────────────────────────────────────────────────

    /**
     * Returns current memory usage formatted as a human-readable string.
     */
    public static function memoryUsage(): string
    {
        return self::formatBytes(memory_get_usage(true));
    }

    /**
     * Returns peak memory usage formatted as a human-readable string.
     */
    public static function peakMemoryUsage(): string
    {
        return self::formatBytes(memory_get_peak_usage(true));
    }

    /**
     * Format bytes into a human-readable string (KB, MB, GB).
     */
    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 2) . ' KB';
        }
        if ($bytes < 1073741824) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        return round($bytes / 1073741824, 2) . ' GB';
    }

    // ─── Result collection ───────────────────────────────────────────

    /**
     * Record a comparison result between blocking and async approaches.
     *
     * @param string $name       Benchmark name.
     * @param float  $blockingMs Time taken by blocking approach (ms).
     * @param float  $asyncMs    Time taken by async approach (ms).
     * @param string $note       Optional note about the test.
     */
    public static function record(string $name, float $blockingMs, float $asyncMs, string $note = ''): void
    {
        $speedup = $blockingMs > 0 ? $blockingMs / $asyncMs : 0.0;
        self::$results[] = [
            'name'     => $name,
            'blocking' => $blockingMs,
            'async'    => $asyncMs,
            'speedup'  => $speedup,
            'note'     => $note,
        ];
    }

    /**
     * Get all recorded results.
     *
     * @return array<int, array{name: string, blocking: float, async: float, speedup: float, note: string}>
     */
    public static function getResults(): array
    {
        return self::$results;
    }

    /**
     * Clear all recorded results.
     */
    public static function clearResults(): void
    {
        self::$results = [];
    }

    // ─── Output / Display ────────────────────────────────────────────

    /**
     * Disable ANSI color output (e.g. for CI/CD or file redirection).
     */
    public static function disableColor(): void
    {
        self::$useColor = false;
    }

    /**
     * Enable ANSI color output.
     */
    public static function enableColor(): void
    {
        self::$useColor = true;
    }

    /**
     * Print a section header.
     */
    public static function header(string $title): void
    {
        $line = str_repeat('═', 70);
        echo "\n";
        echo self::color(self::C_CYAN, $line) . "\n";
        echo self::color(self::C_BOLD . self::C_CYAN, "  $title") . "\n";
        echo self::color(self::C_CYAN, $line) . "\n";
    }

    /**
     * Print a sub-section header.
     */
    public static function subHeader(string $title): void
    {
        echo "\n" . self::color(self::C_YELLOW, "  ▸ $title") . "\n";
        echo self::color(self::C_DIM, "  " . str_repeat('─', 60)) . "\n";
    }

    /**
     * Print an info line.
     */
    public static function info(string $message): void
    {
        echo "    " . $message . "\n";
    }

    /**
     * Print a timing result line.
     */
    public static function timing(string $label, float $ms): void
    {
        $formatted = self::formatMs($ms);
        echo "    " . str_pad($label, 35) . self::color(self::C_BOLD, $formatted) . "\n";
    }

    /**
     * Print a comparison line between blocking and async.
     */
    public static function comparison(string $label, float $blockingMs, float $asyncMs): void
    {
        $speedup = $blockingMs > 0 ? $blockingMs / $asyncMs : 0.0;
        $speedupStr = self::formatSpeedup($speedup);

        echo "    " . self::color(self::C_BOLD, str_pad($label, 30)) . "\n";
        echo "      Blocking:  " . self::color(self::C_RED, str_pad(self::formatMs($blockingMs), 15)) . "\n";
        echo "      Async:     " . self::color(self::C_GREEN, str_pad(self::formatMs($asyncMs), 15)) . "\n";
        echo "      Speedup:   " . $speedupStr . "\n";
    }

    /**
     * Print the full summary table of all recorded results.
     */
    public static function printSummary(): void
    {
        if (empty(self::$results)) {
            echo "\n  No benchmark results recorded.\n";
            return;
        }

        echo "\n";
        $line = str_repeat('═', 90);
        echo self::color(self::C_MAGENTA, $line) . "\n";
        echo self::color(self::C_BOLD . self::C_MAGENTA, "  BENCHMARK SUMMARY") . "\n";
        echo self::color(self::C_MAGENTA, $line) . "\n\n";

        // Table header
        echo self::color(self::C_BOLD, sprintf(
            "  %-30s %12s %12s %12s   %s",
            'Test', 'Blocking', 'Async', 'Speedup', 'Note'
        )) . "\n";
        echo "  " . str_repeat('─', 86) . "\n";

        $totalBlocking = 0.0;
        $totalAsync = 0.0;

        foreach (self::$results as $r) {
            $totalBlocking += $r['blocking'];
            $totalAsync += $r['async'];

            $speedupColor = self::getSpeedupColor($r['speedup']);

            echo sprintf(
                "  %-30s %12s %12s   %s   %s",
                self::truncate($r['name'], 30),
                self::formatMs($r['blocking']),
                self::formatMs($r['async']),
                self::color($speedupColor, str_pad(self::formatSpeedupShort($r['speedup']), 10)),
                self::color(self::C_DIM, $r['note'])
            ) . "\n";
        }

        echo "  " . str_repeat('─', 86) . "\n";

        $totalSpeedup = $totalBlocking > 0 ? $totalBlocking / $totalAsync : 0.0;
        $totalSpeedupColor = self::getSpeedupColor($totalSpeedup);

        echo self::color(self::C_BOLD, sprintf(
            "  %-30s %12s %12s   %s",
            'TOTAL',
            self::formatMs($totalBlocking),
            self::formatMs($totalAsync),
            self::color($totalSpeedupColor, self::formatSpeedupShort($totalSpeedup))
        )) . "\n";

        echo "\n";

        // Environment info
        echo self::color(self::C_DIM, "  Environment:") . "\n";
        echo self::color(self::C_DIM, "    PHP " . PHP_VERSION . " | " . PHP_OS . " | " . php_uname('m')) . "\n";
        echo self::color(self::C_DIM, "    Peak memory: " . self::peakMemoryUsage()) . "\n";
        echo self::color(self::C_DIM, "    Fibers: " . (class_exists('Fiber') ? 'yes' : 'no')) . "\n";
        echo self::color(self::C_DIM, "    pcntl_fork: " . (function_exists('pcntl_fork') ? 'yes' : 'no')) . "\n";
        echo self::color(self::C_DIM, "    shmop: " . (extension_loaded('shmop') ? 'yes' : 'no')) . "\n";
        echo "\n";
    }

    // ─── Formatting helpers ──────────────────────────────────────────

    /**
     * Format milliseconds into a human-readable string.
     */
    public static function formatMs(float $ms): string
    {
        if ($ms < 1.0) {
            return round($ms * 1000, 1) . ' µs';
        }
        if ($ms < 1000.0) {
            return round($ms, 2) . ' ms';
        }
        return round($ms / 1000.0, 3) . ' s';
    }

    /**
     * Format speedup ratio with color and arrow.
     */
    private static function formatSpeedup(float $speedup): string
    {
        if ($speedup >= 1.5) {
            return self::color(self::C_GREEN, sprintf("▲ %.2fx faster (async)", $speedup));
        }
        if ($speedup >= 0.95) {
            return self::color(self::C_YELLOW, sprintf("≈ %.2fx (roughly equal)", $speedup));
        }
        $inverse = $speedup > 0 ? 1.0 / $speedup : INF;
        return self::color(self::C_RED, sprintf("▼ %.2fx slower (async)", $inverse));
    }

    /**
     * Format speedup ratio for summary table (compact).
     */
    private static function formatSpeedupShort(float $speedup): string
    {
        if ($speedup >= 1.0) {
            return sprintf("%.2fx ▲", $speedup);
        }
        $inverse = $speedup > 0 ? 1.0 / $speedup : INF;
        return sprintf("%.2fx ▼", $inverse);
    }

    /**
     * Get ANSI color constant for a speedup value.
     */
    private static function getSpeedupColor(float $speedup): string
    {
        if ($speedup >= 1.5) {
            return self::C_GREEN;
        }
        if ($speedup >= 0.95) {
            return self::C_YELLOW;
        }
        return self::C_RED;
    }

    /**
     * Apply ANSI color to a string (if color is enabled).
     */
    private static function color(string $colorCode, string $text): string
    {
        if (!self::$useColor) {
            return $text;
        }
        return $colorCode . $text . self::C_RESET;
    }

    /**
     * Truncate a string to $maxLen characters, appending "…" if truncated.
     */
    private static function truncate(string $str, int $maxLen): string
    {
        if (mb_strlen($str) <= $maxLen) {
            return $str;
        }
        return mb_substr($str, 0, $maxLen - 1) . '…';
    }

    // ─── Utilities for benchmark scripts ─────────────────────────────

    /**
     * Generate a random string of given length.
     */
    public static function randomString(int $length = 32): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $result = '';
        $max = strlen($chars) - 1;
        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[mt_rand(0, $max)];
        }
        return $result;
    }

    /**
     * Create a temporary file with random content, returning its path.
     * Caller is responsible for cleanup via unlink().
     */
    public static function createTempFile(int $sizeBytes = 4096): string
    {
        $path = tempnam(sys_get_temp_dir(), 'vosaka_bench_');
        file_put_contents($path, self::randomString($sizeBytes));
        return $path;
    }

    /**
     * Clean up an array of file paths (best-effort, ignores errors).
     *
     * @param string[] $paths
     */
    public static function cleanupFiles(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * Print a progress dot (for long-running benchmarks).
     */
    public static function dot(): void
    {
        echo '.';
    }

    /**
     * Assert a condition, printing PASS or FAIL.
     */
    public static function assert(string $label, bool $condition): void
    {
        if ($condition) {
            echo "    " . self::color(self::C_GREEN, "✓ PASS") . " $label\n";
        } else {
            echo "    " . self::color(self::C_RED, "✗ FAIL") . " $label\n";
        }
    }

    /**
     * Simple separator line.
     */
    public static function separator(): void
    {
        echo self::color(self::C_DIM, "  " . str_repeat('─', 60)) . "\n";
    }
}
