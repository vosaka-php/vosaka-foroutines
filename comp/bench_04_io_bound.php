<?php

/**
 * Benchmark 04: I/O-Bound Tasks
 *
 * Compares blocking sequential file I/O and simulated network operations
 * vs VOsaka async concurrent approaches.
 *
 * Scenarios:
 *
 *   1.  Sequential file writes vs concurrent Launch file writes
 *   2.  Sequential file reads vs concurrent Launch file reads
 *   3.  Mixed read/write workload — sequential vs concurrent
 *   4.  Simulated network latency — sequential usleep vs concurrent Delay
 *   5.  Simulated API fan-out — 10 "endpoints" each with latency
 *   6.  Simulated API fan-out with Async/Await collecting results
 *   7.  File write + simulated network send pipeline
 *   8.  Large file write — single big write vs chunked async writes
 *   9.  Dispatchers::IO — offload blocking file_get_contents to child process
 *  10.  Simulated database queries — N queries with varying latency
 *  11.  Mixed I/O sizes — small + medium + large file operations
 *  12.  Throughput scaling — varying concurrent I/O task count
 *  13.  AsyncIO file writes — AsyncIO::filePutContents()->await()
 *  14.  AsyncIO file reads  — AsyncIO::fileGetContents()->await()
 *  15.  AsyncIO pipeline    — write → read → socket pair via ->await()
 *
 * Why this matters:
 *   I/O-bound workloads are where async truly shines. While one task waits
 *   for disk or network, other tasks can proceed. In Node.js, ALL I/O is
 *   non-blocking by default (libuv handles it). In PHP, standard I/O is
 *   blocking, so VOsaka must either:
 *     a) Use Delay::new() to cooperatively yield during simulated waits
 *     b) Use AsyncIO::method()->await() for real stream-based non-blocking I/O
 *     c) Use Dispatchers::IO to offload blocking calls to child processes
 *
 *   This benchmark covers (a), (b), and (c). AsyncIO file operations use
 *   cooperative yielding between chunks via the deferred ->await() pattern,
 *   and AsyncIO also provides createSocketPair() for in-process IPC.
 *
 * Expected results:
 *   - Simulated latency tests: async ~Nx faster (delays overlap)
 *   - Real file I/O in DEFAULT dispatcher: async ≈ blocking (disk is sequential)
 *   - Dispatchers::IO file I/O: overhead from process spawn, but parallel disk access
 *   - Pipeline patterns: async wins when stages have independent I/O
 *   - Node.js comparison: libuv uses a 4-thread pool for file I/O, giving true
 *     parallelism for disk operations. VOsaka DEFAULT dispatcher runs file I/O
 *     sequentially in fibers (no real parallelism for blocking calls).
 */

require __DIR__ . "/../vendor/autoload.php";
require __DIR__ . "/BenchHelper.php";

use comp\BenchHelper;
use vosaka\foroutines\Async;
use vosaka\foroutines\AsyncIO;
use vosaka\foroutines\Delay;
use vosaka\foroutines\Dispatchers;
use vosaka\foroutines\Launch;
use vosaka\foroutines\Pause;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Thread;

use function vosaka\foroutines\main;

// ─── Helper functions ────────────────────────────────────────────────────

/**
 * Generate a temporary file path for benchmarking.
 */
function benchTempPath(string $prefix, int $index): string
{
    return sys_get_temp_dir() .
        DIRECTORY_SEPARATOR .
        "vosaka_bench04_{$prefix}_{$index}.tmp";
}

/**
 * Clean up all temp files matching a prefix pattern.
 */
function cleanupBenchFiles(string $prefix, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        $path = benchTempPath($prefix, $i);
        if (is_file($path)) {
            @unlink($path);
        }
    }
    // Also clean up any large file test artifacts
    $largePath =
        sys_get_temp_dir() .
        DIRECTORY_SEPARATOR .
        "vosaka_bench04_{$prefix}_large.tmp";
    if (is_file($largePath)) {
        @unlink($largePath);
    }
}

/**
 * Simulate a network request with a fixed latency (via usleep for blocking,
 * or Delay::new for async).
 *
 * @return array Simulated response data
 */
function simulateBlockingApiCall(int $endpointId, int $latencyMs): array
{
    usleep($latencyMs * 1000);
    return [
        "endpoint" => $endpointId,
        "status" => 200,
        "data" => "response_from_endpoint_{$endpointId}",
        "latency" => $latencyMs,
    ];
}

/**
 * Generate random content of a given size in bytes.
 */
function generateContent(int $sizeBytes): string
{
    // Fast generation: repeat a pattern instead of random chars
    $pattern =
        "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789\n";
    $patLen = strlen($pattern);
    $result = str_repeat($pattern, intdiv($sizeBytes, $patLen));
    $remainder = $sizeBytes - strlen($result);
    if ($remainder > 0) {
        $result .= substr($pattern, 0, $remainder);
    }
    return $result;
}

// ─── Main benchmark ──────────────────────────────────────────────────────

main(function () {
    // Ensure Launch static state is initialized before any RunBlocking call
    // (RunBlocking::new phase 2 accesses Launch::$queue directly)
    Launch::getInstance();

    BenchHelper::header("Benchmark 04: I/O-Bound Tasks");
    BenchHelper::info("Blocking sequential I/O vs VOsaka async concurrent I/O");
    BenchHelper::info("PHP " . PHP_VERSION . " | " . PHP_OS);
    BenchHelper::separator();

    // ═════════════════════════════════════════════════════════════════
    // Test 1: Sequential file writes vs concurrent Launch file writes
    //
    // Write 10 small files (1KB each).
    // Blocking: write one after another.
    // Async: Launch all writes concurrently (still sequential on disk
    //        in DEFAULT dispatcher, but overlaps with Delay if present).
    //
    // NOTE: Since PHP file_put_contents is blocking even inside a Fiber,
    // the async version won't be faster for pure file I/O in DEFAULT.
    // This test establishes the baseline overhead.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 1: Sequential vs concurrent file writes — 10 × 1KB",
    );

    $fileCount = 10;
    $fileSize = 1024;
    $content = generateContent($fileSize);
    $prefix = "write1";

    cleanupBenchFiles($prefix, $fileCount);

    // --- Blocking ---
    [, $blockingMs] = BenchHelper::measure(function () use (
        $fileCount,
        $content,
        $prefix,
    ) {
        for ($i = 0; $i < $fileCount; $i++) {
            file_put_contents(benchTempPath($prefix, $i), $content);
        }
    });
    BenchHelper::timing("Blocking (sequential writes):", $blockingMs);
    cleanupBenchFiles($prefix, $fileCount);

    // --- Async (DEFAULT dispatcher — still blocking file I/O) ---
    [, $asyncMs] = BenchHelper::measure(function () use (
        $fileCount,
        $content,
        $prefix,
    ) {
        RunBlocking::new(function () use ($fileCount, $content, $prefix) {
            for ($i = 0; $i < $fileCount; $i++) {
                Launch::new(function () use ($i, $content, $prefix) {
                    file_put_contents(benchTempPath($prefix, $i), $content);
                });
            }
            Thread::await();
        });
        Thread::await();
    });
    BenchHelper::timing("Async (Launch file writes):", $asyncMs);
    cleanupBenchFiles($prefix, $fileCount);

    BenchHelper::comparison("10×1KB file writes", $blockingMs, $asyncMs);
    BenchHelper::info(
        "    NOTE: file_put_contents is blocking in DEFAULT dispatcher",
    );
    BenchHelper::info(
        "    Async overhead = fiber creation + scheduler, no I/O overlap",
    );
    BenchHelper::record(
        "10×1KB writes",
        $blockingMs,
        $asyncMs,
        "blocking I/O in fibers",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 2: Sequential file reads vs concurrent Launch file reads
    //
    // First create 10 files, then read them all.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 2: Sequential vs concurrent file reads — 10 × 1KB",
    );

    $prefix = "read2";
    // Setup: create files
    for ($i = 0; $i < $fileCount; $i++) {
        file_put_contents(benchTempPath($prefix, $i), $content);
    }

    // --- Blocking ---
    [$blockingResults, $blockingMs] = BenchHelper::measure(function () use (
        $fileCount,
        $prefix,
    ) {
        $data = [];
        for ($i = 0; $i < $fileCount; $i++) {
            $data[] = file_get_contents(benchTempPath($prefix, $i));
        }
        return count($data);
    });
    BenchHelper::timing("Blocking (sequential reads):", $blockingMs);

    // --- Async ---
    [$asyncResults, $asyncMs] = BenchHelper::measure(function () use (
        $fileCount,
        $prefix,
    ) {
        $data = [];
        RunBlocking::new(function () use ($fileCount, $prefix, &$data) {
            for ($i = 0; $i < $fileCount; $i++) {
                Launch::new(function () use ($i, $prefix, &$data) {
                    $data[$i] = file_get_contents(benchTempPath($prefix, $i));
                });
            }
            Thread::await();
        });
        Thread::await();
        return count($data);
    });
    BenchHelper::timing("Async (Launch file reads):", $asyncMs);
    cleanupBenchFiles($prefix, $fileCount);

    BenchHelper::comparison("10×1KB file reads", $blockingMs, $asyncMs);
    BenchHelper::assert("All files read", $asyncResults === $fileCount);
    BenchHelper::record(
        "10×1KB reads",
        $blockingMs,
        $asyncMs,
        "blocking I/O in fibers",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 3: Mixed read/write workload
    //
    // 5 writes + 5 reads interleaved.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader("Test 3: Mixed read/write — 5 writes + 5 reads");

    $prefix = "mixed3";
    $mixCount = 5;
    // Pre-create files for reading
    for ($i = 0; $i < $mixCount; $i++) {
        file_put_contents(benchTempPath($prefix . "_r", $i), $content);
    }

    // --- Blocking ---
    [, $blockingMs] = BenchHelper::measure(function () use (
        $mixCount,
        $content,
        $prefix,
    ) {
        for ($i = 0; $i < $mixCount; $i++) {
            file_put_contents(benchTempPath($prefix . "_w", $i), $content);
            file_get_contents(benchTempPath($prefix . "_r", $i));
        }
    });
    BenchHelper::timing("Blocking (sequential mixed):", $blockingMs);
    cleanupBenchFiles($prefix . "_w", $mixCount);

    // --- Async ---
    [, $asyncMs] = BenchHelper::measure(function () use (
        $mixCount,
        $content,
        $prefix,
    ) {
        RunBlocking::new(function () use ($mixCount, $content, $prefix) {
            for ($i = 0; $i < $mixCount; $i++) {
                Launch::new(function () use ($i, $content, $prefix) {
                    file_put_contents(
                        benchTempPath($prefix . "_w", $i),
                        $content,
                    );
                });
                Launch::new(function () use ($i, $prefix) {
                    file_get_contents(benchTempPath($prefix . "_r", $i));
                });
            }
            Thread::await();
        });
        Thread::await();
    });
    BenchHelper::timing("Async (concurrent mixed):", $asyncMs);
    cleanupBenchFiles($prefix . "_w", $mixCount);
    cleanupBenchFiles($prefix . "_r", $mixCount);

    BenchHelper::comparison("Mixed 5W+5R", $blockingMs, $asyncMs);
    BenchHelper::record(
        "Mixed 5W+5R",
        $blockingMs,
        $asyncMs,
        "interleaved file I/O",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 4: Simulated network latency — 5 "requests" × 100ms each
    //
    // This is where async REALLY shines: while one "request" waits,
    // others can proceed. Total blocking = 500ms, async ≈ 100ms.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 4: Simulated network — 5 requests × 100ms latency",
    );

    $requestCount = 5;
    $latencyMs = 100;

    // --- Blocking ---
    [, $blockingMs] = BenchHelper::measure(function () use (
        $requestCount,
        $latencyMs,
    ) {
        $responses = [];
        for ($i = 0; $i < $requestCount; $i++) {
            $responses[] = simulateBlockingApiCall($i, $latencyMs);
        }
        return $responses;
    });
    BenchHelper::timing("Blocking (sequential requests):", $blockingMs);

    // --- Async ---
    [$asyncResponses, $asyncMs] = BenchHelper::measure(function () use (
        $requestCount,
        $latencyMs,
    ) {
        $responses = [];
        RunBlocking::new(function () use (
            $requestCount,
            $latencyMs,
            &$responses,
        ) {
            for ($i = 0; $i < $requestCount; $i++) {
                Launch::new(function () use ($i, $latencyMs, &$responses) {
                    Delay::new($latencyMs);
                    $responses[$i] = [
                        "endpoint" => $i,
                        "status" => 200,
                        "data" => "response_from_endpoint_{$i}",
                        "latency" => $latencyMs,
                    ];
                });
            }
            Thread::await();
        });
        Thread::await();
        ksort($responses);
        return array_values($responses);
    });
    BenchHelper::timing("Async (concurrent Delay):", $asyncMs);

    BenchHelper::comparison("5×100ms network sim", $blockingMs, $asyncMs);
    BenchHelper::assert(
        "All responses collected",
        count($asyncResponses) === $requestCount,
    );
    BenchHelper::record(
        "5×100ms network",
        $blockingMs,
        $asyncMs,
        "simulated latency",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 5: Simulated API fan-out — 10 endpoints, varying latency
    //
    // Each endpoint has a different response time (50ms..500ms).
    // Blocking: total = sum(latencies) ≈ 2750ms
    // Async: total ≈ max(latencies) = 500ms
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 5: API fan-out — 10 endpoints, 50-500ms latency",
    );

    $endpoints = [
        ["id" => 0, "latency" => 50],
        ["id" => 1, "latency" => 100],
        ["id" => 2, "latency" => 150],
        ["id" => 3, "latency" => 200],
        ["id" => 4, "latency" => 250],
        ["id" => 5, "latency" => 300],
        ["id" => 6, "latency" => 350],
        ["id" => 7, "latency" => 400],
        ["id" => 8, "latency" => 450],
        ["id" => 9, "latency" => 500],
    ];

    // --- Blocking ---
    [, $blockingMs] = BenchHelper::measure(function () use ($endpoints) {
        $results = [];
        foreach ($endpoints as $ep) {
            $results[] = simulateBlockingApiCall($ep["id"], $ep["latency"]);
        }
        return $results;
    });
    BenchHelper::timing("Blocking (sequential fan-out):", $blockingMs);

    // --- Async ---
    [$asyncResults, $asyncMs] = BenchHelper::measure(function () use (
        $endpoints,
    ) {
        $results = [];
        RunBlocking::new(function () use ($endpoints, &$results) {
            foreach ($endpoints as $ep) {
                Launch::new(function () use ($ep, &$results) {
                    Delay::new($ep["latency"]);
                    $results[$ep["id"]] = [
                        "endpoint" => $ep["id"],
                        "status" => 200,
                        "data" => "response_from_endpoint_{$ep["id"]}",
                        "latency" => $ep["latency"],
                    ];
                });
            }
            Thread::await();
        });
        Thread::await();
        ksort($results);
        return array_values($results);
    });
    BenchHelper::timing("Async (concurrent fan-out):", $asyncMs);

    BenchHelper::comparison("10-endpoint fan-out", $blockingMs, $asyncMs);
    BenchHelper::assert(
        "All 10 endpoints responded",
        count($asyncResults) === count($endpoints),
    );
    BenchHelper::record(
        "Fan-out 10 endpoints",
        $blockingMs,
        $asyncMs,
        "50-500ms varied latency",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 6: Async/Await fan-out with result collection
    //
    // Same as Test 5 but uses Async::new + ->await() to collect results.
    // This tests the Async/Await overhead specifically.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader("Test 6: Async/Await fan-out — 8 endpoints × 100ms");

    $endpointCount = 8;
    $uniformLatency = 100;

    // --- Blocking ---
    [, $blockingMs] = BenchHelper::measure(function () use (
        $endpointCount,
        $uniformLatency,
    ) {
        $results = [];
        for ($i = 0; $i < $endpointCount; $i++) {
            usleep($uniformLatency * 1000);
            $results[] = "result_{$i}";
        }
        return $results;
    });
    BenchHelper::timing("Blocking (sequential):", $blockingMs);

    // --- Async/Await ---
    [$asyncResults, $asyncMs] = BenchHelper::measure(function () use (
        $endpointCount,
        $uniformLatency,
    ) {
        $results = [];
        RunBlocking::new(function () use (
            $endpointCount,
            $uniformLatency,
            &$results,
        ) {
            $handles = [];
            for ($i = 0; $i < $endpointCount; $i++) {
                $handles[] = Async::new(function () use ($i, $uniformLatency) {
                    Delay::new($uniformLatency);
                    return "result_{$i}";
                });
            }
            // Await all results
            foreach ($handles as $h) {
                $results[] = $h->await();
            }
        });
        Thread::await();
        return $results;
    });
    BenchHelper::timing("Async (Async/Await):", $asyncMs);

    BenchHelper::comparison("8×100ms Async/Await", $blockingMs, $asyncMs);
    BenchHelper::assert(
        "All results collected",
        count($asyncResults) === $endpointCount,
    );
    BenchHelper::assert(
        "First result correct",
        isset($asyncResults[0]) && $asyncResults[0] === "result_0",
    );
    BenchHelper::record(
        "Async/Await 8×100ms",
        $blockingMs,
        $asyncMs,
        "Async::new + await",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 7: File write + simulated network send pipeline
    //
    // Each task: write a file (real I/O) + "send" notification (sim 50ms).
    // 8 tasks total.
    //
    // Blocking: file_write + usleep(50ms) × 8 = ~400ms+ of waiting
    // Async: file writes interleave with delays → only ~50ms of waiting
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 7: File write + network send — 8 tasks × (write + 50ms)",
    );

    $taskCount = 8;
    $notifyLatencyMs = 50;
    $prefix = "pipeline7";
    $smallContent = generateContent(512);

    cleanupBenchFiles($prefix, $taskCount);

    // --- Blocking ---
    [, $blockingMs] = BenchHelper::measure(function () use (
        $taskCount,
        $notifyLatencyMs,
        $prefix,
        $smallContent,
    ) {
        for ($i = 0; $i < $taskCount; $i++) {
            file_put_contents(benchTempPath($prefix, $i), $smallContent);
            usleep($notifyLatencyMs * 1000); // simulate sending notification
        }
    });
    BenchHelper::timing("Blocking (write + sleep):", $blockingMs);
    cleanupBenchFiles($prefix, $taskCount);

    // --- Async ---
    [, $asyncMs] = BenchHelper::measure(function () use (
        $taskCount,
        $notifyLatencyMs,
        $prefix,
        $smallContent,
    ) {
        RunBlocking::new(function () use (
            $taskCount,
            $notifyLatencyMs,
            $prefix,
            $smallContent,
        ) {
            for ($i = 0; $i < $taskCount; $i++) {
                Launch::new(function () use (
                    $i,
                    $notifyLatencyMs,
                    $prefix,
                    $smallContent,
                ) {
                    file_put_contents(
                        benchTempPath($prefix, $i),
                        $smallContent,
                    );
                    Delay::new($notifyLatencyMs); // simulate sending notification
                });
            }
            Thread::await();
        });
        Thread::await();
    });
    BenchHelper::timing("Async (concurrent write+delay):", $asyncMs);
    cleanupBenchFiles($prefix, $taskCount);

    BenchHelper::comparison("8×(write+50ms)", $blockingMs, $asyncMs);
    BenchHelper::record(
        "Write+notify 8×50ms",
        $blockingMs,
        $asyncMs,
        "real I/O + sim latency",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 8: Large file write — single 256KB write vs chunked
    //
    // Tests if async chunked writing provides any benefit for large
    // single-file operations. (Spoiler: not really for local disk,
    // but shows the pattern for network streams.)
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 8: Large file write — 256KB single vs chunked",
    );

    $prefix = "large8";
    $largePath =
        sys_get_temp_dir() .
        DIRECTORY_SEPARATOR .
        "vosaka_bench04_{$prefix}_large.tmp";
    $largeContent = generateContent(256 * 1024); // 256KB
    $chunkSize = 16 * 1024; // 16KB chunks

    // --- Blocking: single write ---
    [, $blockingMs] = BenchHelper::measure(function () use (
        $largePath,
        $largeContent,
    ) {
        file_put_contents($largePath, $largeContent);
    });
    BenchHelper::timing("Blocking (single 256KB write):", $blockingMs);
    @unlink($largePath);

    // --- Async: chunked writes within a fiber ---
    [, $asyncMs] = BenchHelper::measure(function () use (
        $largePath,
        $largeContent,
        $chunkSize,
    ) {
        RunBlocking::new(function () use (
            $largePath,
            $largeContent,
            $chunkSize,
        ) {
            $async = Async::new(function () use (
                $largePath,
                $largeContent,
                $chunkSize,
            ) {
                $handle = fopen($largePath, "wb");
                $offset = 0;
                $totalLen = strlen($largeContent);
                while ($offset < $totalLen) {
                    $chunk = substr($largeContent, $offset, $chunkSize);
                    fwrite($handle, $chunk);
                    $offset += $chunkSize;
                    Pause::new(); // yield to other fibers between chunks
                }
                fclose($handle);
                return $totalLen;
            });
            $async->await();
        });
        Thread::await();
    });
    BenchHelper::timing("Async (chunked 16KB writes):", $asyncMs);

    // Verify
    if (is_file($largePath)) {
        $writtenSize = filesize($largePath);
        BenchHelper::assert(
            "Large file size correct",
            $writtenSize === strlen($largeContent),
        );
        @unlink($largePath);
    }

    BenchHelper::comparison("256KB file write", $blockingMs, $asyncMs);
    BenchHelper::info("    Chunked writing adds overhead for single-file ops");
    BenchHelper::info(
        "    Benefit appears when other fibers run between chunks",
    );
    BenchHelper::record(
        "256KB write",
        $blockingMs,
        $asyncMs,
        "single vs chunked",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 9: Dispatchers::IO — offload file I/O to child process
    //
    // Write 3 files using Dispatchers::IO (child process) vs direct.
    // Shows the process spawn + serialization overhead.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 9: Dispatchers::IO — 3 file writes in child processes",
    );

    $ioCount = 3;
    $prefix = "iodispatch9";
    $ioContent = generateContent(2048);

    cleanupBenchFiles($prefix . "_b", $ioCount);
    cleanupBenchFiles($prefix . "_a", $ioCount);

    // --- Blocking ---
    [, $blockingMs] = BenchHelper::measure(function () use (
        $ioCount,
        $ioContent,
        $prefix,
    ) {
        for ($i = 0; $i < $ioCount; $i++) {
            file_put_contents(benchTempPath($prefix . "_b", $i), $ioContent);
        }
    });
    BenchHelper::timing("Blocking (direct writes):", $blockingMs);
    cleanupBenchFiles($prefix . "_b", $ioCount);

    // --- Dispatchers::IO ---
    [, $ioMs] = BenchHelper::measure(function () use (
        $ioCount,
        $ioContent,
        $prefix,
    ) {
        RunBlocking::new(function () use ($ioCount, $ioContent, $prefix) {
            $handles = [];
            for ($i = 0; $i < $ioCount; $i++) {
                $handles[] = Async::new(function () use (
                    $i,
                    $ioContent,
                    $prefix,
                ) {
                    $path = benchTempPath($prefix . "_a", $i);
                    file_put_contents($path, $ioContent);
                    return filesize($path);
                }, Dispatchers::IO);
            }
            foreach ($handles as $h) {
                $h->await();
            }
        });
        Thread::await();
    });
    BenchHelper::timing("Dispatchers::IO (child procs):", $ioMs);
    cleanupBenchFiles($prefix . "_a", $ioCount);

    BenchHelper::comparison("IO dispatch 3 writes", $blockingMs, $ioMs);
    BenchHelper::info(
        "    Process overhead: ~" . BenchHelper::formatMs($ioMs - $blockingMs),
    );
    BenchHelper::info("    On Linux with pcntl_fork: ~1-5ms overhead per task");
    BenchHelper::info(
        "    On Windows (symfony/process): ~50-200ms overhead per task",
    );
    BenchHelper::record(
        "IO dispatch 3 writes",
        $blockingMs,
        $ioMs,
        "child process overhead",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 10: Simulated database queries — 8 queries, 30-150ms each
    //
    // Simulates a typical web request that needs to fetch data from
    // multiple database tables / services with varying latency.
    //
    // Blocking: sum(latencies) ≈ 720ms
    // Async: max(latencies) ≈ 150ms
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 10: Simulated DB queries — 8 queries × 30-150ms",
    );

    $queries = [
        ["name" => "users", "latency" => 30],
        ["name" => "orders", "latency" => 80],
        ["name" => "products", "latency" => 50],
        ["name" => "reviews", "latency" => 120],
        ["name" => "inventory", "latency" => 40],
        ["name" => "payments", "latency" => 150],
        ["name" => "shipping", "latency" => 90],
        ["name" => "analytics", "latency" => 60],
    ];

    $totalBlockingLatency = array_sum(array_column($queries, "latency"));
    $maxLatency = max(array_column($queries, "latency"));

    BenchHelper::info(
        "    Total sequential latency: {$totalBlockingLatency}ms",
    );
    BenchHelper::info("    Theoretical async minimum: {$maxLatency}ms");

    // --- Blocking ---
    [$blockingResults, $blockingMs] = BenchHelper::measure(function () use (
        $queries,
    ) {
        $results = [];
        foreach ($queries as $q) {
            usleep($q["latency"] * 1000);
            $results[$q["name"]] = [
                "table" => $q["name"],
                "rows" => mt_rand(1, 100),
                "time" => $q["latency"],
            ];
        }
        return $results;
    });
    BenchHelper::timing("Blocking (sequential queries):", $blockingMs);

    // --- Async ---
    [$asyncResults, $asyncMs] = BenchHelper::measure(function () use (
        $queries,
    ) {
        $results = [];
        RunBlocking::new(function () use ($queries, &$results) {
            $handles = [];
            foreach ($queries as $q) {
                $handles[$q["name"]] = Async::new(function () use ($q) {
                    Delay::new($q["latency"]);
                    return [
                        "table" => $q["name"],
                        "rows" => mt_rand(1, 100),
                        "time" => $q["latency"],
                    ];
                });
            }
            foreach ($handles as $name => $h) {
                $results[$name] = $h->await();
            }
        });
        Thread::await();
        return $results;
    });
    BenchHelper::timing("Async (concurrent queries):", $asyncMs);

    BenchHelper::comparison("8 DB queries", $blockingMs, $asyncMs);
    BenchHelper::assert(
        "All query results",
        count($asyncResults) === count($queries),
    );
    BenchHelper::record(
        "8 DB queries",
        $blockingMs,
        $asyncMs,
        "30-150ms sim queries",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 11: Mixed I/O sizes — small + medium + large file operations
    //
    // 3 small writes (256B) + 3 medium writes (4KB) + 3 large writes (32KB)
    // Each followed by a simulated 40ms "sync" delay.
    //
    // Blocking: 9 × (write + 40ms) ≈ 360ms+
    // Async: all writes + ~40ms overlap ≈ 40ms+
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 11: Mixed I/O sizes — 9 writes + 40ms notify each",
    );

    $prefix = "mixed11";
    $ioTasks = [
        // small
        ["size" => 256, "id" => 0],
        ["size" => 256, "id" => 1],
        ["size" => 256, "id" => 2],
        // medium
        ["size" => 4096, "id" => 3],
        ["size" => 4096, "id" => 4],
        ["size" => 4096, "id" => 5],
        // large
        ["size" => 32768, "id" => 6],
        ["size" => 32768, "id" => 7],
        ["size" => 32768, "id" => 8],
    ];
    $notifyMs = 40;

    cleanupBenchFiles($prefix, count($ioTasks));

    // --- Blocking ---
    [, $blockingMs] = BenchHelper::measure(function () use (
        $ioTasks,
        $notifyMs,
        $prefix,
    ) {
        foreach ($ioTasks as $task) {
            $data = generateContent($task["size"]);
            file_put_contents(benchTempPath($prefix, $task["id"]), $data);
            usleep($notifyMs * 1000);
        }
    });
    BenchHelper::timing("Blocking (sequential):", $blockingMs);
    cleanupBenchFiles($prefix, count($ioTasks));

    // --- Async ---
    [, $asyncMs] = BenchHelper::measure(function () use (
        $ioTasks,
        $notifyMs,
        $prefix,
    ) {
        RunBlocking::new(function () use ($ioTasks, $notifyMs, $prefix) {
            foreach ($ioTasks as $task) {
                Launch::new(function () use ($task, $notifyMs, $prefix) {
                    $data = generateContent($task["size"]);
                    file_put_contents(
                        benchTempPath($prefix, $task["id"]),
                        $data,
                    );
                    Delay::new($notifyMs);
                });
            }
            Thread::await();
        });
        Thread::await();
    });
    BenchHelper::timing("Async (concurrent):", $asyncMs);
    cleanupBenchFiles($prefix, count($ioTasks));

    BenchHelper::comparison("Mixed sizes + notify", $blockingMs, $asyncMs);
    BenchHelper::record(
        "Mixed I/O + notify",
        $blockingMs,
        $asyncMs,
        "9 writes × 40ms",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 12: Throughput scaling — varying concurrent simulated I/O count
    //
    // Each task simulates 50ms of I/O latency.
    // Shows how async speedup scales with task count.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader("Test 12: Scaling — N tasks × 50ms simulated I/O");

    $taskCounts = [1, 2, 5, 10, 20, 50];
    $simLatency = 50;

    foreach ($taskCounts as $n) {
        // --- Blocking ---
        [, $bMs] = BenchHelper::measure(function () use ($n, $simLatency) {
            for ($i = 0; $i < $n; $i++) {
                usleep($simLatency * 1000);
            }
        });

        // --- Async ---
        [, $aMs] = BenchHelper::measure(function () use ($n, $simLatency) {
            RunBlocking::new(function () use ($n, $simLatency) {
                for ($i = 0; $i < $n; $i++) {
                    Launch::new(function () use ($simLatency) {
                        Delay::new($simLatency);
                    });
                }
                Thread::await();
            });
            Thread::await();
        });

        $speedup = $bMs > 0 ? $bMs / $aMs : 0.0;
        $theoreticalSpeedup = (float) $n;

        BenchHelper::info(
            sprintf(
                "    N=%3d: blocking=%s  async=%s  speedup=%.1fx  (theoretical: %.0fx)  efficiency=%.0f%%",
                $n,
                str_pad(BenchHelper::formatMs($bMs), 12),
                str_pad(BenchHelper::formatMs($aMs), 12),
                $speedup,
                $theoreticalSpeedup,
                $theoreticalSpeedup > 0
                    ? ($speedup / $theoreticalSpeedup) * 100
                    : 0,
            ),
        );
    }

    BenchHelper::info("");
    BenchHelper::info(
        "    Efficiency = actual_speedup / theoretical_speedup × 100%",
    );
    BenchHelper::info(
        "    100% = perfect overlap, <100% = scheduler/fiber overhead",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 13: AsyncIO file writes — AsyncIO::filePutContents()->await()
    //
    // Uses the new deferred AsyncIO->await() pattern for non-blocking
    // file writes within fibers. Compared against blocking sequential
    // file_put_contents and Launch + file_put_contents.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 13: AsyncIO file writes — 10 × 1KB via ->await()",
    );

    $fileCount = 10;
    $fileSize = 1024;
    $content = generateContent($fileSize);
    $prefix = "asyncio13";

    cleanupBenchFiles($prefix . "_b", $fileCount);
    cleanupBenchFiles($prefix . "_a", $fileCount);

    // --- Blocking ---
    [, $blockingMs] = BenchHelper::measure(function () use (
        $fileCount,
        $content,
        $prefix,
    ) {
        for ($i = 0; $i < $fileCount; $i++) {
            file_put_contents(benchTempPath($prefix . "_b", $i), $content);
        }
    });
    BenchHelper::timing("Blocking (sequential writes):", $blockingMs);
    cleanupBenchFiles($prefix . "_b", $fileCount);

    // --- AsyncIO::filePutContents()->await() ---
    [, $asyncIoMs] = BenchHelper::measure(function () use (
        $fileCount,
        $content,
        $prefix,
    ) {
        RunBlocking::new(function () use ($fileCount, $content, $prefix) {
            for ($i = 0; $i < $fileCount; $i++) {
                Launch::new(function () use ($i, $content, $prefix) {
                    AsyncIO::filePutContents(
                        benchTempPath($prefix . "_a", $i),
                        $content,
                    )->await();
                });
            }
            Thread::await();
        });
        Thread::await();
    });
    BenchHelper::timing("AsyncIO (->await() writes):", $asyncIoMs);
    cleanupBenchFiles($prefix . "_a", $fileCount);

    BenchHelper::comparison("10×1KB AsyncIO writes", $blockingMs, $asyncIoMs);
    BenchHelper::info(
        "    AsyncIO::filePutContents()->await() yields between chunks",
    );
    BenchHelper::info(
        "    Pattern: AsyncIO::method()->await() — explicit deferred I/O",
    );
    BenchHelper::record(
        "AsyncIO 10×1KB writes",
        $blockingMs,
        $asyncIoMs,
        "->await() file writes",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 14: AsyncIO file reads — AsyncIO::fileGetContents()->await()
    //
    // Non-blocking file reads via the deferred ->await() pattern.
    // Concurrent reads cooperatively yield between chunks.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 14: AsyncIO file reads — 10 × 1KB via ->await()",
    );

    $prefix = "asyncio14";
    // Setup: create files to read
    for ($i = 0; $i < $fileCount; $i++) {
        file_put_contents(benchTempPath($prefix, $i), $content);
    }

    // --- Blocking ---
    [$blockingCount, $blockingMs] = BenchHelper::measure(function () use (
        $fileCount,
        $prefix,
    ) {
        $data = [];
        for ($i = 0; $i < $fileCount; $i++) {
            $data[] = file_get_contents(benchTempPath($prefix, $i));
        }
        return count($data);
    });
    BenchHelper::timing("Blocking (sequential reads):", $blockingMs);

    // --- AsyncIO::fileGetContents()->await() ---
    [$asyncIoCount, $asyncIoMs] = BenchHelper::measure(function () use (
        $fileCount,
        $prefix,
    ) {
        $data = [];
        RunBlocking::new(function () use ($fileCount, $prefix, &$data) {
            for ($i = 0; $i < $fileCount; $i++) {
                Launch::new(function () use ($i, $prefix, &$data) {
                    $data[$i] = AsyncIO::fileGetContents(
                        benchTempPath($prefix, $i),
                    )->await();
                });
            }
            Thread::await();
        });
        Thread::await();
        return count($data);
    });
    BenchHelper::timing("AsyncIO (->await() reads):", $asyncIoMs);

    BenchHelper::comparison("10×1KB AsyncIO reads", $blockingMs, $asyncIoMs);
    BenchHelper::assert(
        "All files read (blocking)",
        $blockingCount === $fileCount,
    );
    BenchHelper::assert(
        "All files read (AsyncIO)",
        $asyncIoCount === $fileCount,
    );
    BenchHelper::record(
        "AsyncIO 10×1KB reads",
        $blockingMs,
        $asyncIoMs,
        "->await() file reads",
    );

    cleanupBenchFiles($prefix, $fileCount);

    // ═════════════════════════════════════════════════════════════════
    // Test 15: AsyncIO write-then-read pipeline + socket pair IPC
    //
    // Demonstrates chaining: write a file via AsyncIO, read it back,
    // then send the content through an AsyncIO socket pair.
    //
    // 3 tasks, each: write file → read file → send via socket pair
    // Compared against blocking sequential equivalent.
    //
    // NOTE: On Windows, createSocketPair() uses loopback TCP which adds
    // significant overhead per pair. On Linux/macOS, Unix socket pairs
    // are near-instant.
    // ═════════════════════════════════════════════════════════════════

    $pipelineCount = 3;
    $prefix = "asyncio15";
    $pipelineContent = generateContent(512);
    $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === "WIN";

    BenchHelper::subHeader(
        "Test 15: AsyncIO pipeline — write → read → socket pair × {$pipelineCount}",
    );

    cleanupBenchFiles($prefix, $pipelineCount);

    // --- Blocking (sequential write → read → loopback copy) ---
    [, $blockingMs] = BenchHelper::measure(function () use (
        $pipelineCount,
        $pipelineContent,
        $prefix,
    ) {
        for ($i = 0; $i < $pipelineCount; $i++) {
            $path = benchTempPath($prefix, $i);
            file_put_contents($path, $pipelineContent);
            $readBack = file_get_contents($path);
            // Simulate IPC: write to a temp stream and read back
            $tmp = fopen("php://memory", "r+b");
            fwrite($tmp, $readBack);
            rewind($tmp);
            $ipcData = stream_get_contents($tmp);
            fclose($tmp);
        }
    });
    BenchHelper::timing("Blocking (sequential pipeline):", $blockingMs);
    cleanupBenchFiles($prefix, $pipelineCount);

    // --- AsyncIO pipeline with ->await() ---
    [$asyncIoResults, $asyncIoMs] = BenchHelper::measure(function () use (
        $pipelineCount,
        $pipelineContent,
        $prefix,
    ) {
        $results = [];
        RunBlocking::new(function () use (
            $pipelineCount,
            $pipelineContent,
            $prefix,
            &$results,
        ) {
            for ($i = 0; $i < $pipelineCount; $i++) {
                Launch::new(function () use (
                    $i,
                    $pipelineContent,
                    $prefix,
                    &$results,
                ) {
                    $path = benchTempPath($prefix, $i);

                    // Step 1: Write via AsyncIO
                    AsyncIO::filePutContents($path, $pipelineContent)->await();

                    // Step 2: Read back via AsyncIO
                    $readBack = AsyncIO::fileGetContents($path)->await();

                    // Step 3: Send through socket pair via AsyncIO
                    $pair = AsyncIO::createSocketPair()->await();
                    fwrite($pair[0], $readBack);
                    $ipcData = fread($pair[1], strlen($readBack) + 1);
                    fclose($pair[0]);
                    fclose($pair[1]);

                    $results[$i] = strlen($ipcData);
                });
            }
            Thread::await();
        });
        Thread::await();
        return $results;
    });
    BenchHelper::timing("AsyncIO (->await() pipeline):", $asyncIoMs);
    cleanupBenchFiles($prefix, $pipelineCount);

    BenchHelper::comparison(
        "{$pipelineCount}× write→read→IPC pipeline",
        $blockingMs,
        $asyncIoMs,
    );
    BenchHelper::assert(
        "All pipeline results collected",
        count($asyncIoResults) === $pipelineCount,
    );
    if (count($asyncIoResults) === $pipelineCount) {
        $allCorrectSize = true;
        foreach ($asyncIoResults as $size) {
            if ($size !== strlen($pipelineContent)) {
                $allCorrectSize = false;
                break;
            }
        }
        BenchHelper::assert("All IPC data sizes correct", $allCorrectSize);
    }
    BenchHelper::info(
        "    Pattern: AsyncIO::filePutContents()->await() → fileGetContents()->await() → createSocketPair()->await()",
    );
    if ($isWindows) {
        BenchHelper::info(
            "    NOTE: On Windows, createSocketPair() uses loopback TCP (no Unix sockets)",
        );
        BenchHelper::info(
            "    Each pair = stream_socket_server + stream_socket_client + accept → high overhead",
        );
        BenchHelper::info(
            "    On Linux/macOS, stream_socket_pair(STREAM_PF_UNIX) is near-instant",
        );
    }
    BenchHelper::record(
        "AsyncIO pipeline ×{$pipelineCount}",
        $blockingMs,
        $asyncIoMs,
        "write→read→socketPair" . ($isWindows ? " (Win loopback)" : ""),
    );

    // ═════════════════════════════════════════════════════════════════
    //  Summary
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::printSummary();

    BenchHelper::info("");
    BenchHelper::info("Interpretation:");
    BenchHelper::info(
        "  ┌─────────────────────────────────────────────────────────────────┐",
    );
    BenchHelper::info(
        "  │ WORKLOAD TYPE        │ ASYNC BENEFIT │ WHY                       │",
    );
    BenchHelper::info(
        "  ├─────────────────────────────────────────────────────────────────┤",
    );
    BenchHelper::info(
        "  │ Real file I/O only   │ None / Worse  │ file_put_contents blocks  │",
    );
    BenchHelper::info(
        "  │ Simulated latency    │ ~Nx faster    │ Delays overlap perfectly  │",
    );
    BenchHelper::info(
        "  │ File I/O + latency   │ Significant   │ Latency overlaps file I/O │",
    );
    BenchHelper::info(
        "  │ Dispatchers::IO      │ Overhead      │ Process spawn cost        │",
    );
    BenchHelper::info(
        "  │ DB query simulation  │ ~Nx faster    │ Independent queries       │",
    );
    BenchHelper::info(
        "  └─────────────────────────────────────────────────────────────────┘",
    );
    BenchHelper::info("");
    BenchHelper::info("Node.js comparison:");
    BenchHelper::info(
        "  • Node.js: ALL I/O non-blocking by default (libuv handles it)",
    );
    BenchHelper::info(
        "  • Node.js file I/O: libuv thread pool (4 threads) → true parallel disk access",
    );
    BenchHelper::info(
        "  • VOsaka file I/O in DEFAULT: still blocking (PHP limitation)",
    );
    BenchHelper::info(
        "  • VOsaka AsyncIO file I/O: cooperative yielding between chunks",
    );
    BenchHelper::info(
        "  • VOsaka file I/O in IO: child process → overhead but true parallel",
    );
    BenchHelper::info(
        "  • Simulated latency tests: VOsaka matches Node.js speedup ratios",
    );
    BenchHelper::info(
        "  • Real I/O tests: Node.js wins because libuv is non-blocking at OS level",
    );
    BenchHelper::info(
        "  • Key: VOsaka shines when tasks have WAIT TIME (network, API calls)",
    );
    BenchHelper::info(
        "         AsyncIO::method()->await() makes async I/O explicit and composable",
    );
    BenchHelper::info("");
});
