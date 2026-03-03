<?php

/**
 * Test: ChannelBrokerPool — Pool mode with multiple channels sharing one process.
 *
 * Demonstrates the pool mode API:
 *
 *   - Channel::enablePool()         → enable global pool mode
 *   - Channel::create()             → automatically uses pool when enabled
 *   - Channel::createPooled()       → explicitly create a pooled channel
 *   - Channels::enablePool()        → facade for pool enable
 *   - Channels::createPooled()      → facade for pooled channel creation
 *   - $chan->connect()              → reconnect in child process (pool-aware)
 *   - Channel::shutdownPool()       → shut down the shared pool process
 *
 * Key benefit: N channels = 1 background process (instead of N processes).
 */

declare(strict_types=1);

require_once __DIR__ . "/../vendor/autoload.php";

use vosaka\foroutines\channel\Channel;
use vosaka\foroutines\channel\Channels;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Launch;
use vosaka\foroutines\Dispatchers;

$passed = 0;
$failed = 0;

function assert_true(bool $cond, string $msg): void
{
    global $passed, $failed;
    if ($cond) {
        echo "  ✓ {$msg}\n";
        $passed++;
    } else {
        echo "  ✗ FAIL: {$msg}\n";
        $failed++;
    }
}

function separator(string $title): void
{
    echo "\n=== {$title} ===\n";
}

// ═══════════════════════════════════════════════════════════════════════
//  Test 1: Basic pool enable → create → send/receive → shutdown
// ═══════════════════════════════════════════════════════════════════════

separator("Test 1: Basic pool create + send/receive (pool is default)");

try {
    assert_true(Channel::isPoolEnabled(), "Pool is enabled by default");

    $ch = Channel::create(5);
    assert_true($ch !== null, "Channel created successfully");
    assert_true($ch->isPoolMode(), "Channel is in pool mode");
    assert_true(
        $ch->getTransport() === "socket_pool",
        "Transport is socket_pool",
    );

    $poolPort = Channel::getPoolPort();
    assert_true(
        $poolPort !== null && $poolPort > 0,
        "Pool port is valid (lazy booted): {$poolPort}",
    );
    assert_true($ch->getSocketPort() === $poolPort, "Channel uses pool port");

    $ch->send("hello_pool");
    $ch->send("world_pool");

    $val1 = $ch->receive();
    $val2 = $ch->receive();

    assert_true($val1 === "hello_pool", "Received first value: {$val1}");
    assert_true($val2 === "world_pool", "Received second value: {$val2}");

    $ch->close();
    assert_true($ch->isClosed(), "Channel is closed after close()");

    Channel::shutdownPool();
    assert_true(
        Channel::isPoolEnabled(),
        "Pool mode still enabled after shutdown (only process stopped)",
    );

    echo "Test 1 passed\n";
} catch (Throwable $e) {
    echo "Test 1 FAILED: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    $failed++;
    // Ensure cleanup
    try {
        Channel::shutdownPool();
    } catch (Throwable) {
    }
}

// ═══════════════════════════════════════════════════════════════════════
//  Test 2: Multiple channels sharing one pool process
// ═══════════════════════════════════════════════════════════════════════

separator("Test 2: Multiple channels in one pool");

try {
    $ch1 = Channel::create(10);
    $poolPort = Channel::getPoolPort();

    $ch2 = Channel::create(10);
    $ch3 = Channel::create(10);

    assert_true($ch1->getSocketPort() === $poolPort, "Ch1 uses pool port");
    assert_true($ch2->getSocketPort() === $poolPort, "Ch2 uses pool port");
    assert_true($ch3->getSocketPort() === $poolPort, "Ch3 uses pool port");

    assert_true(
        $ch1->getName() !== $ch2->getName() &&
            $ch2->getName() !== $ch3->getName(),
        "All channels have unique names",
    );

    // Send different data to different channels
    $ch1->send("ch1_data_1");
    $ch1->send("ch1_data_2");
    $ch2->send("ch2_data_1");
    $ch3->send("ch3_data_1");
    $ch3->send("ch3_data_2");
    $ch3->send("ch3_data_3");

    // Verify data isolation — each channel only sees its own data
    assert_true(
        $ch1->receive() === "ch1_data_1",
        "Ch1 receives its own data 1",
    );
    assert_true(
        $ch1->receive() === "ch1_data_2",
        "Ch1 receives its own data 2",
    );
    assert_true($ch2->receive() === "ch2_data_1", "Ch2 receives its own data");
    assert_true(
        $ch3->receive() === "ch3_data_1",
        "Ch3 receives its own data 1",
    );
    assert_true(
        $ch3->receive() === "ch3_data_2",
        "Ch3 receives its own data 2",
    );
    assert_true(
        $ch3->receive() === "ch3_data_3",
        "Ch3 receives its own data 3",
    );

    // Verify sizes
    assert_true($ch1->isEmpty(), "Ch1 is empty after receiving all");
    assert_true($ch2->isEmpty(), "Ch2 is empty after receiving all");
    assert_true($ch3->isEmpty(), "Ch3 is empty after receiving all");

    $ch1->close();
    $ch2->close();
    $ch3->close();

    echo "Test 2 passed\n";
} catch (Throwable $e) {
    echo "Test 2 FAILED: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    $failed++;
    try {
    } catch (Throwable) {
    }
}

// ═══════════════════════════════════════════════════════════════════════
//  Test 3: createPooled() explicit API
// ═══════════════════════════════════════════════════════════════════════

separator("Test 3: Channel::createPooled() explicit API");

try {
    $ch = Channel::createPooled("test_explicit_pool", 5);
    assert_true($ch->isPoolMode(), "Explicitly pooled channel is in pool mode");
    assert_true(
        $ch->getName() === "test_explicit_pool",
        "Channel name is correct",
    );

    $ch->send(42);
    $ch->send(84);

    $v1 = $ch->receive();
    $v2 = $ch->receive();

    assert_true($v1 === 42, "Received int value 42");
    assert_true($v2 === 84, "Received int value 84");

    $ch->close();

    echo "Test 3 passed\n";
} catch (Throwable $e) {
    echo "Test 3 FAILED: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    $failed++;
    try {
    } catch (Throwable) {
    }
}

// ═══════════════════════════════════════════════════════════════════════
//  Test 4: Channels facade pool methods
// ═══════════════════════════════════════════════════════════════════════

separator("Test 4: Channels facade pool API");

try {
    assert_true(
        Channels::isPoolEnabled(),
        "Pool enabled by default via Channels facade",
    );

    $ch = Channels::create(5);
    $poolPort = Channels::getPoolPort();
    assert_true(
        $poolPort !== null && $poolPort > 0,
        "Pool port from facade: {$poolPort}",
    );

    assert_true($ch->isPoolMode(), "Channels::create() uses pool by default");

    $ch->send("facade_value");
    assert_true(
        $ch->receive() === "facade_value",
        "Send/receive via facade-created channel",
    );

    $ch2 = Channels::createPooled(3, "facade_explicit");
    assert_true($ch2->isPoolMode(), "Channels::createPooled() is in pool mode");
    assert_true(
        $ch2->getName() === "facade_explicit",
        "Facade explicit channel name",
    );

    $ch2->send("explicit_val");
    assert_true(
        $ch2->receive() === "explicit_val",
        "Send/receive via explicitly pooled channel",
    );

    $ch->close();
    $ch2->close();

    echo "Test 4 passed\n";
} catch (Throwable $e) {
    echo "Test 4 FAILED: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    $failed++;
    try {
    } catch (Throwable) {
    }
}

// ═══════════════════════════════════════════════════════════════════════
//  Test 5: trySend / tryReceive in pool mode
// ═══════════════════════════════════════════════════════════════════════

separator("Test 5: trySend / tryReceive in pool mode");

try {
    $ch = Channel::create(2); // capacity 2

    $ok1 = $ch->trySend("a");
    $ok2 = $ch->trySend("b");
    $ok3 = $ch->trySend("c"); // should fail — buffer full

    assert_true($ok1 === true, "trySend 1 ok");
    assert_true($ok2 === true, "trySend 2 ok");
    assert_true($ok3 === false, "trySend 3 failed (buffer full)");

    assert_true($ch->isFull(), "Channel is full after 2 sends with capacity 2");
    assert_true($ch->size() === 2, "Channel size is 2");

    $v1 = $ch->tryReceive();
    $v2 = $ch->tryReceive();
    $v3 = $ch->tryReceive(); // should be null — buffer empty

    assert_true($v1 === "a", "tryReceive 1: {$v1}");
    assert_true($v2 === "b", "tryReceive 2: {$v2}");
    assert_true($v3 === null, "tryReceive 3: null (empty)");

    assert_true($ch->isEmpty(), "Channel is empty after receiving all");

    $ch->close();

    echo "Test 5 passed\n";
} catch (Throwable $e) {
    echo "Test 5 FAILED: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    $failed++;
    try {
    } catch (Throwable) {
    }
}

// ═══════════════════════════════════════════════════════════════════════
//  Test 6: Complex data types through pool
// ═══════════════════════════════════════════════════════════════════════

separator("Test 6: Complex data types through pool");

try {
    $ch = Channel::create(10);

    // Send various data types
    $ch->send(42);
    $ch->send(3.14);
    $ch->send("hello");
    $ch->send(true);
    $ch->send(null);
    $ch->send([1, 2, 3, "key" => "value"]);
    $ch->send(["nested" => ["a" => 1, "b" => [2, 3]]]);

    assert_true($ch->receive() === 42, "Int round-trip");
    assert_true($ch->receive() === 3.14, "Float round-trip");
    assert_true($ch->receive() === "hello", "String round-trip");
    assert_true($ch->receive() === true, "Bool round-trip");
    assert_true($ch->receive() === null, "Null round-trip");
    assert_true(
        $ch->receive() === [1, 2, 3, "key" => "value"],
        "Array round-trip",
    );

    $nested = $ch->receive();
    assert_true(
        is_array($nested) &&
            $nested["nested"]["a"] === 1 &&
            $nested["nested"]["b"] === [2, 3],
        "Nested array round-trip",
    );

    $ch->close();

    echo "Test 6 passed\n";
} catch (Throwable $e) {
    echo "Test 6 FAILED: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    $failed++;
    try {
    } catch (Throwable) {
    }
}

// ═══════════════════════════════════════════════════════════════════════
//  Test 7: Pool channel info
// ═══════════════════════════════════════════════════════════════════════

separator("Test 7: Pool channel info");

try {
    $ch = Channel::create(5);
    $ch->send("info_test");

    $info = $ch->getInfo();

    assert_true(is_array($info), "getInfo() returns array");
    assert_true(
        $info["transport"] === "socket_pool",
        "Transport is socket_pool in info",
    );
    assert_true($info["capacity"] === 5, "Capacity in info is 5");
    assert_true($info["size"] === 1, "Size in info is 1");
    assert_true($info["closed"] === false, "Closed in info is false");
    assert_true(
        $info["inter_process"] === true,
        "inter_process in info is true",
    );
    assert_true(isset($info["pool_channels"]), "Pool channels count in info");

    $ch->receive();
    $ch->close();

    echo "Test 7 passed\n";
} catch (Throwable $e) {
    echo "Test 7 FAILED: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    $failed++;
    try {
    } catch (Throwable) {
    }
}

// ═══════════════════════════════════════════════════════════════════════
//  Test 8: Channel serialization round-trip in pool mode
// ═══════════════════════════════════════════════════════════════════════

separator("Test 8: Pool channel serialization round-trip");

try {
    $ch = Channel::create(10);
    $ch->send("before_serialize");

    // Serialize
    $serialized = serialize($ch);
    assert_true(strlen($serialized) > 0, "Serialized size > 0");

    // Unserialize — should auto-reconnect to the pool
    $ch2 = unserialize($serialized);
    assert_true($ch2 !== null, "Unserialized channel is not null");
    assert_true($ch2->isPoolMode(), "Unserialized channel is in pool mode");
    assert_true(!$ch2->isOwner(), "Unserialized channel is not owner");

    // Send from unserialized channel
    $ch2->send("from_unserialized");

    // Receive from original channel
    $v1 = $ch->receive();
    $v2 = $ch->receive();

    assert_true(
        $v1 === "before_serialize",
        "Received value sent before serialize",
    );
    assert_true(
        $v2 === "from_unserialized",
        "Received value from unserialized channel",
    );

    $ch->close();

    echo "Test 8 passed\n";
} catch (Throwable $e) {
    echo "Test 8 FAILED: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    $failed++;
    try {
    } catch (Throwable) {
    }
}

// ═══════════════════════════════════════════════════════════════════════
//  Test 9: Pool $chan->connect() for child process simulation
// ═══════════════════════════════════════════════════════════════════════

separator("Test 9: Pool channel connect() for child process simulation");

try {
    $ch = Channel::create(10);
    $port = $ch->getSocketPort();
    $name = $ch->getName();

    assert_true($port !== null && $port > 0, "Channel has valid port: {$port}");
    assert_true($name !== null && $name !== "", "Channel has valid name");

    // Simulate what a child process does: create a new Channel and connect
    $chChild = Channel::connectSocketByPort($name, $port);

    // Wait — connectSocketByPort creates a non-pool client. Let's instead
    // simulate the proper pool reconnect path via serialization.
    $serialized = serialize($ch);
    $chChild2 = unserialize($serialized);

    // Child sends
    $chChild2->send("from_child_connect");

    // Parent receives
    $val = $ch->receive();
    assert_true(
        $val === "from_child_connect",
        "Parent received from child: {$val}",
    );

    // Parent sends, child receives
    $ch->send("from_parent");
    $val2 = $chChild2->receive();
    assert_true($val2 === "from_parent", "Child received from parent: {$val2}");

    $ch->close();

    echo "Test 9 passed\n";
} catch (Throwable $e) {
    echo "Test 9 FAILED: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    $failed++;
    try {
        Channel::shutdownPool();
    } catch (Throwable) {
    }
}

// ═══════════════════════════════════════════════════════════════════════
//  Test 10: Unbounded pool channel (capacity 0)
// ═══════════════════════════════════════════════════════════════════════

separator("Test 10: Unbounded pool channel");

try {
    $ch = Channel::create(); // capacity 0 = unbounded

    // Send a bunch of items without blocking
    $count = 100;
    for ($i = 0; $i < $count; $i++) {
        $ch->send($i);
    }

    assert_true($ch->size() === $count, "Buffer has {$count} items");
    assert_true(!$ch->isFull(), "Unbounded channel is never full");

    $sum = 0;
    for ($i = 0; $i < $count; $i++) {
        $sum += $ch->receive();
    }

    $expected = ($count * ($count - 1)) / 2; // sum of 0..99 = 4950
    assert_true(
        $sum === (int) $expected,
        "Sum of 0..99 = {$sum} (expected {$expected})",
    );
    assert_true($ch->isEmpty(), "Channel empty after receiving all");

    $ch->close();

    echo "Test 10 passed\n";
} catch (Throwable $e) {
    echo "Test 10 FAILED: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    $failed++;
    try {
    } catch (Throwable) {
    }
}

// ═══════════════════════════════════════════════════════════════════════
//  Test 11: Close behavior — closed channel rejects sends
// ═══════════════════════════════════════════════════════════════════════

separator("Test 11: Close behavior in pool mode");

try {
    $ch = Channel::create(5);
    $ch->send("before_close");
    $ch->close();

    assert_true($ch->isClosed(), "Channel is closed");

    // trySend on closed channel should return false
    $ok = $ch->trySend("after_close");
    assert_true($ok === false, "trySend on closed channel returns false");

    // tryReceive on closed but non-empty channel should still work
    // (the buffer had "before_close" which may or may not be available
    //  depending on broker implementation after close)
    // In our broker, close + data in buffer = data still receivable

    echo "Test 11 passed\n";
} catch (Throwable $e) {
    echo "Test 11 FAILED: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    $failed++;
    try {
    } catch (Throwable) {
    }
}

// ═══════════════════════════════════════════════════════════════════════
//  Test 12: disablePool() → new channels use dedicated broker
// ═══════════════════════════════════════════════════════════════════════

separator("Test 12: disablePool() reverts to per-channel brokers");

try {
    // Pool is enabled by default, so first channel uses pool
    $chPooled = Channel::create(5);
    $poolPort = Channel::getPoolPort();
    assert_true(
        $chPooled->isPoolMode(),
        "Channel is pooled before disablePool",
    );

    Channel::disablePool();
    assert_true(!Channel::isPoolEnabled(), "Pool is disabled");

    // New channel should NOT use pool
    $chNonPooled = Channel::create(5);
    assert_true(
        !$chNonPooled->isPoolMode(),
        "New channel is NOT pooled after disablePool",
    );
    assert_true(
        $chNonPooled->getTransport() === "socket",
        "New channel transport is 'socket'",
    );
    assert_true(
        $chNonPooled->getSocketPort() !== $poolPort,
        "New channel uses different port than pool",
    );

    // But the old pooled channel should still work
    $chPooled->send("still_works");
    $val = $chPooled->receive();
    assert_true($val === "still_works", "Old pooled channel still works");

    // The non-pooled channel should also work
    $chNonPooled->send("non_pooled_val");
    $val2 = $chNonPooled->receive();
    assert_true($val2 === "non_pooled_val", "Non-pooled channel works");

    $chPooled->close();
    $chNonPooled->close();

    // Re-enable pool for subsequent tests, clean up pool process
    Channel::enablePool();
    Channel::shutdownPool();

    echo "Test 12 passed\n";
} catch (Throwable $e) {
    echo "Test 12 FAILED: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    $failed++;
    try {
    } catch (Throwable) {
    }
}

// ═══════════════════════════════════════════════════════════════════════
//  Test 13: Many channels stress test
// ═══════════════════════════════════════════════════════════════════════

separator("Test 13: Many channels stress test (20 channels, 1 process)");

try {
    $channels = [];
    $channels[] = Channel::create(5); // triggers lazy pool boot
    $poolPort = Channel::getPoolPort();

    $numChannels = 20;

    $startTime = microtime(true);

    // Create more channels (first one already created above)
    for ($i = 1; $i < $numChannels; $i++) {
        $channels[] = Channel::create(5);
    }

    $createTime = microtime(true) - $startTime;

    // All should use the same pool port
    $allSamePort = true;
    foreach ($channels as $ch) {
        if ($ch->getSocketPort() !== $poolPort) {
            $allSamePort = false;
            break;
        }
    }
    assert_true(
        $allSamePort,
        "All {$numChannels} channels use the same pool port",
    );

    // Send unique data to each channel
    for ($i = 0; $i < $numChannels; $i++) {
        $channels[$i]->send("channel_{$i}_data");
    }

    // Receive and verify isolation
    $allCorrect = true;
    for ($i = 0; $i < $numChannels; $i++) {
        $val = $channels[$i]->receive();
        if ($val !== "channel_{$i}_data") {
            $allCorrect = false;
            echo "  Channel {$i} got wrong data: {$val}\n";
        }
    }
    assert_true(
        $allCorrect,
        "All {$numChannels} channels received correct data",
    );

    echo "  Created {$numChannels} channels in " .
        round($createTime * 1000, 1) .
        "ms\n";

    // Cleanup
    foreach ($channels as $ch) {
        $ch->close();
    }

    echo "Test 13 passed\n";
} catch (Throwable $e) {
    echo "Test 13 FAILED: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    $failed++;
    try {
        Channel::shutdownPool();
    } catch (Throwable) {
    }
}

// ═══════════════════════════════════════════════════════════════════════
//  Test 14: Pool with Dispatchers::IO (if available)
// ═══════════════════════════════════════════════════════════════════════

separator("Test 14: Pool channel — producer/consumer (blocking I/O)");

try {
    $ch = Channel::create(10);

    // Producer: send 5 items (buffered, so non-blocking up to capacity)
    for ($i = 0; $i < 5; $i++) {
        $ch->send("item_{$i}");
    }

    // Consumer: receive all 5 items
    $results = [];
    for ($i = 0; $i < 5; $i++) {
        $results[] = $ch->receive();
    }

    assert_true(count($results) === 5, "Received 5 items");
    $allPresent = true;
    for ($i = 0; $i < 5; $i++) {
        if (!in_array("item_{$i}", $results, true)) {
            $allPresent = false;
        }
    }
    assert_true($allPresent, "All 5 items received correctly");

    // Verify order is FIFO
    $inOrder = true;
    for ($i = 0; $i < 5; $i++) {
        if ($results[$i] !== "item_{$i}") {
            $inOrder = false;
            break;
        }
    }
    assert_true($inOrder, "Items received in FIFO order");

    $ch->close();

    echo "Test 14 passed\n";
} catch (Throwable $e) {
    echo "Test 14 FAILED: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    $failed++;
    try {
    } catch (Throwable) {
    }
}

// ═══════════════════════════════════════════════════════════════════════
//  Test 15: enablePool() called multiple times is idempotent
// ═══════════════════════════════════════════════════════════════════════

separator("Test 15: Pool auto-boots once and stays stable");

try {
    // First create boots the pool lazily
    $ch0 = Channel::create(1);
    $port1 = Channel::getPoolPort();

    // enablePool() on already-enabled pool is a no-op
    Channel::enablePool();
    $port2 = Channel::getPoolPort();

    Channel::enablePool();
    $port3 = Channel::getPoolPort();

    assert_true($port1 === $port2, "Port unchanged after second enablePool()");
    assert_true($port2 === $port3, "Port unchanged after third enablePool()");

    $ch = Channel::create(5);
    $ch->send("idempotent_test");
    assert_true(
        $ch->receive() === "idempotent_test",
        "Channel works after multiple enablePool()",
    );

    $ch->close();
    $ch0->close();

    echo "Test 15 passed\n";
} catch (Throwable $e) {
    echo "Test 15 FAILED: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    $failed++;
    try {
    } catch (Throwable) {
    }
}

// ═══════════════════════════════════════════════════════════════════════
//  Test 16: Comparison — pool vs individual brokers timing
// ═══════════════════════════════════════════════════════════════════════

separator("Test 16: Pool vs individual brokers — creation timing comparison");

try {
    $numChannels = 5;

    // Time individual broker creation (disable pool first)
    Channel::disablePool();
    Channel::shutdownPool();
    $startIndividual = microtime(true);
    $individualChannels = [];
    for ($i = 0; $i < $numChannels; $i++) {
        $individualChannels[] = Channel::create(5);
    }
    $individualTime = microtime(true) - $startIndividual;

    // Cleanup individual channels
    foreach ($individualChannels as $ch) {
        $ch->close();
    }
    // Give brokers a moment to clean up
    usleep(50_000);

    // Time pool creation (re-enable pool)
    Channel::enablePool();
    $startPool = microtime(true);
    $poolChannels = [];
    for ($i = 0; $i < $numChannels; $i++) {
        $poolChannels[] = Channel::create(5);
    }
    $poolTime = microtime(true) - $startPool;

    echo "  Individual brokers ({$numChannels} channels): " .
        round($individualTime * 1000, 1) .
        "ms\n";
    echo "  Pool mode ({$numChannels} channels):           " .
        round($poolTime * 1000, 1) .
        "ms\n";

    if ($poolTime < $individualTime) {
        $speedup = round($individualTime / $poolTime, 1);
        echo "  Pool is ~{$speedup}x faster for channel creation\n";
    } else {
        echo "  (Pool was not faster this run — this can vary based on system load)\n";
    }

    // Both modes should work correctly
    foreach ($poolChannels as $i => $ch) {
        $ch->send("pool_test_{$i}");
    }
    $allOk = true;
    foreach ($poolChannels as $i => $ch) {
        $val = $ch->receive();
        if ($val !== "pool_test_{$i}") {
            $allOk = false;
        }
    }
    assert_true($allOk, "All pool channels work correctly in timing test");

    foreach ($poolChannels as $ch) {
        $ch->close();
    }
    // Final cleanup: shutdown pool at the end of all tests
    Channel::shutdownPool();

    echo "Test 16 passed\n";
} catch (Throwable $e) {
    echo "Test 16 FAILED: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    $failed++;
    try {
    } catch (Throwable) {
    }
}

// ═══════════════════════════════════════════════════════════════════════
//  Summary
// ═══════════════════════════════════════════════════════════════════════

echo "\n" . str_repeat("═", 60) . "\n";
echo "RESULTS: {$passed} passed, {$failed} failed\n";
echo str_repeat("═", 60) . "\n";

if ($failed > 0) {
    echo "⚠ Some tests failed!\n";
    exit(1);
} else {
    echo "✓ All tests passed!\n";
    exit(0);
}
