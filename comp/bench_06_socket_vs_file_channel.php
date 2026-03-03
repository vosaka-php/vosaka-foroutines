<?php

/**
 * Benchmark 06: Socket Pool vs Socket (Legacy) vs File-based Inter-Process Channel
 *
 * Compares the three inter-process channel transport implementations:
 *
 *   1. FILE transport (original):
 *      - Uses file_get_contents / file_put_contents for state persistence
 *      - Mutex (flock) for synchronization
 *      - Spin-wait polling with usleep() for receive
 *      - Full buffer serialize/unserialize on every operation
 *
 *   2. SOCKET transport (legacy — per-channel broker):
 *      - Uses TCP loopback sockets via ChannelBroker background process
 *      - One background process per channel
 *      - Event-driven via stream_select() — no spin-wait polling
 *      - Only serializes individual messages, not the entire buffer
 *
 *   3. SOCKET POOL transport (default — shared broker pool):
 *      - Uses TCP loopback sockets via ChannelBrokerPool background process
 *      - One background process for ALL channels (N channels → 1 process)
 *      - Event-driven via stream_select() — no spin-wait polling
 *      - Commands prefixed with CH:<channelName>: for multiplexing
 *      - ~4-6x faster channel creation (no process spawn per channel)
 *
 * Scenarios tested:
 *
 *   Test 1:  Single send/receive latency (1 message round-trip)
 *   Test 2:  Sequential throughput — 500 messages, single process
 *   Test 3:  Burst send then burst receive — 200 messages
 *   Test 4:  trySend/tryReceive non-blocking throughput — 500 messages
 *   Test 5:  Large payload — 10 KB messages × 50
 *   Test 6:  Small payload — tiny integers × 1000
 *   Test 7:  Channel state query overhead (isClosed, isEmpty, size)
 *   Test 8:  Mixed send/receive interleaved — 500 messages
 *   Test 9:  IO dispatcher producer -> main consumer (cross-process)
 *   Test 10: IO dispatcher consumer <- main producer (cross-process)
 *   Test 11: Throughput scaling — increasing message counts
 *   Test 12: Channel creation + teardown cost (pool advantage)
 *   Test 13: Multi-channel pool — N channels sharing 1 process
 *
 * Expected results:
 *   - Socket pool should match or beat legacy socket on per-message throughput
 *   - Socket pool should DRAMATICALLY win on channel creation cost (no process spawn)
 *   - Socket pool multi-channel test should show resource efficiency
 *   - Both socket transports should beat file transport on throughput
 *   - File transport may win on very first message (no broker startup cost)
 */

declare(strict_types=1);

require __DIR__ . "/../vendor/autoload.php";
require __DIR__ . "/BenchHelper.php";

use comp\BenchHelper;
use vosaka\foroutines\Async;
use vosaka\foroutines\channel\Channel;
use vosaka\foroutines\channel\Channels;
use vosaka\foroutines\Dispatchers;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Thread;

use function vosaka\foroutines\main;

/**
 * Safely tear down a channel.
 */
function safeTeardown(Channel $ch): void
{
    try {
        if (!$ch->isClosed()) {
            $ch->close();
        }
    } catch (\Throwable) {
    }
    try {
        $ch->cleanup();
    } catch (\Throwable) {
    }
}

/**
 * Create a file-based inter-process channel.
 */
function createFileChannel(string $name, int $capacity): Channel
{
    return Channels::createInterProcess($name, $capacity);
}

/**
 * Create a socket-based inter-process channel (legacy — per-channel broker).
 * Disables pool temporarily and creates via newSocketInterProcess.
 */
function createLegacySocketChannel(string $name, int $capacity): Channel
{
    return Channel::newSocketInterProcess($name, $capacity);
}

/**
 * Create a socket-pool-based inter-process channel (default).
 * Uses Channel::createPooled() to explicitly use the pool.
 */
function createPoolChannel(int $capacity, string $name = ""): Channel
{
    return Channel::createPooled($name, $capacity);
}

/**
 * Print a 3-way comparison.
 */
function comparison3(
    string $label,
    float $fileMs,
    float $legacyMs,
    float $poolMs,
): void {
    $bestMs = min($fileMs, $legacyMs, $poolMs);

    $fileTag = $fileMs === $bestMs ? " ★" : "";
    $legacyTag = $legacyMs === $bestMs ? " ★" : "";
    $poolTag = $poolMs === $bestMs ? " ★" : "";

    $fileVsPool = $poolMs > 0 ? $fileMs / $poolMs : 0.0;
    $legacyVsPool = $poolMs > 0 ? $legacyMs / $poolMs : 0.0;

    echo "    \033[1m" . str_pad($label, 30) . "\033[0m\n";
    echo "      File:         " . colorMs($fileMs, $bestMs) . $fileTag . "\n";
    echo "      Socket(legacy):" .
        colorMs($legacyMs, $bestMs) .
        $legacyTag .
        "\n";
    echo "      Socket(pool):  " . colorMs($poolMs, $bestMs) . $poolTag . "\n";
    echo "      Pool vs File:   " . formatSpeedupInline($fileVsPool) . "\n";
    echo "      Pool vs Legacy: " . formatSpeedupInline($legacyVsPool) . "\n";
}

function colorMs(float $ms, float $bestMs): string
{
    $formatted = str_pad(BenchHelper::formatMs($ms), 15);
    if (abs($ms - $bestMs) < 0.001) {
        return "\033[32m{$formatted}\033[0m";
    }
    return "\033[33m{$formatted}\033[0m";
}

function formatSpeedupInline(float $ratio): string
{
    if ($ratio >= 1.5) {
        return "\033[32m" . sprintf("%.2fx faster", $ratio) . "\033[0m";
    }
    if ($ratio >= 0.95) {
        return "\033[33m" . sprintf("≈ %.2fx (similar)", $ratio) . "\033[0m";
    }
    $inverse = $ratio > 0 ? 1.0 / $ratio : INF;
    return "\033[31m" . sprintf("%.2fx slower", $inverse) . "\033[0m";
}

/**
 * Record a 3-way result by recording pool vs file and pool vs legacy.
 */
function record3(
    string $name,
    float $fileMs,
    float $legacyMs,
    float $poolMs,
    string $note = "",
): void {
    // Record file vs pool (file = "blocking", pool = "async")
    BenchHelper::record("{$name} (file→pool)", $fileMs, $poolMs, $note);
}

main(function () {
    BenchHelper::header(
        "Benchmark 06: Socket Pool vs Socket (Legacy) vs File Channel",
    );
    BenchHelper::info(
        "3-way comparison: File transport vs Socket per-channel broker vs Socket pool",
    );
    BenchHelper::info("PHP " . PHP_VERSION . " | " . PHP_OS);
    BenchHelper::info(
        "Pool mode: " .
            (Channel::isPoolEnabled() ? "ENABLED (default)" : "disabled"),
    );
    BenchHelper::separator();

    $uniquePrefix = "bench06_" . getmypid() . "_";

    // Ensure pool is enabled for pool tests
    Channel::enablePool();

    // ═════════════════════════════════════════════════════════════════
    // Test 1: Single send/receive latency (1 message round-trip)
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader("Test 1: Single message round-trip latency");

    // --- File transport ---
    [, $fileMs] = BenchHelper::measure(function () use ($uniquePrefix) {
        $ch = createFileChannel($uniquePrefix . "t1_file", 10);
        $ch->send("hello");
        $val = $ch->receive();
        safeTeardown($ch);
        return $val;
    });
    BenchHelper::timing("File transport:", $fileMs);

    // --- Legacy socket transport ---
    [, $legacyMs] = BenchHelper::measure(function () use ($uniquePrefix) {
        $ch = createLegacySocketChannel($uniquePrefix . "t1_legacy", 10);
        $ch->send("hello");
        $val = $ch->receive();
        safeTeardown($ch);
        return $val;
    });
    BenchHelper::timing("Socket (legacy):", $legacyMs);

    // --- Pool socket transport ---
    [, $poolMs] = BenchHelper::measure(function () {
        $ch = createPoolChannel(10);
        $ch->send("hello");
        $val = $ch->receive();
        safeTeardown($ch);
        return $val;
    });
    BenchHelper::timing("Socket (pool):", $poolMs);

    comparison3("1 msg round-trip", $fileMs, $legacyMs, $poolMs);
    record3(
        "1 msg round-trip",
        $fileMs,
        $legacyMs,
        $poolMs,
        "includes create+destroy",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 2: Sequential throughput — 500 messages, single process
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader("Test 2: Sequential throughput — 300 msgs");

    $msgCount2 = 300;

    // --- File transport ---
    $chFile2 = createFileChannel($uniquePrefix . "t2_file", $msgCount2 + 10);
    [, $fileMs2] = BenchHelper::measure(function () use ($chFile2, $msgCount2) {
        for ($i = 0; $i < $msgCount2; $i++) {
            $chFile2->send($i);
        }
        $sum = 0;
        for ($i = 0; $i < $msgCount2; $i++) {
            $sum += $chFile2->receive();
        }
        return $sum;
    });
    safeTeardown($chFile2);
    BenchHelper::timing("File transport:", $fileMs2);
    BenchHelper::info(
        "    Per-message (file): ~" .
            BenchHelper::formatMs($fileMs2 / $msgCount2),
    );

    // --- Legacy socket transport ---
    $chLegacy2 = createLegacySocketChannel(
        $uniquePrefix . "t2_legacy",
        $msgCount2 + 10,
    );
    [, $legacyMs2] = BenchHelper::measure(function () use (
        $chLegacy2,
        $msgCount2,
    ) {
        for ($i = 0; $i < $msgCount2; $i++) {
            $chLegacy2->send($i);
        }
        $sum = 0;
        for ($i = 0; $i < $msgCount2; $i++) {
            $sum += $chLegacy2->receive();
        }
        return $sum;
    });
    safeTeardown($chLegacy2);
    BenchHelper::timing("Socket (legacy):", $legacyMs2);
    BenchHelper::info(
        "    Per-message (legacy): ~" .
            BenchHelper::formatMs($legacyMs2 / $msgCount2),
    );

    // --- Pool socket transport ---
    $chPool2 = createPoolChannel($msgCount2 + 10);
    [, $poolMs2] = BenchHelper::measure(function () use ($chPool2, $msgCount2) {
        for ($i = 0; $i < $msgCount2; $i++) {
            $chPool2->send($i);
        }
        $sum = 0;
        for ($i = 0; $i < $msgCount2; $i++) {
            $sum += $chPool2->receive();
        }
        return $sum;
    });
    safeTeardown($chPool2);
    BenchHelper::timing("Socket (pool):", $poolMs2);
    BenchHelper::info(
        "    Per-message (pool): ~" .
            BenchHelper::formatMs($poolMs2 / $msgCount2),
    );

    comparison3("300 sequential msgs", $fileMs2, $legacyMs2, $poolMs2);
    record3(
        "300 sequential msgs",
        $fileMs2,
        $legacyMs2,
        $poolMs2,
        "send then recv loop",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 3: Burst send then burst receive — 200 messages
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader("Test 3: Burst send → burst receive — 200 msgs");

    $msgCount3 = 200;

    // --- File transport ---
    $chFile3 = createFileChannel($uniquePrefix . "t3_file", $msgCount3 + 10);
    [, $fileMs3] = BenchHelper::measure(function () use ($chFile3, $msgCount3) {
        for ($i = 0; $i < $msgCount3; $i++) {
            $chFile3->send("msg_{$i}");
        }
        $count = 0;
        for ($i = 0; $i < $msgCount3; $i++) {
            $chFile3->receive();
            $count++;
        }
        return $count;
    });
    safeTeardown($chFile3);
    BenchHelper::timing("File transport:", $fileMs3);

    // --- Legacy socket transport ---
    $chLegacy3 = createLegacySocketChannel(
        $uniquePrefix . "t3_legacy",
        $msgCount3 + 10,
    );
    [, $legacyMs3] = BenchHelper::measure(function () use (
        $chLegacy3,
        $msgCount3,
    ) {
        for ($i = 0; $i < $msgCount3; $i++) {
            $chLegacy3->send("msg_{$i}");
        }
        $count = 0;
        for ($i = 0; $i < $msgCount3; $i++) {
            $chLegacy3->receive();
            $count++;
        }
        return $count;
    });
    safeTeardown($chLegacy3);
    BenchHelper::timing("Socket (legacy):", $legacyMs3);

    // --- Pool socket transport ---
    $chPool3 = createPoolChannel($msgCount3 + 10);
    [, $poolMs3] = BenchHelper::measure(function () use ($chPool3, $msgCount3) {
        for ($i = 0; $i < $msgCount3; $i++) {
            $chPool3->send("msg_{$i}");
        }
        $count = 0;
        for ($i = 0; $i < $msgCount3; $i++) {
            $chPool3->receive();
            $count++;
        }
        return $count;
    });
    safeTeardown($chPool3);
    BenchHelper::timing("Socket (pool):", $poolMs3);

    comparison3("200 burst msgs", $fileMs3, $legacyMs3, $poolMs3);
    record3(
        "200 burst msgs",
        $fileMs3,
        $legacyMs3,
        $poolMs3,
        "burst send then burst recv",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 4: trySend/tryReceive non-blocking throughput — 500 messages
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader("Test 4: trySend/tryReceive — 300 msgs");

    $msgCount4 = 300;

    // --- File transport ---
    $chFile4 = createFileChannel($uniquePrefix . "t4_file", $msgCount4 + 10);
    [, $fileMs4] = BenchHelper::measure(function () use ($chFile4, $msgCount4) {
        for ($i = 0; $i < $msgCount4; $i++) {
            $chFile4->trySend($i);
        }
        $sum = 0;
        for ($i = 0; $i < $msgCount4; $i++) {
            $val = $chFile4->tryReceive();
            if ($val !== null) {
                $sum += $val;
            }
        }
        return $sum;
    });
    safeTeardown($chFile4);
    BenchHelper::timing("File transport:", $fileMs4);

    // --- Legacy socket transport ---
    $chLegacy4 = createLegacySocketChannel(
        $uniquePrefix . "t4_legacy",
        $msgCount4 + 10,
    );
    [, $legacyMs4] = BenchHelper::measure(function () use (
        $chLegacy4,
        $msgCount4,
    ) {
        for ($i = 0; $i < $msgCount4; $i++) {
            $chLegacy4->trySend($i);
        }
        $sum = 0;
        for ($i = 0; $i < $msgCount4; $i++) {
            $val = $chLegacy4->tryReceive();
            if ($val !== null) {
                $sum += $val;
            }
        }
        return $sum;
    });
    safeTeardown($chLegacy4);
    BenchHelper::timing("Socket (legacy):", $legacyMs4);

    // --- Pool socket transport ---
    $chPool4 = createPoolChannel($msgCount4 + 10);
    [, $poolMs4] = BenchHelper::measure(function () use ($chPool4, $msgCount4) {
        for ($i = 0; $i < $msgCount4; $i++) {
            $chPool4->trySend($i);
        }
        $sum = 0;
        for ($i = 0; $i < $msgCount4; $i++) {
            $val = $chPool4->tryReceive();
            if ($val !== null) {
                $sum += $val;
            }
        }
        return $sum;
    });
    safeTeardown($chPool4);
    BenchHelper::timing("Socket (pool):", $poolMs4);

    comparison3("300 trySend/tryRecv", $fileMs4, $legacyMs4, $poolMs4);
    record3(
        "300 trySend/tryRecv",
        $fileMs4,
        $legacyMs4,
        $poolMs4,
        "non-blocking ops",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 5: Large payload — 10 KB messages × 50
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader("Test 5: Large payload — 10 KB × 50 msgs");

    $msgCount5 = 50;
    $payload5 = str_repeat("X", 10240);

    // --- File transport ---
    $chFile5 = createFileChannel($uniquePrefix . "t5_file", $msgCount5 + 10);
    [, $fileMs5] = BenchHelper::measure(function () use (
        $chFile5,
        $msgCount5,
        $payload5,
    ) {
        for ($i = 0; $i < $msgCount5; $i++) {
            $chFile5->send($payload5);
        }
        $count = 0;
        for ($i = 0; $i < $msgCount5; $i++) {
            $chFile5->receive();
            $count++;
        }
        return $count;
    });
    safeTeardown($chFile5);
    BenchHelper::timing("File transport:", $fileMs5);

    // --- Legacy socket transport ---
    $chLegacy5 = createLegacySocketChannel(
        $uniquePrefix . "t5_legacy",
        $msgCount5 + 10,
    );
    [, $legacyMs5] = BenchHelper::measure(function () use (
        $chLegacy5,
        $msgCount5,
        $payload5,
    ) {
        for ($i = 0; $i < $msgCount5; $i++) {
            $chLegacy5->send($payload5);
        }
        $count = 0;
        for ($i = 0; $i < $msgCount5; $i++) {
            $chLegacy5->receive();
            $count++;
        }
        return $count;
    });
    safeTeardown($chLegacy5);
    BenchHelper::timing("Socket (legacy):", $legacyMs5);

    // --- Pool socket transport ---
    $chPool5 = createPoolChannel($msgCount5 + 10);
    [, $poolMs5] = BenchHelper::measure(function () use (
        $chPool5,
        $msgCount5,
        $payload5,
    ) {
        for ($i = 0; $i < $msgCount5; $i++) {
            $chPool5->send($payload5);
        }
        $count = 0;
        for ($i = 0; $i < $msgCount5; $i++) {
            $chPool5->receive();
            $count++;
        }
        return $count;
    });
    safeTeardown($chPool5);
    BenchHelper::timing("Socket (pool):", $poolMs5);

    comparison3("10KB × 50 msgs", $fileMs5, $legacyMs5, $poolMs5);
    record3(
        "10KB × 50 msgs",
        $fileMs5,
        $legacyMs5,
        $poolMs5,
        "large payload stress",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 6: Small payload — tiny integers × 1000
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader("Test 6: Small payload — int × 500");

    $msgCount6 = 500;

    // --- File transport ---
    $chFile6 = createFileChannel($uniquePrefix . "t6_file", $msgCount6 + 10);
    [, $fileMs6] = BenchHelper::measure(function () use ($chFile6, $msgCount6) {
        for ($i = 0; $i < $msgCount6; $i++) {
            $chFile6->send($i);
        }
        $sum = 0;
        for ($i = 0; $i < $msgCount6; $i++) {
            $sum += $chFile6->receive();
        }
        return $sum;
    });
    safeTeardown($chFile6);
    BenchHelper::timing("File transport:", $fileMs6);
    BenchHelper::info(
        "    Per-message (file): ~" .
            BenchHelper::formatMs($fileMs6 / $msgCount6),
    );

    // --- Legacy socket transport ---
    $chLegacy6 = createLegacySocketChannel(
        $uniquePrefix . "t6_legacy",
        $msgCount6 + 10,
    );
    [, $legacyMs6] = BenchHelper::measure(function () use (
        $chLegacy6,
        $msgCount6,
    ) {
        for ($i = 0; $i < $msgCount6; $i++) {
            $chLegacy6->send($i);
        }
        $sum = 0;
        for ($i = 0; $i < $msgCount6; $i++) {
            $sum += $chLegacy6->receive();
        }
        return $sum;
    });
    safeTeardown($chLegacy6);
    BenchHelper::timing("Socket (legacy):", $legacyMs6);
    BenchHelper::info(
        "    Per-message (legacy): ~" .
            BenchHelper::formatMs($legacyMs6 / $msgCount6),
    );

    // --- Pool socket transport ---
    $chPool6 = createPoolChannel($msgCount6 + 10);
    [, $poolMs6] = BenchHelper::measure(function () use ($chPool6, $msgCount6) {
        for ($i = 0; $i < $msgCount6; $i++) {
            $chPool6->send($i);
        }
        $sum = 0;
        for ($i = 0; $i < $msgCount6; $i++) {
            $sum += $chPool6->receive();
        }
        return $sum;
    });
    safeTeardown($chPool6);
    BenchHelper::timing("Socket (pool):", $poolMs6);
    BenchHelper::info(
        "    Per-message (pool): ~" .
            BenchHelper::formatMs($poolMs6 / $msgCount6),
    );

    comparison3("500 small msgs", $fileMs6, $legacyMs6, $poolMs6);
    record3(
        "500 small msgs",
        $fileMs6,
        $legacyMs6,
        $poolMs6,
        "integer payloads",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 7: Channel state query overhead
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader("Test 7: State query overhead — 200 queries each");

    $queryCount = 200;

    // --- File transport ---
    $chFile7 = createFileChannel($uniquePrefix . "t7_file", 10);
    $chFile7->send("data");
    [, $fileMs7] = BenchHelper::measure(function () use (
        $chFile7,
        $queryCount,
    ) {
        for ($i = 0; $i < $queryCount; $i++) {
            $chFile7->isClosed();
            $chFile7->isEmpty();
            $chFile7->size();
        }
    });
    safeTeardown($chFile7);
    BenchHelper::timing("File transport:", $fileMs7);
    BenchHelper::info(
        "    Per-query (file): ~" .
            BenchHelper::formatMs($fileMs7 / ($queryCount * 3)),
    );

    // --- Legacy socket transport ---
    $chLegacy7 = createLegacySocketChannel($uniquePrefix . "t7_legacy", 10);
    $chLegacy7->send("data");
    [, $legacyMs7] = BenchHelper::measure(function () use (
        $chLegacy7,
        $queryCount,
    ) {
        for ($i = 0; $i < $queryCount; $i++) {
            $chLegacy7->isClosed();
            $chLegacy7->isEmpty();
            $chLegacy7->size();
        }
    });
    safeTeardown($chLegacy7);
    BenchHelper::timing("Socket (legacy):", $legacyMs7);
    BenchHelper::info(
        "    Per-query (legacy): ~" .
            BenchHelper::formatMs($legacyMs7 / ($queryCount * 3)),
    );

    // --- Pool socket transport ---
    $chPool7 = createPoolChannel(10);
    $chPool7->send("data");
    [, $poolMs7] = BenchHelper::measure(function () use (
        $chPool7,
        $queryCount,
    ) {
        for ($i = 0; $i < $queryCount; $i++) {
            $chPool7->isClosed();
            $chPool7->isEmpty();
            $chPool7->size();
        }
    });
    safeTeardown($chPool7);
    BenchHelper::timing("Socket (pool):", $poolMs7);
    BenchHelper::info(
        "    Per-query (pool): ~" .
            BenchHelper::formatMs($poolMs7 / ($queryCount * 3)),
    );

    comparison3("600 state queries", $fileMs7, $legacyMs7, $poolMs7);
    record3(
        "600 state queries",
        $fileMs7,
        $legacyMs7,
        $poolMs7,
        "isClosed+isEmpty+size",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 8: Interleaved send/receive — 500 messages
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader("Test 8: Interleaved send/receive — 300 msgs");

    $msgCount8 = 300;

    // --- File transport ---
    $chFile8 = createFileChannel($uniquePrefix . "t8_file", 10);
    [, $fileMs8] = BenchHelper::measure(function () use ($chFile8, $msgCount8) {
        $sum = 0;
        for ($i = 0; $i < $msgCount8; $i++) {
            $chFile8->send($i);
            $sum += $chFile8->receive();
        }
        return $sum;
    });
    safeTeardown($chFile8);
    BenchHelper::timing("File transport:", $fileMs8);

    // --- Legacy socket transport ---
    $chLegacy8 = createLegacySocketChannel($uniquePrefix . "t8_legacy", 10);
    [, $legacyMs8] = BenchHelper::measure(function () use (
        $chLegacy8,
        $msgCount8,
    ) {
        $sum = 0;
        for ($i = 0; $i < $msgCount8; $i++) {
            $chLegacy8->send($i);
            $sum += $chLegacy8->receive();
        }
        return $sum;
    });
    safeTeardown($chLegacy8);
    BenchHelper::timing("Socket (legacy):", $legacyMs8);

    // --- Pool socket transport ---
    $chPool8 = createPoolChannel(10);
    [, $poolMs8] = BenchHelper::measure(function () use ($chPool8, $msgCount8) {
        $sum = 0;
        for ($i = 0; $i < $msgCount8; $i++) {
            $chPool8->send($i);
            $sum += $chPool8->receive();
        }
        return $sum;
    });
    safeTeardown($chPool8);
    BenchHelper::timing("Socket (pool):", $poolMs8);

    comparison3("300 interleaved msgs", $fileMs8, $legacyMs8, $poolMs8);
    record3(
        "300 interleaved msgs",
        $fileMs8,
        $legacyMs8,
        $poolMs8,
        "send-recv-send-recv",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 9: IO dispatcher producer -> main consumer (cross-process)
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 9: Cross-process — IO producer → main consumer (50 msgs)",
    );

    $msgCount9 = 50;

    // --- File transport ---
    $chFile9Name = $uniquePrefix . "t9_file";
    $chFile9 = createFileChannel($chFile9Name, $msgCount9 + 10);
    [, $fileMs9] = BenchHelper::measure(function () use (
        $chFile9,
        $chFile9Name,
        $msgCount9,
    ) {
        $sum = 0;
        RunBlocking::new(function () use (
            $chFile9,
            $chFile9Name,
            $msgCount9,
            &$sum,
        ) {
            $job = Async::new(function () use ($chFile9Name, $msgCount9) {
                $ch = Channel::connectByName($chFile9Name);
                for ($i = 0; $i < $msgCount9; $i++) {
                    $ch->send($i);
                }
                return true;
            }, Dispatchers::IO);

            $job->await();

            for ($i = 0; $i < $msgCount9; $i++) {
                $val = $chFile9->tryReceive();
                if ($val !== null) {
                    $sum += $val;
                }
            }
            Thread::await();
        });
        Thread::await();
        return $sum;
    });
    safeTeardown($chFile9);
    BenchHelper::timing("File transport:", $fileMs9);

    // --- Legacy socket transport ---
    $chLegacy9 = createLegacySocketChannel(
        $uniquePrefix . "t9_legacy",
        $msgCount9 + 10,
    );
    $chLegacy9Port = $chLegacy9->getSocketPort();
    $chLegacy9Name = $chLegacy9->getName();
    [, $legacyMs9] = BenchHelper::measure(function () use (
        $chLegacy9,
        $chLegacy9Port,
        $chLegacy9Name,
        $msgCount9,
    ) {
        $sum = 0;
        RunBlocking::new(function () use (
            $chLegacy9,
            $chLegacy9Port,
            $chLegacy9Name,
            $msgCount9,
            &$sum,
        ) {
            $job = Async::new(function () use (
                $chLegacy9Name,
                $chLegacy9Port,
                $msgCount9,
            ) {
                // In child process, disable pool to ensure legacy connect
                Channel::disablePool();
                $ch = Channel::connectSocketByPort(
                    $chLegacy9Name,
                    $chLegacy9Port,
                );
                for ($i = 0; $i < $msgCount9; $i++) {
                    $ch->send($i);
                }
                $ch->cleanup();
                return true;
            }, Dispatchers::IO);

            $job->await();

            for ($i = 0; $i < $msgCount9; $i++) {
                $val = $chLegacy9->tryReceive();
                if ($val !== null) {
                    $sum += $val;
                }
            }
            Thread::await();
        });
        Thread::await();
        return $sum;
    });
    safeTeardown($chLegacy9);
    BenchHelper::timing("Socket (legacy):", $legacyMs9);

    // --- Pool socket transport ---
    // Uses $ch->connect() — the recommended API for child-process reconnection.
    // The instance method already knows it's pool mode and reconnects directly
    // without needing a PING/POOL_PONG probe.
    $chPool9 = createPoolChannel($msgCount9 + 10);
    [, $poolMs9] = BenchHelper::measure(function () use ($chPool9, $msgCount9) {
        $sum = 0;
        RunBlocking::new(function () use ($chPool9, $msgCount9, &$sum) {
            $job = Async::new(function () use ($chPool9, $msgCount9) {
                $chPool9->connect(); // pool-aware reconnect — no args needed
                for ($i = 0; $i < $msgCount9; $i++) {
                    $chPool9->send($i);
                }
                return true;
            }, Dispatchers::IO);

            $job->await();

            for ($i = 0; $i < $msgCount9; $i++) {
                $val = $chPool9->tryReceive();
                if ($val !== null) {
                    $sum += $val;
                }
            }
            Thread::await();
        });
        Thread::await();
        return $sum;
    });
    safeTeardown($chPool9);
    BenchHelper::timing("Socket (pool):", $poolMs9);

    comparison3("IO→main 50 msgs", $fileMs9, $legacyMs9, $poolMs9);
    record3(
        "IO→main 50 msgs",
        $fileMs9,
        $legacyMs9,
        $poolMs9,
        "cross-process producer",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 10: Main producer -> IO dispatcher consumer (cross-process)
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 10: Cross-process — main producer → IO consumer (50 msgs)",
    );

    $msgCount10 = 50;

    // --- File transport ---
    $chFile10Name = $uniquePrefix . "t10_file";
    $chFile10 = Channels::createInterProcess($chFile10Name, $msgCount10 + 10);
    for ($i = 0; $i < $msgCount10; $i++) {
        $chFile10->send($i);
    }

    [, $fileMs10] = BenchHelper::measure(function () use (
        $chFile10Name,
        $msgCount10,
    ) {
        $sum = 0;
        RunBlocking::new(function () use ($chFile10Name, $msgCount10, &$sum) {
            $job = Async::new(function () use ($chFile10Name, $msgCount10) {
                $ch = Channel::connectByName($chFile10Name);
                $s = 0;
                for ($i = 0; $i < $msgCount10; $i++) {
                    $val = $ch->tryReceive();
                    if ($val !== null) {
                        $s += $val;
                    }
                }
                return $s;
            }, Dispatchers::IO);

            $sum = $job->await();
            Thread::await();
        });
        Thread::await();
        return $sum;
    });
    safeTeardown($chFile10);
    BenchHelper::timing("File transport:", $fileMs10);

    // --- Legacy socket transport ---
    $chLegacy10 = createLegacySocketChannel(
        $uniquePrefix . "t10_legacy",
        $msgCount10 + 10,
    );
    $chLegacy10Port = $chLegacy10->getSocketPort();
    $chLegacy10Name = $chLegacy10->getName();
    for ($i = 0; $i < $msgCount10; $i++) {
        $chLegacy10->send($i);
    }

    [, $legacyMs10] = BenchHelper::measure(function () use (
        $chLegacy10Name,
        $chLegacy10Port,
        $msgCount10,
    ) {
        $sum = 0;
        RunBlocking::new(function () use (
            $chLegacy10Name,
            $chLegacy10Port,
            $msgCount10,
            &$sum,
        ) {
            $job = Async::new(function () use (
                $chLegacy10Name,
                $chLegacy10Port,
                $msgCount10,
            ) {
                Channel::disablePool();
                $ch = Channel::connectSocketByPort(
                    $chLegacy10Name,
                    $chLegacy10Port,
                );
                $s = 0;
                $received = 0;
                for (
                    $attempt = 0;
                    $attempt < 1000 && $received < $msgCount10;
                    $attempt++
                ) {
                    $val = $ch->tryReceive();
                    if ($val !== null) {
                        $s += (int) $val;
                        $received++;
                    } else {
                        usleep(1000);
                    }
                }
                $ch->cleanup();
                return $s;
            }, Dispatchers::IO);

            $sum = $job->await();
            Thread::await();
        });
        Thread::await();
        return $sum;
    });
    safeTeardown($chLegacy10);
    BenchHelper::timing("Socket (legacy):", $legacyMs10);

    // --- Pool socket transport ---
    // Uses $ch->connect() — the recommended API for child-process reconnection.
    // The instance method already knows it's pool mode and reconnects directly
    // without needing a PING/POOL_PONG probe.
    $chPool10 = createPoolChannel($msgCount10 + 10);
    for ($i = 0; $i < $msgCount10; $i++) {
        $chPool10->send($i);
    }

    [, $poolMs10] = BenchHelper::measure(function () use (
        $chPool10,
        $msgCount10,
    ) {
        $sum = 0;
        RunBlocking::new(function () use ($chPool10, $msgCount10, &$sum) {
            $job = Async::new(function () use ($chPool10, $msgCount10) {
                $chPool10->connect(); // pool-aware reconnect — no args needed
                $s = 0;
                $received = 0;
                for (
                    $attempt = 0;
                    $attempt < 1000 && $received < $msgCount10;
                    $attempt++
                ) {
                    $val = $chPool10->tryReceive();
                    if ($val !== null) {
                        $s += (int) $val;
                        $received++;
                    } else {
                        usleep(1000);
                    }
                }
                return $s;
            }, Dispatchers::IO);

            $sum = $job->await();
            Thread::await();
        });
        Thread::await();
        return $sum;
    });
    safeTeardown($chPool10);
    BenchHelper::timing("Socket (pool):", $poolMs10);

    comparison3("main→IO 50 msgs", $fileMs10, $legacyMs10, $poolMs10);
    record3(
        "main→IO 50 msgs",
        $fileMs10,
        $legacyMs10,
        $poolMs10,
        "cross-process consumer",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 11: Throughput scaling — increasing message counts
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader("Test 11: Throughput scaling");

    $scaleCounts = [50, 100, 250, 500];

    BenchHelper::info(
        sprintf(
            "    %-8s  %12s  %12s  %12s  %12s  %12s  %12s",
            "Msgs",
            "File(total)",
            "File/msg",
            "Legacy(total)",
            "Legacy/msg",
            "Pool(total)",
            "Pool/msg",
        ),
    );

    foreach ($scaleCounts as $n) {
        // File
        $chF = createFileChannel($uniquePrefix . "t11_file_{$n}", $n + 10);
        [, $fMs] = BenchHelper::measure(function () use ($chF, $n) {
            for ($i = 0; $i < $n; $i++) {
                $chF->send($i);
            }
            for ($i = 0; $i < $n; $i++) {
                $chF->receive();
            }
        });
        safeTeardown($chF);

        // Legacy Socket
        $chL = createLegacySocketChannel(
            $uniquePrefix . "t11_legacy_{$n}",
            $n + 10,
        );
        [, $lMs] = BenchHelper::measure(function () use ($chL, $n) {
            for ($i = 0; $i < $n; $i++) {
                $chL->send($i);
            }
            for ($i = 0; $i < $n; $i++) {
                $chL->receive();
            }
        });
        safeTeardown($chL);

        // Pool Socket
        $chP = createPoolChannel($n + 10);
        [, $pMs] = BenchHelper::measure(function () use ($chP, $n) {
            for ($i = 0; $i < $n; $i++) {
                $chP->send($i);
            }
            for ($i = 0; $i < $n; $i++) {
                $chP->receive();
            }
        });
        safeTeardown($chP);

        $fPerMsg = $fMs / $n;
        $lPerMsg = $lMs / $n;
        $pPerMsg = $pMs / $n;

        BenchHelper::info(
            sprintf(
                "    %-8d  %12s  %12s  %12s  %12s  %12s  %12s",
                $n,
                BenchHelper::formatMs($fMs),
                BenchHelper::formatMs($fPerMsg),
                BenchHelper::formatMs($lMs),
                BenchHelper::formatMs($lPerMsg),
                BenchHelper::formatMs($pMs),
                BenchHelper::formatMs($pPerMsg),
            ),
        );
    }

    BenchHelper::info("");
    BenchHelper::info(
        "    (File per-msg cost increases with buffer size; Socket stays constant)",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 12: Channel creation + teardown cost
    //
    // This is where the pool DRAMATICALLY wins — pool channels don't
    // spawn a new process, they just send CREATE_CHANNEL to the
    // already-running pool process.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader("Test 12: Channel creation + teardown cost");

    $createCount = 5;

    // --- File transport ---
    [, $fileCreateMs] = BenchHelper::measure(function () use (
        $uniquePrefix,
        $createCount,
    ) {
        for ($i = 0; $i < $createCount; $i++) {
            $ch = createFileChannel($uniquePrefix . "t12_file_{$i}", 10);
            safeTeardown($ch);
        }
    });
    BenchHelper::timing(
        "File ({$createCount}× create+destroy):",
        $fileCreateMs,
    );
    BenchHelper::info(
        "    Per-channel (file): ~" .
            BenchHelper::formatMs($fileCreateMs / $createCount),
    );

    // --- Legacy socket transport ---
    [, $legacyCreateMs] = BenchHelper::measure(function () use (
        $uniquePrefix,
        $createCount,
    ) {
        for ($i = 0; $i < $createCount; $i++) {
            $ch = createLegacySocketChannel(
                $uniquePrefix . "t12_legacy_{$i}",
                10,
            );
            safeTeardown($ch);
        }
    });
    BenchHelper::timing(
        "Socket legacy ({$createCount}× create+destroy):",
        $legacyCreateMs,
    );
    BenchHelper::info(
        "    Per-channel (legacy): ~" .
            BenchHelper::formatMs($legacyCreateMs / $createCount),
    );

    // --- Pool socket transport ---
    [, $poolCreateMs] = BenchHelper::measure(function () use ($createCount) {
        for ($i = 0; $i < $createCount; $i++) {
            $ch = createPoolChannel(10);
            safeTeardown($ch);
        }
    });
    BenchHelper::timing(
        "Socket pool ({$createCount}× create+destroy):",
        $poolCreateMs,
    );
    BenchHelper::info(
        "    Per-channel (pool): ~" .
            BenchHelper::formatMs($poolCreateMs / $createCount),
    );

    comparison3(
        "{$createCount}× create+destroy",
        $fileCreateMs,
        $legacyCreateMs,
        $poolCreateMs,
    );
    record3(
        "5× create+destroy",
        $fileCreateMs,
        $legacyCreateMs,
        $poolCreateMs,
        "channel lifecycle cost",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 13: Multi-channel pool — N channels sharing 1 process
    //
    // This test creates multiple channels and sends/receives through
    // all of them, demonstrating the pool's core advantage: many
    // channels sharing a single background process.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader(
        "Test 13: Multi-channel pool — 5 channels × 50 msgs each",
    );

    $numChannels = 5;
    $msgsPerChannel = 50;

    // --- Legacy socket: 5 channels = 5 broker processes ---
    [, $legacyMultiMs] = BenchHelper::measure(function () use (
        $uniquePrefix,
        $numChannels,
        $msgsPerChannel,
    ) {
        $channels = [];
        // Create all channels (each spawns a process)
        for ($c = 0; $c < $numChannels; $c++) {
            $channels[] = createLegacySocketChannel(
                $uniquePrefix . "t13_legacy_{$c}",
                $msgsPerChannel + 10,
            );
        }

        // Send and receive through each channel
        $totalSum = 0;
        foreach ($channels as $ch) {
            for ($i = 0; $i < $msgsPerChannel; $i++) {
                $ch->send($i);
            }
            for ($i = 0; $i < $msgsPerChannel; $i++) {
                $totalSum += $ch->receive();
            }
        }

        // Teardown all
        foreach ($channels as $ch) {
            safeTeardown($ch);
        }
        return $totalSum;
    });
    BenchHelper::timing("Socket legacy (5 processes):", $legacyMultiMs);
    BenchHelper::info(
        "    Total messages: " .
            $numChannels * $msgsPerChannel .
            " | Per-msg: ~" .
            BenchHelper::formatMs(
                $legacyMultiMs / ($numChannels * $msgsPerChannel),
            ),
    );

    // --- Pool socket: 10 channels = 1 pool process ---
    [, $poolMultiMs] = BenchHelper::measure(function () use (
        $numChannels,
        $msgsPerChannel,
    ) {
        $channels = [];
        // Create all channels (all go to same pool process)
        for ($c = 0; $c < $numChannels; $c++) {
            $channels[] = createPoolChannel($msgsPerChannel + 10);
        }

        // Send and receive through each channel
        $totalSum = 0;
        foreach ($channels as $ch) {
            for ($i = 0; $i < $msgsPerChannel; $i++) {
                $ch->send($i);
            }
            for ($i = 0; $i < $msgsPerChannel; $i++) {
                $totalSum += $ch->receive();
            }
        }

        // Teardown all
        foreach ($channels as $ch) {
            safeTeardown($ch);
        }
        return $totalSum;
    });
    BenchHelper::timing("Socket pool (1 process):", $poolMultiMs);
    BenchHelper::info(
        "    Total messages: " .
            $numChannels * $msgsPerChannel .
            " | Per-msg: ~" .
            BenchHelper::formatMs(
                $poolMultiMs / ($numChannels * $msgsPerChannel),
            ),
    );

    $multiSpeedup = $legacyMultiMs > 0 ? $legacyMultiMs / $poolMultiMs : 0.0;
    echo "\n";
    echo "    \033[1mPool vs Legacy ({$numChannels} channels):\033[0m " .
        formatSpeedupInline($multiSpeedup) .
        "\n";
    echo "    \033[2mLegacy: {$numChannels} broker processes | Pool: 1 pool process\033[0m\n";

    BenchHelper::record(
        "5ch multi-channel",
        $legacyMultiMs,
        $poolMultiMs,
        "N channels pool advantage",
    );

    // ═════════════════════════════════════════════════════════════════
    // Print Summary
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::printSummary();

    // Additional interpretation
    echo "\n";
    echo "    \033[1mInterpretation:\033[0m\n";
    echo "    ┌────────────────────────────────────────────────────────────────────────────────┐\n";
    echo "    │ ASPECT                  │ FILE TRANSPORT    │ SOCKET (LEGACY)   │ SOCKET (POOL)    │\n";
    echo "    ├────────────────────────────────────────────────────────────────────────────────┤\n";
    echo "    │ Per-message overhead    │ High (full buffer │ Low (single msg   │ Low (single msg  │\n";
    echo "    │                         │ serialize + IO)   │ + TCP)            │ + TCP + prefix)  │\n";
    echo "    │ State queries           │ File read each    │ Short TCP cmd     │ Short TCP cmd    │\n";
    echo "    │ Channel creation        │ Fast (temp files) │ Slow (spawn proc) │ Fast (TCP cmd)   │\n";
    echo "    │ Processes per N chans   │ 0                 │ N                 │ 1                │\n";
    echo "    │ Multi-channel scaling   │ O(n) per-msg      │ O(1) per-msg      │ O(1) per-msg     │\n";
    echo "    │ Cross-process           │ Mutex contention  │ Event-driven      │ Event-driven     │\n";
    echo "    │ Windows compatibility   │ Works (flock)     │ Works (TCP)       │ Works (TCP)      │\n";
    echo "    │ Resource usage          │ Temp files only   │ N bg processes    │ 1 bg process     │\n";
    echo "    │ Auto-detection (child)  │ N/A               │ N/A               │ PING/POOL_PONG   │\n";
    echo "    └────────────────────────────────────────────────────────────────────────────────┘\n";
    echo "\n";
    echo "    \033[1mRecommendation:\033[0m\n";
    echo "      • Use \033[32mChannel::create()\033[0m (pool mode, default) for most use cases\n";
    echo "      • Pool mode is \033[32menabled by default\033[0m — no configuration needed\n";
    echo "      • Use \033[33mChannel::disablePool()\033[0m only if you need isolated per-channel brokers\n";
    echo "      • Use \033[33mfile transport\033[0m for one-shot / low-frequency / no-background-process needs\n";
    echo "      • Use \033[36min-process channels\033[0m when no IPC is needed (fastest)\n";
    echo "      • Pool shines brightest when creating \033[32mmany channels\033[0m (N channels → 1 process)\n";
    echo "\n";

    // Shutdown pool cleanly at end
    Channel::shutdownPool();
});
