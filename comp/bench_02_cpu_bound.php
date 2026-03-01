<?php

/**
 * Benchmark 02: CPU-Bound Computation
 *
 * Compares blocking sequential CPU work vs VOsaka async fiber-based concurrency
 * for purely computational tasks (no I/O, no waiting).
 *
 * This benchmark is deliberately adversarial for the async approach:
 *   - CPU-bound work does NOT benefit from I/O concurrency
 *   - Fiber suspend/resume adds overhead with zero parallelism gain
 *   - The async scheduler loop itself consumes CPU cycles
 *
 * The purpose is to quantify the raw overhead of the fiber/scheduler machinery
 * when there is NO I/O to overlap. This is the "worst case" for coroutines.
 *
 * Scenarios:
 *   1. Single heavy computation (no concurrency needed)
 *   2. Multiple independent computations in fibers vs sequential
 *   3. Fine-grained vs coarse-grained yielding
 *   4. Fiber creation/teardown overhead
 *   5. Async::new + await overhead for CPU tasks
 *   6. Comparison with Dispatchers::IO (offload to child process)
 *
 * Expected results:
 *   - For pure CPU work in DEFAULT dispatcher: async ≈ blocking or slightly slower
 *     (fiber overhead is small but nonzero)
 *   - For Dispatchers::IO: significant overhead due to process spawn + serialization
 *   - Node.js comparison: V8 JIT compiles JS ~5-10x faster than PHP interpreter
 *     for raw CPU work, but both would show similar "no speedup" for single-threaded
 *     CPU-bound tasks.
 */

require __DIR__ . "/../vendor/autoload.php";
require __DIR__ . "/BenchHelper.php";

use comp\BenchHelper;
use vosaka\foroutines\Async;
use vosaka\foroutines\Delay;
use vosaka\foroutines\Dispatchers;
use vosaka\foroutines\Launch;
use vosaka\foroutines\Pause;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Thread;

use function vosaka\foroutines\main;

// ─── CPU-bound work functions ────────────────────────────────────────────

/**
 * Compute the sum of primes up to $n using trial division.
 * This is intentionally naive to burn CPU cycles.
 */
function sumPrimesUpTo(int $n): int
{
    $sum = 0;
    for ($i = 2; $i <= $n; $i++) {
        $isPrime = true;
        $limit = (int) sqrt($i);
        for ($j = 2; $j <= $limit; $j++) {
            if ($i % $j === 0) {
                $isPrime = false;
                break;
            }
        }
        if ($isPrime) {
            $sum += $i;
        }
    }
    return $sum;
}

/**
 * Fibonacci (iterative) — pure computation, no allocations.
 */
function fibIterative(int $n): int
{
    if ($n <= 1) {
        return $n;
    }
    $a = 0;
    $b = 1;
    for ($i = 2; $i <= $n; $i++) {
        $c = $a + $b;
        $a = $b;
        $b = $c;
    }
    return $b;
}

/**
 * Matrix multiplication of two $size×$size matrices.
 * Returns the result matrix.
 *
 * @param array<array<float>> $a
 * @param array<array<float>> $b
 * @param int $size
 * @return array<array<float>>
 */
function matMul(array $a, array $b, int $size): array
{
    $result = array_fill(0, $size, array_fill(0, $size, 0.0));
    for ($i = 0; $i < $size; $i++) {
        for ($j = 0; $j < $size; $j++) {
            $sum = 0.0;
            for ($k = 0; $k < $size; $k++) {
                $sum += $a[$i][$k] * $b[$k][$j];
            }
            $result[$i][$j] = $sum;
        }
    }
    return $result;
}

/**
 * Generate a random $size×$size matrix with values in [0, 1).
 *
 * @param int $size
 * @return array<array<float>>
 */
function randomMatrix(int $size): array
{
    $m = [];
    for ($i = 0; $i < $size; $i++) {
        $row = [];
        for ($j = 0; $j < $size; $j++) {
            $row[] = mt_rand(0, 10000) / 10000.0;
        }
        $m[] = $row;
    }
    return $m;
}

/**
 * SHA-256 hash chain: hash a string N times sequentially.
 */
function hashChain(string $seed, int $iterations): string
{
    $hash = $seed;
    for ($i = 0; $i < $iterations; $i++) {
        $hash = hash("sha256", $hash);
    }
    return $hash;
}

// ─── Main benchmark ──────────────────────────────────────────────────────

main(function () {
    // Ensure Launch static state is initialized before any RunBlocking call
    // (RunBlocking::new phase 2 accesses Launch::$queue directly)
    Launch::getInstance();

    BenchHelper::header("Benchmark 02: CPU-Bound Computation");
    BenchHelper::info("Blocking sequential vs VOsaka async fiber overhead");
    BenchHelper::info("Pure CPU work — no I/O overlap possible");
    BenchHelper::info("PHP " . PHP_VERSION . " | " . PHP_OS);
    BenchHelper::separator();

    // ═════════════════════════════════════════════════════════════════
    // Test 1: Single heavy computation — sumPrimes(10000)
    // No concurrency benefit here; measures pure fiber overhead.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader("Test 1: Single computation — sumPrimes(10000)");

    $n = 10000;

    // --- Blocking ---
    [$blockingResult, $blockingMs] = BenchHelper::measure(function () use ($n) {
        return sumPrimesUpTo($n);
    });
    BenchHelper::timing("Blocking (direct call):", $blockingMs);

    // --- Async (fiber wrapper, no real concurrency) ---
    [$asyncResult, $asyncMs] = BenchHelper::measure(function () use ($n) {
        $result = null;
        RunBlocking::new(function () use ($n, &$result) {
            $result = sumPrimesUpTo($n);
        });
        Thread::await();
        return $result;
    });
    BenchHelper::timing("Async (RunBlocking wrapper):", $asyncMs);

    BenchHelper::comparison("sumPrimes(10000)", $blockingMs, $asyncMs);
    BenchHelper::assert("Results match", $blockingResult === $asyncResult);
    BenchHelper::record(
        "Single sumPrimes",
        $blockingMs,
        $asyncMs,
        "fiber wrapper overhead",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 2: Multiple independent computations — 5 × sumPrimes(5000)
    // All run in DEFAULT dispatcher (same process, fibers).
    // Since CPU is single-threaded, no speedup expected — only overhead.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 2: 5 independent computations — sumPrimes(5000)",
    );

    $taskCount = 5;
    $n = 5000;

    // --- Blocking ---
    [$blockingResults, $blockingMs] = BenchHelper::measure(function () use (
        $taskCount,
        $n,
    ) {
        $results = [];
        for ($i = 0; $i < $taskCount; $i++) {
            $results[] = sumPrimesUpTo($n);
        }
        return $results;
    });
    BenchHelper::timing("Blocking (sequential):", $blockingMs);

    // --- Async (Launch in DEFAULT dispatcher) ---
    [$asyncResults, $asyncMs] = BenchHelper::measure(function () use (
        $taskCount,
        $n,
    ) {
        $results = [];
        RunBlocking::new(function () use ($taskCount, $n, &$results) {
            for ($i = 0; $i < $taskCount; $i++) {
                Launch::new(function () use ($n, &$results, $i) {
                    $results[$i] = sumPrimesUpTo($n);
                });
            }
            Thread::await();
        });
        Thread::await();
        ksort($results);
        return array_values($results);
    });
    BenchHelper::timing("Async (5 fibers DEFAULT):", $asyncMs);

    BenchHelper::comparison("5×sumPrimes(5000)", $blockingMs, $asyncMs);
    BenchHelper::assert(
        "All results match",
        $blockingResults === $asyncResults,
    );
    BenchHelper::record(
        "5×sumPrimes(5000)",
        $blockingMs,
        $asyncMs,
        "fiber overhead, no I/O",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 3: Fiber creation/teardown overhead
    // Create N fibers that do trivial work (return immediately).
    // Measures the cost of fiber allocation + start + terminate.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 3: Fiber creation overhead — 500 trivial tasks",
    );

    $taskCount = 500;

    // --- Blocking (just function calls) ---
    [, $blockingMs] = BenchHelper::measure(function () use ($taskCount) {
        $sum = 0;
        for ($i = 0; $i < $taskCount; $i++) {
            $sum += $i;
        }
        return $sum;
    });
    BenchHelper::timing("Blocking (plain loop):", $blockingMs);

    // --- Async (Launch each iteration) ---
    [, $asyncMs] = BenchHelper::measure(function () use ($taskCount) {
        $sum = 0;
        RunBlocking::new(function () use ($taskCount, &$sum) {
            for ($i = 0; $i < $taskCount; $i++) {
                Launch::new(function () use ($i, &$sum) {
                    $sum += $i;
                });
            }
            Thread::await();
        });
        Thread::await();
        return $sum;
    });
    BenchHelper::timing("Async (500 Launch fibers):", $asyncMs);

    BenchHelper::comparison("500 trivial fibers", $blockingMs, $asyncMs);
    BenchHelper::info(
        "    Overhead per fiber: ~" .
            BenchHelper::formatMs(($asyncMs - $blockingMs) / $taskCount),
    );
    BenchHelper::record(
        "500 trivial fibers",
        $blockingMs,
        $asyncMs,
        "creation + teardown cost",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 4: Pause::new() yield overhead
    // One fiber that yields N times via Pause::new().
    // Measures raw context-switch cost.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 4: Context-switch overhead — 1000 Pause::new() yields",
    );

    $yieldCount = 1000;

    // --- Blocking (equivalent: just a loop doing nothing) ---
    [, $blockingMs] = BenchHelper::measure(function () use ($yieldCount) {
        for ($i = 0; $i < $yieldCount; $i++) {
            // no-op: simulating "no yield"
        }
    });
    BenchHelper::timing("Blocking (no-op loop):", $blockingMs);

    // --- Async (fiber yielding) ---
    [, $asyncMs] = BenchHelper::measure(function () use ($yieldCount) {
        RunBlocking::new(function () use ($yieldCount) {
            Launch::new(function () use ($yieldCount) {
                for ($i = 0; $i < $yieldCount; $i++) {
                    Pause::new();
                }
            });
            Thread::await();
        });
        Thread::await();
    });
    BenchHelper::timing("Async (1000 yields):", $asyncMs);

    BenchHelper::comparison("1000 Pause::new()", $blockingMs, $asyncMs);
    BenchHelper::info(
        "    Cost per yield: ~" . BenchHelper::formatMs($asyncMs / $yieldCount),
    );
    BenchHelper::record(
        "1000 yields",
        $blockingMs,
        $asyncMs,
        "Fiber::suspend/resume cost",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 5: Async/Await overhead for CPU tasks
    // 10 × Async::new() + ->await() each computing fibIterative(50)
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader("Test 5: Async/Await overhead — 10×fib(50)");

    $taskCount = 10;
    $fibN = 50;

    // --- Blocking ---
    [$blockingResults, $blockingMs] = BenchHelper::measure(function () use (
        $taskCount,
        $fibN,
    ) {
        $results = [];
        for ($i = 0; $i < $taskCount; $i++) {
            $results[] = fibIterative($fibN);
        }
        return $results;
    });
    BenchHelper::timing("Blocking (sequential):", $blockingMs);

    // --- Async ---
    [$asyncResults, $asyncMs] = BenchHelper::measure(function () use (
        $taskCount,
        $fibN,
    ) {
        $results = [];
        RunBlocking::new(function () use ($taskCount, $fibN, &$results) {
            $handles = [];
            for ($i = 0; $i < $taskCount; $i++) {
                $handles[] = Async::new(function () use ($fibN) {
                    return fibIterative($fibN);
                });
            }
            foreach ($handles as $h) {
                $results[] = $h->await();
            }
        });
        Thread::await();
        return $results;
    });
    BenchHelper::timing("Async (10 Async::new):", $asyncMs);

    BenchHelper::comparison("10×fib(50)", $blockingMs, $asyncMs);
    BenchHelper::assert("Results match", $blockingResults === $asyncResults);
    BenchHelper::record(
        "10×fib(50) await",
        $blockingMs,
        $asyncMs,
        "Async::new + await cost",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 6: Matrix multiplication — heavier CPU workload
    // Single 50×50 matmul: blocking vs fiber-wrapped
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader("Test 6: Matrix multiplication — 50×50 matmul");

    $size = 50;
    $matA = randomMatrix($size);
    $matB = randomMatrix($size);

    // --- Blocking ---
    [, $blockingMs] = BenchHelper::measure(function () use (
        $matA,
        $matB,
        $size,
    ) {
        return matMul($matA, $matB, $size);
    });
    BenchHelper::timing("Blocking (direct):", $blockingMs);

    // --- Async ---
    [, $asyncMs] = BenchHelper::measure(function () use ($matA, $matB, $size) {
        $result = null;
        RunBlocking::new(function () use ($matA, $matB, $size, &$result) {
            $async = Async::new(function () use ($matA, $matB, $size) {
                return matMul($matA, $matB, $size);
            });
            $result = $async->await();
        });
        Thread::await();
        return $result;
    });
    BenchHelper::timing("Async (fiber-wrapped):", $asyncMs);

    BenchHelper::comparison("50×50 matmul", $blockingMs, $asyncMs);
    BenchHelper::record(
        "50×50 matmul",
        $blockingMs,
        $asyncMs,
        "heavy single computation",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 7: Hash chain — 3 independent chains × 5000 iterations
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader("Test 7: Hash chain — 3×5000 SHA-256 iterations");

    $chainCount = 3;
    $iterations = 5000;
    $seeds = ["alpha", "beta", "gamma"];

    // --- Blocking ---
    [$blockingResults, $blockingMs] = BenchHelper::measure(function () use (
        $seeds,
        $iterations,
    ) {
        $results = [];
        foreach ($seeds as $seed) {
            $results[] = hashChain($seed, $iterations);
        }
        return $results;
    });
    BenchHelper::timing("Blocking (sequential):", $blockingMs);

    // --- Async (fibers, same process) ---
    [$asyncResults, $asyncMs] = BenchHelper::measure(function () use (
        $seeds,
        $iterations,
    ) {
        $results = [];
        RunBlocking::new(function () use ($seeds, $iterations, &$results) {
            $handles = [];
            foreach ($seeds as $idx => $seed) {
                $handles[$idx] = Async::new(function () use (
                    $seed,
                    $iterations,
                ) {
                    return hashChain($seed, $iterations);
                });
            }
            foreach ($handles as $idx => $h) {
                $results[$idx] = $h->await();
            }
        });
        Thread::await();
        return $results;
    });
    BenchHelper::timing("Async (3 fibers):", $asyncMs);

    BenchHelper::comparison("3×5000 SHA-256", $blockingMs, $asyncMs);
    BenchHelper::assert(
        "Hash results match",
        $blockingResults === $asyncResults,
    );
    BenchHelper::record(
        "3×SHA-256 chains",
        $blockingMs,
        $asyncMs,
        "hash chains, no I/O",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 8: Dispatchers::IO — offload CPU work to child process
    // sumPrimes(8000) in a child process vs direct call.
    // This shows the process-spawn overhead.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 8: Dispatchers::IO — sumPrimes(8000) in child process",
    );
    BenchHelper::info("    (This tests process spawn + serialize overhead)");

    $n = 8000;

    // --- Blocking (direct) ---
    [$blockingResult, $blockingMs] = BenchHelper::measure(function () use ($n) {
        return sumPrimesUpTo($n);
    });
    BenchHelper::timing("Blocking (direct):", $blockingMs);

    // --- Dispatchers::IO (child process) ---
    [$ioResult, $ioMs] = BenchHelper::measure(function () use ($n) {
        $result = null;
        RunBlocking::new(function () use ($n, &$result) {
            $async = Async::new(function () use ($n) {
                return sumPrimesUpTo($n);
            }, Dispatchers::IO);
            $result = $async->await();
        });
        Thread::await();
        return $result;
    });
    BenchHelper::timing("Dispatchers::IO (child proc):", $ioMs);

    BenchHelper::comparison("IO dispatch overhead", $blockingMs, $ioMs);
    BenchHelper::assert("IO result matches", $blockingResult === $ioResult);
    BenchHelper::info(
        "    Process overhead: ~" . BenchHelper::formatMs($ioMs - $blockingMs),
    );
    BenchHelper::record(
        "IO sumPrimes(8000)",
        $blockingMs,
        $ioMs,
        "child process overhead",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 9: Mixed workload — CPU + Delay (simulated I/O)
    // 5 tasks: each does sumPrimes(3000) then Delay(100ms)
    // Blocking: 5×(CPU + 100ms)  |  Async: CPU_total + ~100ms
    // This shows where async DOES help even with CPU work.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 9: Mixed CPU + I/O — 5×(sumPrimes(3000) + 100ms delay)",
    );

    $taskCount = 5;
    $n = 3000;
    $delayMs = 100;

    // --- Blocking ---
    [, $blockingMs] = BenchHelper::measure(function () use (
        $taskCount,
        $n,
        $delayMs,
    ) {
        for ($i = 0; $i < $taskCount; $i++) {
            sumPrimesUpTo($n);
            usleep($delayMs * 1000);
        }
    });
    BenchHelper::timing("Blocking (sequential):", $blockingMs);

    // --- Async ---
    [, $asyncMs] = BenchHelper::measure(function () use (
        $taskCount,
        $n,
        $delayMs,
    ) {
        RunBlocking::new(function () use ($taskCount, $n, $delayMs) {
            for ($i = 0; $i < $taskCount; $i++) {
                Launch::new(function () use ($n, $delayMs) {
                    sumPrimesUpTo($n);
                    Delay::new($delayMs);
                });
            }
            Thread::await();
        });
        Thread::await();
    });
    BenchHelper::timing("Async (concurrent):", $asyncMs);

    BenchHelper::comparison("Mixed CPU+I/O", $blockingMs, $asyncMs);
    BenchHelper::record(
        "Mixed CPU+I/O",
        $blockingMs,
        $asyncMs,
        "CPU work + delay overlap",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 10: Scaling — fiber count impact on CPU work
    // Fixed total work: sumPrimes(2000) × N, vary N
    // Shows how fiber count affects overhead for CPU tasks.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 10: Scaling — fiber count impact on CPU overhead",
    );

    $n = 2000;
    $fiberCounts = [1, 5, 10, 25, 50];

    foreach ($fiberCounts as $count) {
        // --- Blocking ---
        [, $bMs] = BenchHelper::measure(function () use ($count, $n) {
            for ($i = 0; $i < $count; $i++) {
                sumPrimesUpTo($n);
            }
        });

        // --- Async ---
        [, $aMs] = BenchHelper::measure(function () use ($count, $n) {
            RunBlocking::new(function () use ($count, $n) {
                for ($i = 0; $i < $count; $i++) {
                    Launch::new(function () use ($n) {
                        sumPrimesUpTo($n);
                    });
                }
                Thread::await();
            });
            Thread::await();
        });

        $overhead = $bMs > 0 ? (($aMs - $bMs) / $bMs) * 100.0 : 0.0;
        BenchHelper::info(
            sprintf(
                "    %3d fibers: blocking=%s  async=%s  overhead=%+.1f%%",
                $count,
                str_pad(BenchHelper::formatMs($bMs), 12),
                str_pad(BenchHelper::formatMs($aMs), 12),
                $overhead,
            ),
        );
    }

    BenchHelper::info("");
    BenchHelper::info(
        "    (Overhead % = how much slower async is vs blocking for pure CPU)",
    );

    // ═════════════════════════════════════════════════════════════════
    //  Summary
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::printSummary();

    BenchHelper::info("");
    BenchHelper::info("Interpretation:");
    BenchHelper::info(
        "  • For pure CPU work, async adds overhead (fiber create/switch/teardown)",
    );
    BenchHelper::info(
        "  • Typical overhead: ~5-20% for DEFAULT dispatcher (in-process fibers)",
    );
    BenchHelper::info(
        "  • Dispatchers::IO adds massive overhead: process spawn + serialization",
    );
    BenchHelper::info(
        "  • Mixed workloads (CPU + I/O) still benefit from async concurrency",
    );
    BenchHelper::info(
        "  • Node.js comparison: V8 JIT is ~5-10x faster for raw CPU math,",
    );
    BenchHelper::info(
        "    but async/await overhead ratio is similar (Promises are cheap too)",
    );
    BenchHelper::info(
        "  • Key takeaway: Use async for I/O-bound work, not CPU-bound work",
    );
    BenchHelper::info("");
});
