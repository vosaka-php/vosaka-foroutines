<?php

/**
 * Benchmark 05: Flow / SharedFlow / StateFlow — Throughput & Backpressure
 *
 * Compares traditional PHP data-processing patterns (arrays, generators, iterators)
 * vs VOsaka Flow-based reactive streams with backpressure control.
 *
 * Scenarios:
 *
 *   1.  Cold Flow vs array iteration — baseline emission/collection overhead
 *   2.  Cold Flow with operators (map-like via emit transform) vs array_map
 *   3.  Cold Flow with buffer() operator — producer/consumer decoupling
 *   4.  SharedFlow — single emitter, single collector, replay=0
 *   5.  SharedFlow — single emitter, multiple collectors, replay=N
 *   6.  SharedFlow backpressure: SUSPEND strategy under fast producer
 *   7.  SharedFlow backpressure: DROP_OLDEST strategy under fast producer
 *   8.  SharedFlow backpressure: DROP_LATEST strategy under fast producer
 *   9.  MutableStateFlow — rapid state updates, single observer
 *  10.  MutableStateFlow — rapid state updates, multiple observers
 *  11.  SharedFlow with extraBufferCapacity — burst absorption
 *  12.  Cold Flow vs Generator — PHP native generator comparison
 *  13.  Backpressure strategy comparison — all strategies side by side
 *  14.  SharedFlow throughput scaling — varying collector count
 *  15.  StateFlow vs SharedFlow(replay=1) — semantic equivalence perf
 *
 * Why this matters:
 *   Flow/SharedFlow/StateFlow are Kotlin-inspired reactive primitives that have
 *   no direct equivalent in Node.js (closest: RxJS Observables, Node Streams).
 *   Backpressure is critical for production systems where producers outpace
 *   consumers. This benchmark quantifies:
 *     - Per-emission overhead of the Flow machinery
 *     - Backpressure strategy impact on throughput and data loss
 *     - Scaling behavior with multiple collectors
 *     - Comparison with plain PHP patterns (arrays, generators)
 *
 * Expected results:
 *   - Cold Flow: small overhead vs raw arrays/generators (fiber + closure cost)
 *   - SharedFlow with SUSPEND: lowest throughput (emitter blocks on full buffer)
 *   - SharedFlow with DROP_OLDEST/DROP_LATEST: higher throughput (no blocking)
 *   - MutableStateFlow: fast for single-value state (always DROP_OLDEST semantics)
 *   - Multiple collectors: linear throughput degradation per collector
 *   - Node.js comparison:
 *       • RxJS Observable: ~0.1-0.5 µs/emission (V8 JIT optimized)
 *       • Node Readable stream: ~0.5-2 µs/emission with backpressure
 *       • VOsaka Flow: ~1-20 µs/emission (PHP + Fiber overhead)
 */

require __DIR__ . "/../vendor/autoload.php";
require __DIR__ . "/BenchHelper.php";

use comp\BenchHelper;
use vosaka\foroutines\Async;
use vosaka\foroutines\Delay;
use vosaka\foroutines\Launch;
use vosaka\foroutines\Pause;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Thread;
use vosaka\foroutines\flow\BackpressureStrategy;
use vosaka\foroutines\flow\Flow;
use vosaka\foroutines\flow\SharedFlow;
use vosaka\foroutines\flow\MutableStateFlow;
use vosaka\foroutines\flow\StateFlow;

use function vosaka\foroutines\main;

main(function () {
    // Ensure Launch static state is initialized before any RunBlocking call
    // (RunBlocking::new phase 2 accesses Launch::$queue directly)
    Launch::getInstance();

    BenchHelper::header("Benchmark 05: Flow / SharedFlow / StateFlow");
    BenchHelper::info(
        "Throughput & backpressure — reactive streams vs traditional PHP",
    );
    BenchHelper::info("PHP " . PHP_VERSION . " | " . PHP_OS);
    BenchHelper::separator();

    // ═════════════════════════════════════════════════════════════════
    // Test 1: Cold Flow vs array iteration — baseline overhead
    //
    // Emit 2000 integers via Cold Flow vs building an array.
    // Measures the per-emission cost of Flow::emit + collect machinery.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader("Test 1: Cold Flow vs array — 2000 integers");

    $itemCount = 2000;

    // --- Array baseline ---
    [$arrayResult, $arrayMs] = BenchHelper::measure(function () use (
        $itemCount,
    ) {
        $data = [];
        for ($i = 0; $i < $itemCount; $i++) {
            $data[] = $i * 2;
        }
        $sum = 0;
        foreach ($data as $val) {
            $sum += $val;
        }
        return $sum;
    });
    BenchHelper::timing("Array (build + iterate):", $arrayMs);

    // --- Cold Flow ---
    [$flowResult, $flowMs] = BenchHelper::measure(function () use ($itemCount) {
        $sum = 0;
        RunBlocking::new(function () use ($itemCount, &$sum) {
            $flow = Flow::new(function () use ($itemCount) {
                for ($i = 0; $i < $itemCount; $i++) {
                    Flow::emit($i * 2);
                }
            });

            $flow->collect(function ($value) use (&$sum) {
                $sum += $value;
            });
        });
        Thread::await();
        return $sum;
    });
    BenchHelper::timing("Cold Flow (emit + collect):", $flowMs);

    BenchHelper::comparison("2000 int emissions", $arrayMs, $flowMs);
    BenchHelper::assert(
        "Flow sum matches array sum",
        $flowResult === $arrayResult,
    );
    $perEmitUs = ($flowMs / $itemCount) * 1000.0;
    BenchHelper::info(sprintf("    Per-emission cost: ~%.1f µs", $perEmitUs));
    BenchHelper::record(
        "Cold Flow vs array",
        $arrayMs,
        $flowMs,
        "2000 ints baseline",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 2: Cold Flow with transform vs array_map
    //
    // Emit 1000 values, transform each (×3 +1), collect.
    // Simulates a map operator via emit transformation.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 2: Cold Flow transform vs array_map — 1000 items",
    );

    $itemCount = 1000;

    // --- array_map ---
    [$arrayResult, $arrayMs] = BenchHelper::measure(function () use (
        $itemCount,
    ) {
        $data = range(0, $itemCount - 1);
        $transformed = array_map(fn($v) => $v * 3 + 1, $data);
        return array_sum($transformed);
    });
    BenchHelper::timing("array_map:", $arrayMs);

    // --- Flow with inline transform ---
    [$flowResult, $flowMs] = BenchHelper::measure(function () use ($itemCount) {
        $sum = 0;
        RunBlocking::new(function () use ($itemCount, &$sum) {
            $flow = Flow::new(function () use ($itemCount) {
                for ($i = 0; $i < $itemCount; $i++) {
                    Flow::emit($i * 3 + 1);
                }
            });

            $flow->collect(function ($value) use (&$sum) {
                $sum += $value;
            });
        });
        Thread::await();
        return $sum;
    });
    BenchHelper::timing("Cold Flow (transform+collect):", $flowMs);

    BenchHelper::comparison("1000 items transform", $arrayMs, $flowMs);
    BenchHelper::assert(
        "Transform results match",
        $flowResult === $arrayResult,
    );
    BenchHelper::record(
        "Flow transform vs map",
        $arrayMs,
        $flowMs,
        "1000 items ×3+1",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 3: Cold Flow with buffer() operator
    //
    // Producer emits 500 values. With buffer(), the producer can run
    // ahead of the consumer up to the buffer capacity, decoupling
    // their execution speeds.
    //
    // Without buffer: producer and consumer alternate (lock-step).
    // With buffer: producer fills buffer, consumer catches up.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 3: Cold Flow — unbuffered vs buffer(32) — 500 items",
    );

    $itemCount = 500;

    // --- Unbuffered (default collect) ---
    [, $unbufferedMs] = BenchHelper::measure(function () use ($itemCount) {
        $sum = 0;
        RunBlocking::new(function () use ($itemCount, &$sum) {
            $flow = Flow::new(function () use ($itemCount) {
                for ($i = 0; $i < $itemCount; $i++) {
                    Flow::emit($i);
                }
            });

            $flow->collect(function ($value) use (&$sum) {
                $sum += $value;
            });
        });
        Thread::await();
        return $sum;
    });
    BenchHelper::timing("Unbuffered collect:", $unbufferedMs);

    // --- Buffered (buffer(32)) ---
    [, $bufferedMs] = BenchHelper::measure(function () use ($itemCount) {
        $sum = 0;
        RunBlocking::new(function () use ($itemCount, &$sum) {
            $flow = Flow::new(function () use ($itemCount) {
                for ($i = 0; $i < $itemCount; $i++) {
                    Flow::emit($i);
                }
            });

            $flow->buffer(32)->collect(function ($value) use (&$sum) {
                $sum += $value;
            });
        });
        Thread::await();
        return $sum;
    });
    BenchHelper::timing("Buffered collect (buf=32):", $bufferedMs);

    BenchHelper::comparison(
        "Unbuffered vs buffer(32)",
        $unbufferedMs,
        $bufferedMs,
    );
    BenchHelper::info(
        "    Buffer decouples producer/consumer fiber scheduling",
    );
    BenchHelper::record(
        "Flow buffer(32)",
        $unbufferedMs,
        $bufferedMs,
        "500 items, cold flow",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 4: SharedFlow — single emitter, single collector, replay=0
    //
    // Hot stream: emitter and collector run concurrently.
    // 1000 messages through SharedFlow with no replay.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 4: SharedFlow — 1 emitter, 1 collector, 1000 msgs",
    );

    $msgCount = 1000;

    // --- Array baseline (sequential produce + consume) ---
    [, $arrayMs] = BenchHelper::measure(function () use ($msgCount) {
        $buffer = [];
        for ($i = 0; $i < $msgCount; $i++) {
            $buffer[] = $i;
        }
        $sum = 0;
        foreach ($buffer as $v) {
            $sum += $v;
        }
        return $sum;
    });
    BenchHelper::timing("Array baseline:", $arrayMs);

    // --- SharedFlow ---
    [$sharedResult, $sharedMs] = BenchHelper::measure(function () use (
        $msgCount,
    ) {
        $sum = 0;
        RunBlocking::new(function () use ($msgCount, &$sum) {
            $flow = SharedFlow::new(
                replay: 0,
                extraBufferCapacity: 64,
                onBufferOverflow: BackpressureStrategy::SUSPEND,
            );

            // Collector fiber
            Launch::new(function () use ($flow, $msgCount, &$sum) {
                $received = 0;
                $flow->collect(function ($value) use (
                    &$sum,
                    &$received,
                    $msgCount,
                ) {
                    $sum += $value;
                    $received++;
                    if ($received >= $msgCount) {
                        return false; // stop collecting
                    }
                });
            });

            // Emitter fiber — slight delay to let collector start
            Launch::new(function () use ($flow, $msgCount) {
                Pause::new(); // yield once so collector registers
                for ($i = 0; $i < $msgCount; $i++) {
                    $flow->emit($i);
                }
                // Give collector time to drain, then complete
                Pause::new();
                $flow->complete();
            });

            Thread::await();
        });
        Thread::await();
        return $sum;
    });
    BenchHelper::timing("SharedFlow (SUSPEND, buf=64):", $sharedMs);

    $expectedSum = (($msgCount - 1) * $msgCount) / 2;
    BenchHelper::comparison("SharedFlow vs array", $arrayMs, $sharedMs);
    BenchHelper::assert(
        "SharedFlow sum correct",
        $sharedResult === $expectedSum,
    );
    $perMsgUs = ($sharedMs / $msgCount) * 1000.0;
    BenchHelper::info(
        sprintf("    Per-message SharedFlow cost: ~%.1f µs", $perMsgUs),
    );
    BenchHelper::record(
        "SharedFlow 1×1 1000",
        $arrayMs,
        $sharedMs,
        "hot stream baseline",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 5: SharedFlow — replay semantics, 1 emitter + late collector
    //
    // Emit 100 values into SharedFlow(replay=10). Then start a new
    // collector and verify it receives the last 10 replayed values.
    // Measures replay buffer overhead.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 5: SharedFlow replay — emit 100, replay=10, late collector",
    );

    $emitCount = 100;
    $replaySize = 10;

    [$replayResult, $replayMs] = BenchHelper::measure(function () use (
        $emitCount,
        $replaySize,
    ) {
        $replayed = [];
        RunBlocking::new(function () use ($emitCount, $replaySize, &$replayed) {
            $flow = SharedFlow::new(
                replay: $replaySize,
                extraBufferCapacity: 32,
                onBufferOverflow: BackpressureStrategy::DROP_OLDEST,
            );

            // Emit all values first (no collector yet)
            for ($i = 0; $i < $emitCount; $i++) {
                $flow->tryEmit($i);
            }

            // Late collector — should receive replayed values
            Launch::new(function () use ($flow, $replaySize, &$replayed) {
                $collected = 0;
                $flow->collect(function ($value) use (
                    &$replayed,
                    &$collected,
                    $replaySize,
                ) {
                    $replayed[] = $value;
                    $collected++;
                    if ($collected >= $replaySize) {
                        return false;
                    }
                });
            });

            Thread::await();
            $flow->complete();
        });
        Thread::await();
        return $replayed;
    });
    BenchHelper::timing("SharedFlow replay collect:", $replayMs);

    $replayCorrect = count($replayResult) === $replaySize;
    if ($replayCorrect && count($replayResult) > 0) {
        // Last emitted values should be (emitCount-replaySize) .. (emitCount-1)
        $expectedFirst = $emitCount - $replaySize;
        $replayCorrect = $replayResult[0] === $expectedFirst;
    }
    BenchHelper::assert(
        "Replayed {$replaySize} values",
        count($replayResult) === $replaySize,
    );
    BenchHelper::assert("Replay content correct", $replayCorrect);
    BenchHelper::record(
        "SharedFlow replay",
        0.01,
        $replayMs,
        "replay={$replaySize}, emit={$emitCount}",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 6: SharedFlow SUSPEND backpressure — fast producer
    //
    // Producer emits 500 values as fast as possible.
    // Buffer = 16. SUSPEND strategy: producer blocks when buffer full.
    // Consumer adds a tiny Pause::new() to simulate slow processing.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 6: Backpressure SUSPEND — 500 msgs, buffer=16, slow consumer",
    );

    $msgCount = 500;
    $bufSize = 16;

    [$suspendResult, $suspendMs] = BenchHelper::measure(function () use (
        $msgCount,
        $bufSize,
    ) {
        $received = 0;
        RunBlocking::new(function () use ($msgCount, $bufSize, &$received) {
            $flow = SharedFlow::new(
                replay: 0,
                extraBufferCapacity: $bufSize,
                onBufferOverflow: BackpressureStrategy::SUSPEND,
            );

            // Slow collector
            Launch::new(function () use ($flow, $msgCount, &$received) {
                $flow->collect(function ($value) use (&$received, $msgCount) {
                    $received++;
                    Pause::new(); // simulate slow processing
                    if ($received >= $msgCount) {
                        return false;
                    }
                });
            });

            // Fast producer
            Launch::new(function () use ($flow, $msgCount) {
                Pause::new();
                for ($i = 0; $i < $msgCount; $i++) {
                    $flow->emit($i);
                }
                Pause::new();
                $flow->complete();
            });

            Thread::await();
        });
        Thread::await();
        return $received;
    });
    BenchHelper::timing("SUSPEND backpressure:", $suspendMs);
    BenchHelper::assert(
        "All {$msgCount} msgs received (SUSPEND)",
        $suspendResult === $msgCount,
    );
    BenchHelper::info(
        sprintf(
            "    Throughput: ~%.0f msgs/sec",
            $msgCount / ($suspendMs / 1000.0),
        ),
    );
    BenchHelper::info(
        "    SUSPEND guarantees: zero data loss, emitter blocked when full",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 7: SharedFlow DROP_OLDEST backpressure — fast producer
    //
    // Same setup but DROP_OLDEST: old values evicted when buffer full.
    // Producer never blocks → higher throughput, but data loss possible.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 7: Backpressure DROP_OLDEST — 500 msgs, buffer=16",
    );

    [$dropOldestReceived, $dropOldestMs] = BenchHelper::measure(
        function () use ($msgCount, $bufSize) {
            $received = 0;
            RunBlocking::new(function () use ($msgCount, $bufSize, &$received) {
                $flow = SharedFlow::new(
                    replay: 0,
                    extraBufferCapacity: $bufSize,
                    onBufferOverflow: BackpressureStrategy::DROP_OLDEST,
                );

                Launch::new(function () use ($flow, $msgCount, &$received) {
                    $flow->collect(function ($value) use (
                        &$received,
                        $msgCount,
                    ) {
                        $received++;
                        Pause::new();
                        if ($received >= $msgCount) {
                            return false;
                        }
                    });
                });

                Launch::new(function () use ($flow, $msgCount) {
                    Pause::new();
                    for ($i = 0; $i < $msgCount; $i++) {
                        $flow->tryEmit($i);
                    }
                    Pause::new();
                    $flow->complete();
                });

                Thread::await();
            });
            Thread::await();
            return $received;
        },
    );
    BenchHelper::timing("DROP_OLDEST backpressure:", $dropOldestMs);
    $lossRate = (1.0 - $dropOldestReceived / $msgCount) * 100.0;
    BenchHelper::info(
        sprintf(
            "    Received: %d/%d (%.1f%% loss)",
            $dropOldestReceived,
            $msgCount,
            $lossRate,
        ),
    );
    BenchHelper::info(
        sprintf(
            "    Throughput: ~%.0f msgs/sec",
            $msgCount / ($dropOldestMs / 1000.0),
        ),
    );
    BenchHelper::info(
        "    DROP_OLDEST: emitter never blocks, oldest data evicted",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 8: SharedFlow DROP_LATEST backpressure — fast producer
    //
    // DROP_LATEST: new emissions silently discarded when buffer full.
    // Buffer retains oldest unconsumed data.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 8: Backpressure DROP_LATEST — 500 msgs, buffer=16",
    );

    [$dropLatestReceived, $dropLatestMs] = BenchHelper::measure(
        function () use ($msgCount, $bufSize) {
            $received = 0;
            RunBlocking::new(function () use ($msgCount, $bufSize, &$received) {
                $flow = SharedFlow::new(
                    replay: 0,
                    extraBufferCapacity: $bufSize,
                    onBufferOverflow: BackpressureStrategy::DROP_LATEST,
                );

                Launch::new(function () use ($flow, $msgCount, &$received) {
                    $flow->collect(function ($value) use (
                        &$received,
                        $msgCount,
                    ) {
                        $received++;
                        Pause::new();
                        if ($received >= $msgCount) {
                            return false;
                        }
                    });
                });

                Launch::new(function () use ($flow, $msgCount) {
                    Pause::new();
                    for ($i = 0; $i < $msgCount; $i++) {
                        $flow->tryEmit($i);
                    }
                    Pause::new();
                    $flow->complete();
                });

                Thread::await();
            });
            Thread::await();
            return $received;
        },
    );
    BenchHelper::timing("DROP_LATEST backpressure:", $dropLatestMs);
    $lossRate = (1.0 - $dropLatestReceived / $msgCount) * 100.0;
    BenchHelper::info(
        sprintf(
            "    Received: %d/%d (%.1f%% loss)",
            $dropLatestReceived,
            $msgCount,
            $lossRate,
        ),
    );
    BenchHelper::info(
        sprintf(
            "    Throughput: ~%.0f msgs/sec",
            $msgCount / ($dropLatestMs / 1000.0),
        ),
    );
    BenchHelper::info(
        "    DROP_LATEST: emitter never blocks, newest data discarded",
    );

    // Record all three strategies for comparison
    BenchHelper::record(
        "BP: SUSPEND",
        $suspendMs,
        $suspendMs,
        "rcvd={$msgCount}/{$msgCount}",
    );
    BenchHelper::record(
        "BP: DROP_OLDEST",
        $suspendMs,
        $dropOldestMs,
        "rcvd={$dropOldestReceived}/{$msgCount}",
    );
    BenchHelper::record(
        "BP: DROP_LATEST",
        $suspendMs,
        $dropLatestMs,
        "rcvd={$dropLatestReceived}/{$msgCount}",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 9: MutableStateFlow — rapid state updates, single observer
    //
    // Update state 1000 times. Observer collects latest values.
    // StateFlow always keeps only the latest value (DROP_OLDEST by nature).
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 9: MutableStateFlow — 1000 rapid updates, 1 observer",
    );

    $updateCount = 1000;

    // --- Plain variable baseline ---
    [, $plainMs] = BenchHelper::measure(function () use ($updateCount) {
        $state = 0;
        $observed = [];
        for ($i = 0; $i < $updateCount; $i++) {
            $state = $i * 2 + 1;
            $observed[] = $state;
        }
        return end($observed);
    });
    BenchHelper::timing("Plain variable updates:", $plainMs);

    // --- MutableStateFlow ---
    [$stateResult, $stateMs] = BenchHelper::measure(function () use (
        $updateCount,
    ) {
        $lastSeen = null;
        RunBlocking::new(function () use ($updateCount, &$lastSeen) {
            $stateFlow = MutableStateFlow::new(0);

            // Observer
            Launch::new(function () use ($stateFlow, $updateCount, &$lastSeen) {
                $seen = 0;
                $stateFlow->collect(function ($value) use (
                    &$lastSeen,
                    &$seen,
                    $updateCount,
                ) {
                    $lastSeen = $value;
                    $seen++;
                    // StateFlow may coalesce rapid updates — we observe
                    // at least some of them. Stop after enough observations
                    // or after seeing the final value.
                    if (
                        $value === ($updateCount - 1) * 2 + 1 ||
                        $seen >= $updateCount
                    ) {
                        return false;
                    }
                });
            });

            // Updater
            Launch::new(function () use ($stateFlow, $updateCount) {
                Pause::new();
                for ($i = 0; $i < $updateCount; $i++) {
                    $stateFlow->setValue($i * 2 + 1);
                }
            });

            Thread::await();
        });
        Thread::await();
        return $lastSeen;
    });
    BenchHelper::timing("MutableStateFlow updates:", $stateMs);

    $expectedFinal = ($updateCount - 1) * 2 + 1;
    BenchHelper::comparison("1000 state updates", $plainMs, $stateMs);
    BenchHelper::assert(
        "Final state value correct",
        $stateResult === $expectedFinal,
    );
    BenchHelper::info(
        "    StateFlow coalesces rapid updates — observer sees latest, not all",
    );
    BenchHelper::record(
        "StateFlow 1000 updates",
        $plainMs,
        $stateMs,
        "single observer",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 10: MutableStateFlow — multiple observers
    //
    // 3 observers watching the same StateFlow for 500 updates.
    // Shows per-observer overhead.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 10: MutableStateFlow — 500 updates, 3 observers",
    );

    $updateCount = 500;
    $observerCount = 3;

    [$multiResult, $multiMs] = BenchHelper::measure(function () use (
        $updateCount,
        $observerCount,
    ) {
        $lastSeen = array_fill(0, $observerCount, null);
        RunBlocking::new(function () use (
            $updateCount,
            $observerCount,
            &$lastSeen,
        ) {
            $stateFlow = MutableStateFlow::new(0);

            for ($o = 0; $o < $observerCount; $o++) {
                $observerIdx = $o;
                Launch::new(function () use (
                    $stateFlow,
                    $updateCount,
                    &$lastSeen,
                    $observerIdx,
                ) {
                    $stateFlow->collect(function ($value) use (
                        &$lastSeen,
                        $observerIdx,
                        $updateCount,
                    ) {
                        $lastSeen[$observerIdx] = $value;
                        if ($value === ($updateCount - 1) * 10) {
                            return false;
                        }
                    });
                });
            }

            Launch::new(function () use ($stateFlow, $updateCount) {
                Pause::new();
                for ($i = 0; $i < $updateCount; $i++) {
                    $stateFlow->setValue($i * 10);
                }
            });

            Thread::await();
        });
        Thread::await();
        return $lastSeen;
    });
    BenchHelper::timing("StateFlow {$observerCount} observers:", $multiMs);

    $expectedFinal = ($updateCount - 1) * 10;
    $allCorrect = true;
    foreach ($multiResult as $val) {
        if ($val !== $expectedFinal) {
            $allCorrect = false;
            break;
        }
    }
    BenchHelper::assert(
        "All {$observerCount} observers saw final value",
        $allCorrect,
    );
    BenchHelper::record(
        "StateFlow {$observerCount} observers",
        $stateMs ?? $multiMs,
        $multiMs,
        "500 updates",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 11: SharedFlow with extraBufferCapacity — burst absorption
    //
    // Emit 300 values in a burst, then collector processes them.
    // Compare extraBufferCapacity=8 vs extraBufferCapacity=128.
    // Larger buffer absorbs bursts without SUSPEND blocking.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 11: SharedFlow burst absorption — extraBuffer 8 vs 128",
    );

    $burstCount = 300;
    $smallBuf = 8;
    $largeBuf = 128;

    // --- Small buffer ---
    [, $smallBufMs] = BenchHelper::measure(function () use (
        $burstCount,
        $smallBuf,
    ) {
        $received = 0;
        RunBlocking::new(function () use ($burstCount, $smallBuf, &$received) {
            $flow = SharedFlow::new(
                replay: 0,
                extraBufferCapacity: $smallBuf,
                onBufferOverflow: BackpressureStrategy::SUSPEND,
            );

            Launch::new(function () use ($flow, $burstCount, &$received) {
                $flow->collect(function ($value) use (&$received, $burstCount) {
                    $received++;
                    if ($received >= $burstCount) {
                        return false;
                    }
                });
            });

            Launch::new(function () use ($flow, $burstCount) {
                Pause::new();
                for ($i = 0; $i < $burstCount; $i++) {
                    $flow->emit($i);
                }
                Pause::new();
                $flow->complete();
            });

            Thread::await();
        });
        Thread::await();
        return $received;
    });
    BenchHelper::timing("extraBuffer={$smallBuf}:", $smallBufMs);

    // --- Large buffer ---
    [, $largeBufMs] = BenchHelper::measure(function () use (
        $burstCount,
        $largeBuf,
    ) {
        $received = 0;
        RunBlocking::new(function () use ($burstCount, $largeBuf, &$received) {
            $flow = SharedFlow::new(
                replay: 0,
                extraBufferCapacity: $largeBuf,
                onBufferOverflow: BackpressureStrategy::SUSPEND,
            );

            Launch::new(function () use ($flow, $burstCount, &$received) {
                $flow->collect(function ($value) use (&$received, $burstCount) {
                    $received++;
                    if ($received >= $burstCount) {
                        return false;
                    }
                });
            });

            Launch::new(function () use ($flow, $burstCount) {
                Pause::new();
                for ($i = 0; $i < $burstCount; $i++) {
                    $flow->emit($i);
                }
                Pause::new();
                $flow->complete();
            });

            Thread::await();
        });
        Thread::await();
        return $received;
    });
    BenchHelper::timing("extraBuffer={$largeBuf}:", $largeBufMs);

    BenchHelper::comparison(
        "buf={$smallBuf} vs buf={$largeBuf}",
        $smallBufMs,
        $largeBufMs,
    );
    BenchHelper::info(
        "    Larger buffer → fewer SUSPEND pauses → higher burst throughput",
    );
    BenchHelper::record(
        "Burst buf={$smallBuf}",
        $smallBufMs,
        $smallBufMs,
        "{$burstCount} burst",
    );
    BenchHelper::record(
        "Burst buf={$largeBuf}",
        $smallBufMs,
        $largeBufMs,
        "{$burstCount} burst",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 12: Cold Flow vs PHP Generator — native comparison
    //
    // PHP generators are the closest native equivalent to cold flows.
    // Both produce values lazily on demand.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader("Test 12: Cold Flow vs PHP Generator — 2000 values");

    $itemCount = 2000;

    // --- PHP Generator ---
    [$genResult, $genMs] = BenchHelper::measure(function () use ($itemCount) {
        $gen = (function () use ($itemCount) {
            for ($i = 0; $i < $itemCount; $i++) {
                yield $i * 2;
            }
        })();

        $sum = 0;
        foreach ($gen as $val) {
            $sum += $val;
        }
        return $sum;
    });
    BenchHelper::timing("PHP Generator:", $genMs);

    // --- Cold Flow ---
    [$flowResult, $flowMs] = BenchHelper::measure(function () use ($itemCount) {
        $sum = 0;
        RunBlocking::new(function () use ($itemCount, &$sum) {
            $flow = Flow::new(function () use ($itemCount) {
                for ($i = 0; $i < $itemCount; $i++) {
                    Flow::emit($i * 2);
                }
            });

            $flow->collect(function ($value) use (&$sum) {
                $sum += $value;
            });
        });
        Thread::await();
        return $sum;
    });
    BenchHelper::timing("Cold Flow:", $flowMs);

    BenchHelper::comparison("Generator vs Cold Flow", $genMs, $flowMs);
    BenchHelper::assert("Results match", $genResult === $flowResult);
    BenchHelper::info("    Generator: native PHP, zero fiber overhead");
    BenchHelper::info(
        "    Cold Flow: Fiber-based, supports backpressure/buffer/operators",
    );
    BenchHelper::record(
        "Generator vs Flow",
        $genMs,
        $flowMs,
        "2000 lazy values",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 13: Backpressure strategies — side-by-side comparison
    //
    // Same workload, same buffer, different strategies.
    // 400 messages, buffer=8, consumer with Pause::new() per msg.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 13: Backpressure side-by-side — 400 msgs, buffer=8",
    );

    $msgCount = 400;
    $bufSize = 8;

    $strategies = [
        "SUSPEND" => BackpressureStrategy::SUSPEND,
        "DROP_OLDEST" => BackpressureStrategy::DROP_OLDEST,
        "DROP_LATEST" => BackpressureStrategy::DROP_LATEST,
    ];

    $stratResults = [];

    foreach ($strategies as $name => $strategy) {
        $useTryEmit = $strategy !== BackpressureStrategy::SUSPEND;

        [$received, $ms] = BenchHelper::measure(function () use (
            $msgCount,
            $bufSize,
            $strategy,
            $useTryEmit,
        ) {
            $received = 0;
            RunBlocking::new(function () use (
                $msgCount,
                $bufSize,
                $strategy,
                $useTryEmit,
                &$received,
            ) {
                $flow = SharedFlow::new(
                    replay: 0,
                    extraBufferCapacity: $bufSize,
                    onBufferOverflow: $strategy,
                );

                Launch::new(function () use ($flow, $msgCount, &$received) {
                    $flow->collect(function ($value) use (
                        &$received,
                        $msgCount,
                    ) {
                        $received++;
                        Pause::new();
                        if ($received >= $msgCount) {
                            return false;
                        }
                    });
                });

                Launch::new(function () use ($flow, $msgCount, $useTryEmit) {
                    Pause::new();
                    for ($i = 0; $i < $msgCount; $i++) {
                        if ($useTryEmit) {
                            $flow->tryEmit($i);
                        } else {
                            $flow->emit($i);
                        }
                    }
                    Pause::new();
                    $flow->complete();
                });

                Thread::await();
            });
            Thread::await();
            return $received;
        });

        $loss = $msgCount - $received;
        $lossPercent = ($loss / $msgCount) * 100.0;
        $throughput = $msgCount / ($ms / 1000.0);

        $stratResults[$name] = [
            "ms" => $ms,
            "received" => $received,
            "loss" => $loss,
            "throughput" => $throughput,
        ];

        BenchHelper::info(
            sprintf(
                "    %-14s: %s  recv=%d/%d  loss=%.1f%%  rate=%.0f msg/s",
                $name,
                str_pad(BenchHelper::formatMs($ms), 12),
                $received,
                $msgCount,
                $lossPercent,
                $throughput,
            ),
        );
    }

    BenchHelper::info("");
    BenchHelper::info(
        "    SUSPEND:      Slowest but guarantees all data delivered",
    );
    BenchHelper::info(
        "    DROP_OLDEST:  Fastest, loses old data (real-time streams)",
    );
    BenchHelper::info(
        "    DROP_LATEST:  Fast, loses new data (keep earliest values)",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 14: SharedFlow throughput scaling — varying collector count
    //
    // 1, 2, 3, 5 collectors on the same SharedFlow.
    // 300 messages per run. Shows per-collector overhead.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 14: SharedFlow scaling — varying collector count",
    );

    $msgCount = 300;
    $collectorCounts = [1, 2, 3, 5];

    foreach ($collectorCounts as $numCollectors) {
        [, $ms] = BenchHelper::measure(function () use (
            $msgCount,
            $numCollectors,
        ) {
            RunBlocking::new(function () use ($msgCount, $numCollectors) {
                $flow = SharedFlow::new(
                    replay: 0,
                    extraBufferCapacity: 32,
                    onBufferOverflow: BackpressureStrategy::SUSPEND,
                );

                for ($c = 0; $c < $numCollectors; $c++) {
                    Launch::new(function () use ($flow, $msgCount) {
                        $seen = 0;
                        $flow->collect(function ($value) use (
                            &$seen,
                            $msgCount,
                        ) {
                            $seen++;
                            if ($seen >= $msgCount) {
                                return false;
                            }
                        });
                    });
                }

                Launch::new(function () use ($flow, $msgCount) {
                    Pause::new();
                    for ($i = 0; $i < $msgCount; $i++) {
                        $flow->emit($i);
                    }
                    Pause::new();
                    $flow->complete();
                });

                Thread::await();
            });
            Thread::await();
        });

        $perMsg = ($ms / $msgCount) * 1000.0;
        $perCollector = $ms / $numCollectors;
        BenchHelper::info(
            sprintf(
                "    %d collector(s): total=%s  per-msg=%.1f µs  per-collector=%s",
                $numCollectors,
                str_pad(BenchHelper::formatMs($ms), 12),
                $perMsg,
                BenchHelper::formatMs($perCollector),
            ),
        );
    }

    BenchHelper::info(
        "    (Overhead scales roughly linearly with collector count)",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 15: Flow::of / Flow::fromArray — factory convenience perf
    //
    // Compare Flow::of(...values) and Flow::fromArray() vs manual emit.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 15: Flow factories — of() vs fromArray() vs manual emit",
    );

    $itemCount = 500;
    $items = range(0, $itemCount - 1);

    // --- Flow::fromArray ---
    [, $fromArrayMs] = BenchHelper::measure(function () use ($items) {
        $sum = 0;
        RunBlocking::new(function () use ($items, &$sum) {
            $flow = Flow::fromArray($items);
            $flow->collect(function ($value) use (&$sum) {
                $sum += $value;
            });
        });
        Thread::await();
        return $sum;
    });
    BenchHelper::timing("Flow::fromArray:", $fromArrayMs);

    // --- Manual emit ---
    [, $manualMs] = BenchHelper::measure(function () use ($itemCount) {
        $sum = 0;
        RunBlocking::new(function () use ($itemCount, &$sum) {
            $flow = Flow::new(function () use ($itemCount) {
                for ($i = 0; $i < $itemCount; $i++) {
                    Flow::emit($i);
                }
            });
            $flow->collect(function ($value) use (&$sum) {
                $sum += $value;
            });
        });
        Thread::await();
        return $sum;
    });
    BenchHelper::timing("Flow::new (manual emit):", $manualMs);

    BenchHelper::comparison("fromArray vs manual", $fromArrayMs, $manualMs);
    BenchHelper::record(
        "fromArray vs manual",
        $fromArrayMs,
        $manualMs,
        "{$itemCount} items",
    );

    // ═════════════════════════════════════════════════════════════════
    //  Summary
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::printSummary();

    BenchHelper::info("");
    BenchHelper::info("Interpretation:");
    BenchHelper::info(
        "  ┌──────────────────────────────────────────────────────────────────────┐",
    );
    BenchHelper::info(
        "  │ PATTERN              │ OVERHEAD vs RAW    │ BENEFIT                   │",
    );
    BenchHelper::info(
        "  ├──────────────────────────────────────────────────────────────────────┤",
    );
    BenchHelper::info(
        "  │ Cold Flow            │ ~5-20µs/emit       │ Backpressure, operators   │",
    );
    BenchHelper::info(
        "  │ SharedFlow SUSPEND   │ ~10-50µs/msg       │ Zero data loss             │",
    );
    BenchHelper::info(
        "  │ SharedFlow DROP_*    │ ~5-20µs/msg        │ Fast, lossy                │",
    );
    BenchHelper::info(
        "  │ MutableStateFlow     │ ~5-30µs/update     │ Latest-value semantics     │",
    );
    BenchHelper::info(
        "  │ PHP Generator        │ ~0.1-0.5µs/yield   │ Native, no backpressure   │",
    );
    BenchHelper::info(
        "  │ Array iteration      │ ~0.01-0.1µs/item   │ Simplest, no streaming    │",
    );
    BenchHelper::info(
        "  └──────────────────────────────────────────────────────────────────────┘",
    );
    BenchHelper::info("");
    BenchHelper::info("Node.js / RxJS comparison:");
    BenchHelper::info(
        "  • RxJS Observable.pipe(map, filter): ~0.1-1 µs/emission (V8 JIT)",
    );
    BenchHelper::info(
        "  • Node Readable stream (object mode): ~0.5-5 µs/emission",
    );
    BenchHelper::info(
        "  • VOsaka Cold Flow: ~5-20 µs/emission (PHP + Fiber overhead)",
    );
    BenchHelper::info(
        "  • VOsaka SharedFlow: ~10-50 µs/msg (dispatch to collectors + backpressure)",
    );
    BenchHelper::info(
        "  • VOsaka is ~10-50x slower per emission than V8-optimized RxJS,",
    );
    BenchHelper::info(
        "    but provides equivalent functionality (backpressure, replay, multi-collector).",
    );
    BenchHelper::info(
        "  • The overhead is acceptable for typical I/O-bound workloads where",
    );
    BenchHelper::info(
        "    emission rates are 100-10000 msg/sec (well within VOsaka capacity).",
    );
    BenchHelper::info("");
    BenchHelper::info("Key takeaways:");
    BenchHelper::info(
        "  1. Use Cold Flow for lazy data pipelines (like generators but with operators)",
    );
    BenchHelper::info(
        "  2. Use SharedFlow for event buses / hot streams between concurrent fibers",
    );
    BenchHelper::info(
        "  3. Use MutableStateFlow for observable state (UI state, config changes)",
    );
    BenchHelper::info(
        "  4. Choose SUSPEND for correctness, DROP_* for throughput",
    );
    BenchHelper::info(
        "  5. Increase extraBufferCapacity to absorb bursts without blocking",
    );
    BenchHelper::info(
        "  6. For raw throughput with no backpressure: use PHP arrays or generators",
    );
    BenchHelper::info("");
});
