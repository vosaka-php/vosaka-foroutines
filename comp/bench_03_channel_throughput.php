<?php

/**
 * Benchmark 03: Channel Throughput
 *
 * Compares traditional PHP data-passing patterns (arrays, queues, shared variables)
 * vs VOsaka Channel-based communication between concurrent fibers.
 *
 * Scenarios:
 *
 *   1. Single-producer / single-consumer — buffered channel vs array accumulation
 *   2. Multiple-producer / single-consumer — fan-in pattern
 *   3. Pipeline pattern — data flows through a chain of processing stages
 *   4. Channel backpressure — how a full buffer affects throughput
 *   5. Large message throughput — sending big payloads through channels
 *   6. trySend/tryReceive non-blocking overhead
 *   7. Channel creation/teardown cost
 *   8. SplQueue vs Channel for in-process queuing
 *   9. High-frequency small messages — latency under load
 *  10. Pipeline with simulated I/O latency
 *  11. Throughput scaling — varying message counts
 *  12. Channel with mixed data types
 *
 * IMPORTANT NOTE on Channel + Launch compatibility:
 *   Channel::send() / Channel::receive() use direct Fiber::suspend()/resume()
 *   internally, which conflicts with the Launch scheduler's own fiber management.
 *   When using channels inside Launch::new() fibers, we MUST use the non-blocking
 *   trySend() / tryReceive() methods combined with Pause::new() for cooperative
 *   scheduling. This avoids the "Cannot resume a fiber that is not suspended"
 *   FiberError that occurs when both the Channel and Launch try to manage the
 *   same fiber's lifecycle.
 *
 * Why this matters:
 *   In Node.js, inter-task communication is essentially free (shared memory,
 *   closures capture variables by reference, or postMessage for workers).
 *   In VOsaka, in-process channels use trySend/tryReceive + Pause for cooperative
 *   scheduling inside Launch fibers, and inter-process channels use shared memory
 *   (shmop) + Mutex + serialization. This benchmark quantifies those costs.
 *
 * Expected results:
 *   - In-process channels: small overhead vs direct array/SplQueue (Pause + polling)
 *   - Pipeline pattern: async can overlap stages, blocking cannot
 *   - Node.js comparison: postMessage structured clone is ~10-50µs per message;
 *     VOsaka in-process channel trySend/tryReceive is ~1-10µs (comparable)
 */

require __DIR__ . "/../vendor/autoload.php";
require __DIR__ . "/BenchHelper.php";

use comp\BenchHelper;
use vosaka\foroutines\Async;
use vosaka\foroutines\channel\Channel;
use vosaka\foroutines\channel\Channels;
use vosaka\foroutines\Delay;
use vosaka\foroutines\Dispatchers;
use vosaka\foroutines\Launch;
use vosaka\foroutines\Pause;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Thread;

use function vosaka\foroutines\main;

// ─── Helper: cooperative channel send inside a Launch fiber ──────────────
// Spins on trySend + Pause::new() until the value is accepted or channel closed.
function coopSend(Channel $ch, mixed $value): bool
{
    while (true) {
        if ($ch->isClosed()) {
            return false;
        }
        if ($ch->trySend($value)) {
            return true;
        }
        // Buffer full — yield to let consumer drain
        Pause::new();
    }
}

// ─── Helper: cooperative channel receive inside a Launch fiber ───────────
// Spins on tryReceive + Pause::new() until a value is available or channel
// is closed and empty.
function coopReceive(Channel $ch): mixed
{
    while (true) {
        $val = $ch->tryReceive();
        if ($val !== null) {
            return $val;
        }
        if ($ch->isClosed() && $ch->isEmpty()) {
            return null; // channel closed and drained
        }
        // No data yet — yield to let producer send
        Pause::new();
    }
}

main(function () {
    // Ensure Launch static state is initialized before any RunBlocking call
    Launch::getInstance();

    BenchHelper::header("Benchmark 03: Channel Throughput");
    BenchHelper::info(
        "Traditional PHP data-passing vs VOsaka Channel communication",
    );
    BenchHelper::info("PHP " . PHP_VERSION . " | " . PHP_OS);
    BenchHelper::info(
        "Using trySend/tryReceive + Pause::new() for Launch-compatible channels",
    );
    BenchHelper::separator();

    // ═════════════════════════════════════════════════════════════════
    // Test 1: Single-producer / single-consumer — 1000 integer messages
    //
    // Blocking approach: Producer fills an array, consumer reads it after.
    // Async approach: Producer sends to channel, consumer receives concurrently.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 1: Single-producer / single-consumer — 1000 ints",
    );

    $msgCount = 1000;

    // --- Blocking: array accumulate then iterate ---
    [, $blockingMs] = BenchHelper::measure(function () use ($msgCount) {
        $buffer = [];
        // "Produce"
        for ($i = 0; $i < $msgCount; $i++) {
            $buffer[] = $i * 2;
        }
        // "Consume"
        $sum = 0;
        foreach ($buffer as $val) {
            $sum += $val;
        }
        return $sum;
    });
    BenchHelper::timing("Blocking (array accumulate):", $blockingMs);

    // --- Async: channel trySend/tryReceive in two fibers ---
    [$asyncResult, $asyncMs] = BenchHelper::measure(function () use (
        $msgCount,
    ) {
        $sum = 0;
        RunBlocking::new(function () use ($msgCount, &$sum) {
            $ch = Channels::createBuffered(64);

            // Producer fiber
            Launch::new(function () use ($ch, $msgCount) {
                for ($i = 0; $i < $msgCount; $i++) {
                    coopSend($ch, $i * 2);
                }
                $ch->close();
            });

            // Consumer fiber
            Launch::new(function () use ($ch, $msgCount, &$sum) {
                $received = 0;
                while ($received < $msgCount) {
                    $val = coopReceive($ch);
                    if ($val === null) {
                        break; // channel closed
                    }
                    $sum += $val;
                    $received++;
                }
            });

            Thread::await();
        });
        Thread::await();
        return $sum;
    });
    BenchHelper::timing("Async (channel trySend/tryRecv):", $asyncMs);

    $expectedSum = 0;
    for ($i = 0; $i < $msgCount; $i++) {
        $expectedSum += $i * 2;
    }
    BenchHelper::comparison("1000 int messages", $blockingMs, $asyncMs);
    BenchHelper::assert("Sum is correct", $asyncResult === $expectedSum);
    BenchHelper::info(
        "    Per-message channel cost: ~" .
            BenchHelper::formatMs($asyncMs / $msgCount),
    );
    BenchHelper::record(
        "1×1 channel 1000 ints",
        $blockingMs,
        $asyncMs,
        "basic trySend/tryRecv",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 2: Multiple-producer / single-consumer (fan-in)
    // 5 producers, each sends 200 messages → 1 consumer receives 1000 total
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 2: Fan-in — 5 producers × 200 msgs → 1 consumer",
    );

    $producerCount = 5;
    $msgsPerProducer = 200;
    $totalMsgs = $producerCount * $msgsPerProducer;

    // --- Blocking: 5 arrays merged ---
    [, $blockingMs] = BenchHelper::measure(function () use (
        $producerCount,
        $msgsPerProducer,
    ) {
        $allData = [];
        for ($p = 0; $p < $producerCount; $p++) {
            $batch = [];
            for ($i = 0; $i < $msgsPerProducer; $i++) {
                $batch[] = $p * $msgsPerProducer + $i;
            }
            $allData = array_merge($allData, $batch);
        }
        $sum = array_sum($allData);
        return $sum;
    });
    BenchHelper::timing("Blocking (array merge):", $blockingMs);

    // --- Async: 5 producer fibers → 1 channel → 1 consumer fiber ---
    [$asyncResult, $asyncMs] = BenchHelper::measure(function () use (
        $producerCount,
        $msgsPerProducer,
        $totalMsgs,
    ) {
        $sum = 0;
        $producersDone = 0;
        RunBlocking::new(function () use (
            $producerCount,
            $msgsPerProducer,
            $totalMsgs,
            &$sum,
            &$producersDone,
        ) {
            $ch = Channels::createBuffered(64);

            for ($p = 0; $p < $producerCount; $p++) {
                Launch::new(function () use (
                    $ch,
                    $p,
                    $msgsPerProducer,
                    $producerCount,
                    &$producersDone,
                ) {
                    for ($i = 0; $i < $msgsPerProducer; $i++) {
                        coopSend($ch, $p * $msgsPerProducer + $i);
                    }
                    $producersDone++;
                    // Last producer closes the channel
                    if ($producersDone >= $producerCount) {
                        $ch->close();
                    }
                });
            }

            // Consumer
            Launch::new(function () use ($ch, $totalMsgs, &$sum) {
                $received = 0;
                while ($received < $totalMsgs) {
                    $val = coopReceive($ch);
                    if ($val === null) {
                        break;
                    }
                    $sum += $val;
                    $received++;
                }
            });

            Thread::await();
        });
        Thread::await();
        return $sum;
    });
    BenchHelper::timing("Async (5 producers → channel):", $asyncMs);

    BenchHelper::comparison("Fan-in 5→1", $blockingMs, $asyncMs);
    BenchHelper::record(
        "Fan-in 5→1 (1000)",
        $blockingMs,
        $asyncMs,
        "multiple producers",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 3: Pipeline pattern — 3 stages, 500 messages
    //
    // Stage 1: generate numbers 0..499
    // Stage 2: multiply by 3
    // Stage 3: sum results
    //
    // Blocking: sequential processing through each stage.
    // Async: stages run as concurrent fibers connected by channels.
    //        Stages can overlap: while stage 2 processes msg N,
    //        stage 1 can produce msg N+1.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader("Test 3: Pipeline — 3 stages × 500 messages");

    $pipelineCount = 500;

    // --- Blocking: sequential pipeline ---
    [$blockingResult, $blockingMs] = BenchHelper::measure(function () use (
        $pipelineCount,
    ) {
        // Stage 1: generate
        $stage1 = [];
        for ($i = 0; $i < $pipelineCount; $i++) {
            $stage1[] = $i;
        }
        // Stage 2: transform (multiply by 3)
        $stage2 = [];
        foreach ($stage1 as $val) {
            $stage2[] = $val * 3;
        }
        // Stage 3: aggregate (sum)
        return array_sum($stage2);
    });
    BenchHelper::timing("Blocking (sequential pipeline):", $blockingMs);

    // --- Async: concurrent pipeline via channels ---
    [$asyncResult, $asyncMs] = BenchHelper::measure(function () use (
        $pipelineCount,
    ) {
        $finalSum = 0;
        RunBlocking::new(function () use ($pipelineCount, &$finalSum) {
            $ch1 = Channels::createBuffered(32);
            $ch2 = Channels::createBuffered(32);

            // Stage 1: generate → ch1
            Launch::new(function () use ($ch1, $pipelineCount) {
                for ($i = 0; $i < $pipelineCount; $i++) {
                    coopSend($ch1, $i);
                }
                $ch1->close();
            });

            // Stage 2: ch1 → transform → ch2
            Launch::new(function () use ($ch1, $ch2, $pipelineCount) {
                $processed = 0;
                while ($processed < $pipelineCount) {
                    $val = coopReceive($ch1);
                    if ($val === null) {
                        break;
                    }
                    coopSend($ch2, $val * 3);
                    $processed++;
                }
                $ch2->close();
            });

            // Stage 3: ch2 → aggregate
            Launch::new(function () use ($ch2, $pipelineCount, &$finalSum) {
                $processed = 0;
                while ($processed < $pipelineCount) {
                    $val = coopReceive($ch2);
                    if ($val === null) {
                        break;
                    }
                    $finalSum += $val;
                    $processed++;
                }
            });

            Thread::await();
        });
        Thread::await();
        return $finalSum;
    });
    BenchHelper::timing("Async (channel pipeline):", $asyncMs);

    BenchHelper::comparison("3-stage pipeline", $blockingMs, $asyncMs);
    BenchHelper::assert(
        "Pipeline result matches",
        $blockingResult === $asyncResult,
    );
    BenchHelper::record(
        "Pipeline 3×500",
        $blockingMs,
        $asyncMs,
        "stage overlap",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 4: Buffer size impact on throughput
    // Same workload (500 messages), different buffer capacities.
    // Shows how buffer size affects channel performance.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 4: Buffer size impact — 500 messages, various capacities",
    );

    $bufferSizes = [1, 8, 32, 128, 512];
    $msgCount = 500;

    foreach ($bufferSizes as $bufSize) {
        [, $ms] = BenchHelper::measure(function () use ($msgCount, $bufSize) {
            RunBlocking::new(function () use ($msgCount, $bufSize) {
                $ch = Channels::createBuffered($bufSize);

                Launch::new(function () use ($ch, $msgCount) {
                    for ($i = 0; $i < $msgCount; $i++) {
                        coopSend($ch, $i);
                    }
                    $ch->close();
                });

                Launch::new(function () use ($ch, $msgCount) {
                    $count = 0;
                    while ($count < $msgCount) {
                        $val = coopReceive($ch);
                        if ($val === null) {
                            break;
                        }
                        $count++;
                    }
                });

                Thread::await();
            });
            Thread::await();
        });

        $perMsg = $ms / $msgCount;
        BenchHelper::info(
            sprintf(
                "    buffer=%4d: total=%s  per-msg=%s",
                $bufSize,
                str_pad(BenchHelper::formatMs($ms), 12),
                BenchHelper::formatMs($perMsg),
            ),
        );
    }
    BenchHelper::info(
        "    (Larger buffers reduce Pause::new() yield frequency)",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 5: Large message throughput — sending big string payloads
    // 100 messages × 4KB each through a channel
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader("Test 5: Large messages — 100 × 4KB strings");

    $msgCount = 100;
    $payloadSize = 4096;

    // --- Blocking: array of strings ---
    [, $blockingMs] = BenchHelper::measure(function () use (
        $msgCount,
        $payloadSize,
    ) {
        $buffer = [];
        for ($i = 0; $i < $msgCount; $i++) {
            $buffer[] = str_repeat("X", $payloadSize);
        }
        $totalLen = 0;
        foreach ($buffer as $msg) {
            $totalLen += strlen($msg);
        }
        return $totalLen;
    });
    BenchHelper::timing("Blocking (array of strings):", $blockingMs);

    // --- Async: channel ---
    [$asyncResult, $asyncMs] = BenchHelper::measure(function () use (
        $msgCount,
        $payloadSize,
    ) {
        $totalLen = 0;
        RunBlocking::new(function () use ($msgCount, $payloadSize, &$totalLen) {
            $ch = Channels::createBuffered(16);

            Launch::new(function () use ($ch, $msgCount, $payloadSize) {
                for ($i = 0; $i < $msgCount; $i++) {
                    coopSend($ch, str_repeat("X", $payloadSize));
                }
                $ch->close();
            });

            Launch::new(function () use ($ch, $msgCount, &$totalLen) {
                $received = 0;
                while ($received < $msgCount) {
                    $msg = coopReceive($ch);
                    if ($msg === null) {
                        break;
                    }
                    $totalLen += strlen($msg);
                    $received++;
                }
            });

            Thread::await();
        });
        Thread::await();
        return $totalLen;
    });
    BenchHelper::timing("Async (channel 4KB msgs):", $asyncMs);

    BenchHelper::comparison("100×4KB messages", $blockingMs, $asyncMs);
    BenchHelper::assert(
        "Total bytes correct",
        $asyncResult === $msgCount * $payloadSize,
    );
    $throughputMBps =
        ($msgCount * $payloadSize) / ($asyncMs / 1000.0) / (1024 * 1024);
    BenchHelper::info(
        sprintf("    Channel throughput: ~%.1f MB/s", $throughputMBps),
    );
    BenchHelper::record(
        "100×4KB messages",
        $blockingMs,
        $asyncMs,
        "large payload",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 6: trySend / tryReceive non-blocking overhead
    // 2000 messages using non-blocking try operations (no fibers)
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 6: trySend/tryReceive — 2000 non-blocking ops",
    );

    $msgCount = 2000;

    // --- Blocking: direct SplQueue ---
    [, $blockingMs] = BenchHelper::measure(function () use ($msgCount) {
        $q = new SplQueue();
        for ($i = 0; $i < $msgCount; $i++) {
            $q->enqueue($i);
        }
        $sum = 0;
        while (!$q->isEmpty()) {
            $sum += $q->dequeue();
        }
        return $sum;
    });
    BenchHelper::timing("Blocking (SplQueue):", $blockingMs);

    // --- Channel trySend/tryReceive ---
    [$asyncResult, $asyncMs] = BenchHelper::measure(function () use (
        $msgCount,
    ) {
        $ch = Channels::createBuffered($msgCount);
        for ($i = 0; $i < $msgCount; $i++) {
            $ch->trySend($i);
        }
        $sum = 0;
        for ($i = 0; $i < $msgCount; $i++) {
            $val = $ch->tryReceive();
            if ($val !== null) {
                $sum += $val;
            }
        }
        return $sum;
    });
    BenchHelper::timing("Channel (trySend/tryReceive):", $asyncMs);

    $expectedSum = (($msgCount - 1) * $msgCount) / 2;
    BenchHelper::comparison("2000 non-blocking ops", $blockingMs, $asyncMs);
    BenchHelper::assert("Sum is correct", $asyncResult === $expectedSum);
    BenchHelper::record(
        "2000 try ops",
        $blockingMs,
        $asyncMs,
        "non-blocking channel",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 7: Channel creation/teardown cost
    // Create and immediately close N channels.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader("Test 7: Channel creation/teardown — 500 channels");

    $channelCount = 500;

    // --- Blocking: SplQueue creation ---
    [, $blockingMs] = BenchHelper::measure(function () use ($channelCount) {
        $queues = [];
        for ($i = 0; $i < $channelCount; $i++) {
            $queues[] = new SplQueue();
        }
        return count($queues);
    });
    BenchHelper::timing("Blocking (500 SplQueues):", $blockingMs);

    // --- Channel creation ---
    [, $asyncMs] = BenchHelper::measure(function () use ($channelCount) {
        $channels = [];
        for ($i = 0; $i < $channelCount; $i++) {
            $ch = Channels::createBuffered(8);
            $channels[] = $ch;
        }
        foreach ($channels as $ch) {
            $ch->close();
        }
        return count($channels);
    });
    BenchHelper::timing("Channel (500 create+close):", $asyncMs);

    BenchHelper::comparison("500 channel lifecycle", $blockingMs, $asyncMs);
    BenchHelper::info(
        "    Per-channel cost: ~" .
            BenchHelper::formatMs($asyncMs / $channelCount),
    );
    BenchHelper::record(
        "500 channel create",
        $blockingMs,
        $asyncMs,
        "lifecycle overhead",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 8: SplQueue vs Channel for in-process queuing (same thread)
    // Direct comparison of data structure overhead — no fibers involved,
    // just the channel's internal buffer machinery.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 8: SplQueue vs Channel buffer — 5000 items, no fibers",
    );

    $itemCount = 5000;

    // --- SplQueue ---
    [, $splMs] = BenchHelper::measure(function () use ($itemCount) {
        $q = new SplQueue();
        for ($i = 0; $i < $itemCount; $i++) {
            $q->enqueue($i);
        }
        $sum = 0;
        while (!$q->isEmpty()) {
            $sum += $q->dequeue();
        }
        return $sum;
    });
    BenchHelper::timing("SplQueue:", $splMs);

    // --- Channel (trySend/tryReceive, no fibers) ---
    [, $chMs] = BenchHelper::measure(function () use ($itemCount) {
        $ch = Channels::createBuffered($itemCount);
        for ($i = 0; $i < $itemCount; $i++) {
            $ch->trySend($i);
        }
        $sum = 0;
        for ($i = 0; $i < $itemCount; $i++) {
            $val = $ch->tryReceive();
            if ($val !== null) {
                $sum += $val;
            }
        }
        return $sum;
    });
    BenchHelper::timing("Channel (trySend/tryReceive):", $chMs);

    // --- Plain PHP array ---
    [, $arrMs] = BenchHelper::measure(function () use ($itemCount) {
        $arr = [];
        for ($i = 0; $i < $itemCount; $i++) {
            $arr[] = $i;
        }
        $sum = 0;
        foreach ($arr as $v) {
            $sum += $v;
        }
        return $sum;
    });
    BenchHelper::timing("Plain array:", $arrMs);

    BenchHelper::info(
        sprintf(
            "    Ratios — Channel/SplQueue: %.1fx  Channel/Array: %.1fx  SplQueue/Array: %.1fx",
            $splMs > 0 ? $chMs / $splMs : INF,
            $arrMs > 0 ? $chMs / $arrMs : INF,
            $arrMs > 0 ? $splMs / $arrMs : INF,
        ),
    );
    BenchHelper::record(
        "SplQueue vs Channel",
        $splMs,
        $chMs,
        "data structure overhead",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 9: High-frequency small messages — latency profile
    // 2000 tiny messages (integers) with buffer=16.
    // Measures average and per-message latency under moderate contention.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader("Test 9: High-frequency — 2000 ints, buffer=16");

    $msgCount = 2000;
    $bufSize = 16;

    [, $asyncMs] = BenchHelper::measure(function () use ($msgCount, $bufSize) {
        RunBlocking::new(function () use ($msgCount, $bufSize) {
            $ch = Channels::createBuffered($bufSize);

            Launch::new(function () use ($ch, $msgCount) {
                for ($i = 0; $i < $msgCount; $i++) {
                    coopSend($ch, $i);
                }
                $ch->close();
            });

            Launch::new(function () use ($ch, $msgCount) {
                $received = 0;
                while ($received < $msgCount) {
                    $val = coopReceive($ch);
                    if ($val === null) {
                        break;
                    }
                    $received++;
                }
            });

            Thread::await();
        });
        Thread::await();
    });

    $perMsgUs = ($asyncMs / $msgCount) * 1000.0; // µs per message
    $msgsPerSec = $msgCount / ($asyncMs / 1000.0);
    BenchHelper::timing("Total time:", $asyncMs);
    BenchHelper::info(sprintf("    Per-message latency: ~%.1f µs", $perMsgUs));
    BenchHelper::info(sprintf("    Throughput: ~%.0f msgs/sec", $msgsPerSec));
    BenchHelper::info("");
    BenchHelper::info("    Node.js comparison (estimated):");
    BenchHelper::info(
        "      • EventEmitter: ~0.1-0.5 µs/msg (V8 optimized callbacks)",
    );
    BenchHelper::info(
        "      • Readable stream: ~0.5-2 µs/msg (backpressure overhead)",
    );
    BenchHelper::info(
        "      • worker postMessage: ~10-50 µs/msg (structured clone)",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 10: Pipeline with simulated I/O latency
    //
    // 3-stage pipeline where each stage has a small delay (simulating
    // I/O like DB queries or API calls).
    //
    // Blocking: total = N × (stage1_delay + stage2_delay + stage3_delay)
    // Async: stages overlap → total ≈ N × max(stage_delay) + pipeline fill time
    //
    // This shows the TRUE power of channels: decoupling stages with
    // different processing speeds.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 10: Pipeline with I/O — 10 msgs × 3 stages × 30ms",
    );

    $pipelineMsgs = 10;
    $stageDelayMs = 30;

    // --- Blocking: sequential 3-stage processing ---
    [, $blockingMs] = BenchHelper::measure(function () use (
        $pipelineMsgs,
        $stageDelayMs,
    ) {
        $results = [];
        for ($i = 0; $i < $pipelineMsgs; $i++) {
            // Stage 1: generate
            usleep($stageDelayMs * 1000);
            $val = $i * 10;

            // Stage 2: transform
            usleep($stageDelayMs * 1000);
            $val = $val + 5;

            // Stage 3: finalize
            usleep($stageDelayMs * 1000);
            $results[] = $val * 2;
        }
        return $results;
    });
    BenchHelper::timing("Blocking (sequential stages):", $blockingMs);

    // --- Async: 3 concurrent pipeline stages via channels ---
    [$asyncResults, $asyncMs] = BenchHelper::measure(function () use (
        $pipelineMsgs,
        $stageDelayMs,
    ) {
        $results = [];
        RunBlocking::new(function () use (
            $pipelineMsgs,
            $stageDelayMs,
            &$results,
        ) {
            $ch1 = Channels::createBuffered(4);
            $ch2 = Channels::createBuffered(4);
            $ch3 = Channels::createBuffered(4);

            // Stage 1: generate → ch1
            Launch::new(function () use ($ch1, $pipelineMsgs, $stageDelayMs) {
                for ($i = 0; $i < $pipelineMsgs; $i++) {
                    Delay::new($stageDelayMs);
                    coopSend($ch1, $i * 10);
                }
                $ch1->close();
            });

            // Stage 2: ch1 → transform → ch2
            Launch::new(function () use (
                $ch1,
                $ch2,
                $pipelineMsgs,
                $stageDelayMs,
            ) {
                $processed = 0;
                while ($processed < $pipelineMsgs) {
                    $val = coopReceive($ch1);
                    if ($val === null) {
                        break;
                    }
                    Delay::new($stageDelayMs);
                    coopSend($ch2, $val + 5);
                    $processed++;
                }
                $ch2->close();
            });

            // Stage 3: ch2 → finalize → ch3
            Launch::new(function () use (
                $ch2,
                $ch3,
                $pipelineMsgs,
                $stageDelayMs,
            ) {
                $processed = 0;
                while ($processed < $pipelineMsgs) {
                    $val = coopReceive($ch2);
                    if ($val === null) {
                        break;
                    }
                    Delay::new($stageDelayMs);
                    coopSend($ch3, $val * 2);
                    $processed++;
                }
                $ch3->close();
            });

            // Collector
            Launch::new(function () use ($ch3, $pipelineMsgs, &$results) {
                $collected = 0;
                while ($collected < $pipelineMsgs) {
                    $val = coopReceive($ch3);
                    if ($val === null) {
                        break;
                    }
                    $results[] = $val;
                    $collected++;
                }
            });

            Thread::await();
        });
        Thread::await();
        return $results;
    });
    BenchHelper::timing("Async (pipelined stages):", $asyncMs);

    BenchHelper::comparison("Pipeline with I/O", $blockingMs, $asyncMs);
    BenchHelper::assert(
        "Pipeline produced all results",
        count($asyncResults) === $pipelineMsgs,
    );

    // Verify correctness: expected result for item i = (i*10 + 5) * 2
    $correct = true;
    for ($i = 0; $i < $pipelineMsgs; $i++) {
        $expected = ($i * 10 + 5) * 2;
        if (!isset($asyncResults[$i]) || $asyncResults[$i] !== $expected) {
            $correct = false;
            break;
        }
    }
    BenchHelper::assert("Pipeline results are correct", $correct);

    BenchHelper::info(
        "    Blocking: 10 msgs × 3 stages × 30ms = ~900ms (sequential)",
    );
    BenchHelper::info(
        "    Async:    pipeline fill ~90ms + 10×30ms overlap ≈ ~390ms (ideal)",
    );
    BenchHelper::record(
        "Pipeline + I/O",
        $blockingMs,
        $asyncMs,
        "stage overlap with delays",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 11: Varying message counts — throughput scaling
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 11: Throughput scaling — varying message counts",
    );

    $messageCounts = [100, 500, 1000, 2000, 5000];

    foreach ($messageCounts as $count) {
        [, $ms] = BenchHelper::measure(function () use ($count) {
            RunBlocking::new(function () use ($count) {
                $ch = Channels::createBuffered(64);

                Launch::new(function () use ($ch, $count) {
                    for ($i = 0; $i < $count; $i++) {
                        coopSend($ch, $i);
                    }
                    $ch->close();
                });

                Launch::new(function () use ($ch, $count) {
                    $received = 0;
                    while ($received < $count) {
                        $val = coopReceive($ch);
                        if ($val === null) {
                            break;
                        }
                        $received++;
                    }
                });

                Thread::await();
            });
            Thread::await();
        });

        $perMsg = ($ms / $count) * 1000.0; // µs
        $rate = $count / ($ms / 1000.0);
        BenchHelper::info(
            sprintf(
                "    %5d msgs: total=%s  per-msg=%.1f µs  rate=%.0f msg/s",
                $count,
                str_pad(BenchHelper::formatMs($ms), 12),
                $perMsg,
                $rate,
            ),
        );
    }

    // ═════════════════════════════════════════════════════════════════
    // Test 12: Channel with mixed data types
    // Sends different types to see if serialization/type-checking adds cost
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader("Test 12: Mixed data types — 500 messages");

    $msgCount = 500;

    // Data generator: cycle through different types
    $dataGen = function (int $i): mixed {
        return match ($i % 5) {
            0 => $i, // int
            1 => "message_$i", // string
            2 => $i * 3.14, // float
            3 => ["key" => $i, "val" => $i * 2], // array
            4 => true, // bool
        };
    };

    // --- Blocking ---
    [, $blockingMs] = BenchHelper::measure(function () use (
        $msgCount,
        $dataGen,
    ) {
        $buffer = [];
        for ($i = 0; $i < $msgCount; $i++) {
            $buffer[] = $dataGen($i);
        }
        return count($buffer);
    });
    BenchHelper::timing("Blocking (array mixed types):", $blockingMs);

    // --- Async ---
    [$asyncCount, $asyncMs] = BenchHelper::measure(function () use (
        $msgCount,
        $dataGen,
    ) {
        $count = 0;
        RunBlocking::new(function () use ($msgCount, $dataGen, &$count) {
            $ch = Channels::createBuffered(32);

            Launch::new(function () use ($ch, $msgCount, $dataGen) {
                for ($i = 0; $i < $msgCount; $i++) {
                    coopSend($ch, $dataGen($i));
                }
                $ch->close();
            });

            Launch::new(function () use ($ch, $msgCount, &$count) {
                while ($count < $msgCount) {
                    $val = coopReceive($ch);
                    if ($val === null) {
                        break;
                    }
                    $count++;
                }
            });

            Thread::await();
        });
        Thread::await();
        return $count;
    });
    BenchHelper::timing("Async (channel mixed types):", $asyncMs);

    BenchHelper::comparison("500 mixed-type msgs", $blockingMs, $asyncMs);
    BenchHelper::assert("All messages received", $asyncCount === $msgCount);
    BenchHelper::record(
        "Mixed types 500",
        $blockingMs,
        $asyncMs,
        "int/str/float/array/bool",
    );

    // ═════════════════════════════════════════════════════════════════
    //  Summary
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::printSummary();

    BenchHelper::info("");
    BenchHelper::info("Interpretation:");
    BenchHelper::info(
        "  • In-process channels add ~1-20µs per message vs raw array/SplQueue",
    );
    BenchHelper::info(
        "  • The overhead comes from trySend/tryReceive polling + Pause::new() yields",
    );
    BenchHelper::info(
        "  • Larger buffers reduce Pause::new() frequency → better throughput",
    );
    BenchHelper::info(
        "  • Pipeline pattern with I/O shows real async wins (stage overlap)",
    );
    BenchHelper::info(
        "  • trySend/tryReceive avoid fiber suspend/resume conflicts with Launch scheduler",
    );
    BenchHelper::info("");
    BenchHelper::info("Node.js comparison:");
    BenchHelper::info(
        "  • EventEmitter (same-process): ~0.1-0.5 µs/msg — faster than PHP channels",
    );
    BenchHelper::info(
        "  • Readable/Writable streams: ~0.5-2 µs/msg — comparable to VOsaka channels",
    );
    BenchHelper::info(
        "  • worker_threads postMessage: ~10-50 µs/msg — VOsaka in-process is competitive",
    );
    BenchHelper::info(
        "  • Pipeline overlap benefits are similar in both runtimes",
    );
    BenchHelper::info("");
});
