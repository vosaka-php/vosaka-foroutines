<?php

/**
 * Test: Socket-based Channel (inter-process communication via TCP broker)
 *
 * Demonstrates the socket transport for inter-process Channel communication:
 *
 *   - Channels::createSocketInterProcess() spawns a ChannelBroker background
 *     process and returns an owner Channel connected via TCP loopback.
 *   - Channels::connectSocket() connects to an existing broker by channel name.
 *   - Channel::connectSocket() / Channel::connectSocketByPort() for direct connection.
 *   - All standard Channel operations: send, receive, trySend, tryReceive,
 *     close, isClosed, isEmpty, isFull, size, getInfo.
 *   - Inter-process communication via Dispatchers::IO child processes.
 *   - Multiple clients connecting to the same broker concurrently.
 *   - Various data types survive serialization round-trip.
 *   - Large payload integrity.
 *   - Backpressure (full buffer) and drain behavior.
 *   - Channel close semantics (remaining data can still be received).
 *   - Graceful broker shutdown.
 *
 * Expected output:
 *   "=== Test 1: Basic socket channel send/receive ==="
 *   "Received: 10"
 *   "Received: 20"
 *   "Received: 30"
 *   "Test 1 passed"
 *   "=== Test 2: Channel state queries ==="
 *   "Is empty after creation: yes"
 *   "Size after 2 sends: 2"
 *   "Is full (cap=3, size=2): no"
 *   "Sent third, is full: yes"
 *   "After receive, size: 2"
 *   "Is closed: no"
 *   "Test 2 passed"
 *   "=== Test 3: trySend and tryReceive ==="
 *   "trySend 100: success"
 *   "trySend 200: success"
 *   "tryReceive: 100"
 *   "tryReceive: 200"
 *   "tryReceive on empty: NULL"
 *   "Test 3 passed"
 *   "=== Test 4: trySend on full channel ==="
 *   "trySend when full: failed"
 *   "Test 4 passed"
 *   "=== Test 5: Close channel and attempt send ==="
 *   "Channel closed"
 *   "Is closed: yes"
 *   "Send after close threw: Channel is closed"
 *   "Test 5 passed"
 *   "=== Test 6: Receive remaining after close ==="
 *   "Remaining: AAA"
 *   "Remaining: BBB"
 *   "Test 6 passed"
 *   "=== Test 7: Channel getInfo ==="
 *   "Info has capacity key: yes"
 *   "Info has closed key: yes"
 *   "Info transport is socket: yes"
 *   "Test 7 passed"
 *   "=== Test 8: FIFO ordering ==="
 *   "FIFO 1: first"
 *   "FIFO 2: second"
 *   "FIFO 3: third"
 *   "Test 8 passed"
 *   "=== Test 9: Various data types ==="
 *   "Type int: 42"
 *   "Type float: 3.14"
 *   "Type string: hello"
 *   "Type bool: true"
 *   "Type array: [1, 2, 3]"
 *   "Type null: NULL"
 *   "Test 9 passed"
 *   "=== Test 10: Large buffered channel — 100 items ==="
 *   "Sent 100 items"
 *   "Received 100 items"
 *   "Sum matches: yes"
 *   "Test 10 passed"
 *   "=== Test 11: tryReceive on closed empty channel ==="
 *   "tryReceive on closed empty: NULL"
 *   "Test 11 passed"
 *   "=== Test 12: trySend on closed channel ==="
 *   "trySend on closed: failed"
 *   "Test 12 passed"
 *   "=== Test 13: Multiple close calls (idempotent) ==="
 *   "Second close handled gracefully"
 *   "Test 13 passed"
 *   "=== Test 14: Channel with single capacity ==="
 *   "Single cap send: success"
 *   "Single cap full: yes"
 *   "Single cap trySend when full: failed"
 *   "Single cap receive: ping"
 *   "Single cap empty after receive: yes"
 *   "Test 14 passed"
 *   "=== Test 15: Second client connects to same broker ==="
 *   "Client2 received: from_client1"
 *   "Client1 received: from_client2"
 *   "Test 15 passed"
 *   "=== Test 16: Large payload integrity ==="
 *   "Payload length: 10240"
 *   "Content matches: yes"
 *   "Test 16 passed"
 *   "=== Test 17: IO dispatcher producer -> main consumer ==="
 *   "IO Received: io_hello_0"
 *   "IO Received: io_hello_1"
 *   "IO Received: io_hello_2"
 *   "Test 17 passed"
 *   "=== Test 18: Main producer -> IO dispatcher consumer ==="
 *   "IO consumer sum: 45"
 *   "Test 18 passed"
 *   "=== Test 19: Unbounded channel (capacity=0) ==="
 *   "Sent 50 items to unbounded channel"
 *   "Received 50 items from unbounded channel"
 *   "Sum matches: yes"
 *   "Test 19 passed"
 *   "All socket channel tests passed!"
 */

require "../vendor/autoload.php";

use vosaka\foroutines\Async;
use vosaka\foroutines\Dispatchers;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Thread;
use vosaka\foroutines\channel\Channel;
use vosaka\foroutines\channel\Channels;

use function vosaka\foroutines\main;

/**
 * Safely tear down a socket channel (close + cleanup).
 */
function teardownSocket(Channel $ch): void
{
    try {
        if (!$ch->isClosed()) {
            $ch->close();
        }
    } catch (Exception $e) {
        // ignore
    }
    $ch->cleanup();
}

main(function () {
    // ═══════════════════════════════════════════════════════════════════
    // Test 1: Basic socket channel send/receive
    // ═══════════════════════════════════════════════════════════════════
    var_dump("=== Test 1: Basic socket channel send/receive ===");
    $ch = Channels::createSocketInterProcess("test_socket_basic_1", 5);
    $ch->send(10);
    $ch->send(20);
    $ch->send(30);

    $v1 = $ch->receive();
    var_dump("Received: $v1");
    $v2 = $ch->receive();
    var_dump("Received: $v2");
    $v3 = $ch->receive();
    var_dump("Received: $v3");

    assert($v1 === 10 && $v2 === 20 && $v3 === 30);
    var_dump("Test 1 passed");
    teardownSocket($ch);

    // ═══════════════════════════════════════════════════════════════════
    // Test 2: Channel state queries (isEmpty, isFull, size, isClosed)
    // ═══════════════════════════════════════════════════════════════════
    var_dump("=== Test 2: Channel state queries ===");
    $ch2 = Channels::createSocketInterProcess("test_socket_state_2", 3);
    var_dump("Is empty after creation: " . ($ch2->isEmpty() ? "yes" : "no"));
    $ch2->send("a");
    $ch2->send("b");
    var_dump("Size after 2 sends: " . $ch2->size());
    var_dump("Is full (cap=3, size=2): " . ($ch2->isFull() ? "yes" : "no"));
    $ch2->send("c");
    var_dump("Sent third, is full: " . ($ch2->isFull() ? "yes" : "no"));
    $ch2->receive();
    var_dump("After receive, size: " . $ch2->size());
    var_dump("Is closed: " . ($ch2->isClosed() ? "yes" : "no"));
    var_dump("Test 2 passed");
    teardownSocket($ch2);

    // ═══════════════════════════════════════════════════════════════════
    // Test 3: trySend and tryReceive (non-blocking)
    // ═══════════════════════════════════════════════════════════════════
    var_dump("=== Test 3: trySend and tryReceive ===");
    $ch3 = Channels::createSocketInterProcess("test_socket_try_3", 5);
    $r1 = $ch3->trySend(100);
    var_dump("trySend 100: " . ($r1 ? "success" : "failed"));
    $r2 = $ch3->trySend(200);
    var_dump("trySend 200: " . ($r2 ? "success" : "failed"));

    $v = $ch3->tryReceive();
    var_dump("tryReceive: $v");
    $v = $ch3->tryReceive();
    var_dump("tryReceive: $v");
    $v = $ch3->tryReceive();
    var_dump("tryReceive on empty: " . ($v === null ? "NULL" : $v));
    var_dump("Test 3 passed");
    teardownSocket($ch3);

    // ═══════════════════════════════════════════════════════════════════
    // Test 4: trySend on full channel should fail
    // ═══════════════════════════════════════════════════════════════════
    var_dump("=== Test 4: trySend on full channel ===");
    $ch4 = Channels::createSocketInterProcess("test_socket_full_4", 2);
    $ch4->trySend("x");
    $ch4->trySend("y");
    $full = $ch4->trySend("z"); // should fail - channel is full
    var_dump("trySend when full: " . ($full ? "success" : "failed"));
    assert($full === false);
    var_dump("Test 4 passed");
    teardownSocket($ch4);

    // ═══════════════════════════════════════════════════════════════════
    // Test 5: Close channel and attempt send
    // ═══════════════════════════════════════════════════════════════════
    var_dump("=== Test 5: Close channel and attempt send ===");
    $ch5 = Channels::createSocketInterProcess("test_socket_close_5", 5);
    $ch5->close();
    var_dump("Channel closed");
    var_dump("Is closed: " . ($ch5->isClosed() ? "yes" : "no"));
    try {
        $ch5->send("should fail");
        var_dump("ERROR: send after close did not throw");
    } catch (Exception $e) {
        var_dump("Send after close threw: " . $e->getMessage());
    }
    var_dump("Test 5 passed");
    teardownSocket($ch5);

    // ═══════════════════════════════════════════════════════════════════
    // Test 6: Receive remaining items after channel is closed
    // ═══════════════════════════════════════════════════════════════════
    var_dump("=== Test 6: Receive remaining after close ===");
    $ch6 = Channels::createSocketInterProcess("test_socket_drain_6", 5);
    $ch6->send("AAA");
    $ch6->send("BBB");
    $ch6->close();
    // Should still be able to receive items that were sent before close
    $remaining1 = $ch6->receive();
    var_dump("Remaining: $remaining1");
    $remaining2 = $ch6->receive();
    var_dump("Remaining: $remaining2");
    assert($remaining1 === "AAA" && $remaining2 === "BBB");
    var_dump("Test 6 passed");
    teardownSocket($ch6);

    // ═══════════════════════════════════════════════════════════════════
    // Test 7: Channel getInfo returns useful debug data with socket transport
    // ═══════════════════════════════════════════════════════════════════
    var_dump("=== Test 7: Channel getInfo ===");
    $ch7 = Channels::createSocketInterProcess("test_socket_info_7", 10);
    $ch7->send("data");
    $info = $ch7->getInfo();
    var_dump(
        "Info has capacity key: " .
            (array_key_exists("capacity", $info) ? "yes" : "no"),
    );
    var_dump(
        "Info has closed key: " .
            (array_key_exists("closed", $info) ? "yes" : "no"),
    );
    $transport = $info["transport"] ?? "unknown";
    var_dump(
        "Info transport is socket: " . ($transport === "socket" ? "yes" : "no"),
    );
    var_dump("Test 7 passed");
    teardownSocket($ch7);

    // ═══════════════════════════════════════════════════════════════════
    // Test 8: FIFO ordering in socket channel
    // ═══════════════════════════════════════════════════════════════════
    var_dump("=== Test 8: FIFO ordering ===");
    $ch8 = Channels::createSocketInterProcess("test_socket_fifo_8", 10);
    $ch8->send("first");
    $ch8->send("second");
    $ch8->send("third");
    var_dump("FIFO 1: " . $ch8->receive());
    var_dump("FIFO 2: " . $ch8->receive());
    var_dump("FIFO 3: " . $ch8->receive());
    var_dump("Test 8 passed");
    teardownSocket($ch8);

    // ═══════════════════════════════════════════════════════════════════
    // Test 9: Various data types survive serialization round-trip
    // ═══════════════════════════════════════════════════════════════════
    var_dump("=== Test 9: Various data types ===");
    $ch9 = Channels::createSocketInterProcess("test_socket_types_9", 10);
    $ch9->send(42);
    $ch9->send(3.14);
    $ch9->send("hello");
    $ch9->send(true);
    $ch9->send([1, 2, 3]);
    $ch9->send(null);

    $val = $ch9->receive();
    var_dump("Type int: $val");
    assert($val === 42);
    $val = $ch9->receive();
    var_dump("Type float: $val");
    assert($val === 3.14);
    $val = $ch9->receive();
    var_dump("Type string: $val");
    assert($val === "hello");
    $val = $ch9->receive();
    var_dump("Type bool: " . ($val ? "true" : "false"));
    assert($val === true);
    $val = $ch9->receive();
    var_dump("Type array: [" . implode(", ", $val) . "]");
    assert($val === [1, 2, 3]);
    $val = $ch9->receive();
    var_dump("Type null: " . ($val === null ? "NULL" : $val));
    assert($val === null);
    var_dump("Test 9 passed");
    teardownSocket($ch9);

    // ═══════════════════════════════════════════════════════════════════
    // Test 10: Large buffered channel — send and receive 100 items
    // ═══════════════════════════════════════════════════════════════════
    var_dump("=== Test 10: Large buffered channel — 100 items ===");
    $ch10 = Channels::createSocketInterProcess("test_socket_large_10", 100);
    $expectedSum = 0;
    for ($i = 1; $i <= 100; $i++) {
        $ch10->send($i);
        $expectedSum += $i;
    }
    var_dump("Sent 100 items");
    $actualSum = 0;
    for ($i = 0; $i < 100; $i++) {
        $actualSum += $ch10->receive();
    }
    var_dump("Received 100 items");
    var_dump("Sum matches: " . ($actualSum === $expectedSum ? "yes" : "no"));
    assert($actualSum === $expectedSum);
    var_dump("Test 10 passed");
    teardownSocket($ch10);

    // ═══════════════════════════════════════════════════════════════════
    // Test 11: tryReceive on closed empty channel returns null
    // ═══════════════════════════════════════════════════════════════════
    var_dump("=== Test 11: tryReceive on closed empty channel ===");
    $ch11 = Channels::createSocketInterProcess("test_socket_closedrecv_11", 5);
    $ch11->close();
    $val = $ch11->tryReceive();
    var_dump("tryReceive on closed empty: " . ($val === null ? "NULL" : $val));
    assert($val === null);
    var_dump("Test 11 passed");
    teardownSocket($ch11);

    // ═══════════════════════════════════════════════════════════════════
    // Test 12: trySend on closed channel returns false
    // ═══════════════════════════════════════════════════════════════════
    var_dump("=== Test 12: trySend on closed channel ===");
    $ch12 = Channels::createSocketInterProcess("test_socket_closedsend_12", 5);
    $ch12->close();
    $result = $ch12->trySend("nope");
    var_dump("trySend on closed: " . ($result ? "success" : "failed"));
    assert($result === false);
    var_dump("Test 12 passed");
    teardownSocket($ch12);

    // ═══════════════════════════════════════════════════════════════════
    // Test 13: Multiple close calls should not throw (idempotent)
    // ═══════════════════════════════════════════════════════════════════
    var_dump("=== Test 13: Multiple close calls (idempotent) ===");
    $ch13 = Channels::createSocketInterProcess("test_socket_multiclose_13", 5);
    $ch13->close();
    try {
        $ch13->close(); // second close
        var_dump("Second close handled gracefully");
    } catch (Exception $e) {
        var_dump("Second close threw: " . $e->getMessage());
    }
    var_dump("Test 13 passed");
    teardownSocket($ch13);

    // ═══════════════════════════════════════════════════════════════════
    // Test 14: Channel with single capacity (capacity=1)
    // ═══════════════════════════════════════════════════════════════════
    var_dump("=== Test 14: Channel with single capacity ===");
    $ch14 = Channels::createSocketInterProcess("test_socket_singlecap_14", 1);
    $sent = $ch14->trySend("ping");
    var_dump("Single cap send: " . ($sent ? "success" : "failed"));
    var_dump("Single cap full: " . ($ch14->isFull() ? "yes" : "no"));
    $overflow = $ch14->trySend("overflow");
    var_dump(
        "Single cap trySend when full: " . ($overflow ? "success" : "failed"),
    );
    $val = $ch14->receive();
    var_dump("Single cap receive: $val");
    var_dump(
        "Single cap empty after receive: " . ($ch14->isEmpty() ? "yes" : "no"),
    );
    assert($val === "ping");
    var_dump("Test 14 passed");
    teardownSocket($ch14);

    // ═══════════════════════════════════════════════════════════════════
    // Test 15: Second client connects to same broker
    // Two separate ChannelSocketClient instances talking to the same broker
    // ═══════════════════════════════════════════════════════════════════
    var_dump("=== Test 15: Second client connects to same broker ===");
    $ch15 = Channels::createSocketInterProcess(
        "test_socket_multiclient_15",
        10,
    );
    // Connect a second client to the same broker by port (no port file needed)
    $port15 = $ch15->getSocketPort();
    $ch15b = Channel::connectSocketByPort(
        "test_socket_multiclient_15",
        $port15,
    );

    $ch15->send("from_client1");
    $val = $ch15b->receive();
    var_dump("Client2 received: $val");
    assert($val === "from_client1");

    $ch15b->send("from_client2");
    $val = $ch15->receive();
    var_dump("Client1 received: $val");
    assert($val === "from_client2");

    var_dump("Test 15 passed");
    // Only cleanup the owner — the connector just disconnects
    $ch15b->cleanup();
    teardownSocket($ch15);

    // ═══════════════════════════════════════════════════════════════════
    // Test 16: Large payload integrity (10 KB)
    // ═══════════════════════════════════════════════════════════════════
    var_dump("=== Test 16: Large payload integrity ===");
    $ch16 = Channels::createSocketInterProcess(
        "test_socket_largepayload_16",
        5,
    );
    $bigPayload = str_repeat("X", 10240);
    $ch16->send($bigPayload);
    $received = $ch16->receive();
    var_dump("Payload length: " . strlen($received));
    var_dump("Content matches: " . ($received === $bigPayload ? "yes" : "no"));
    assert($received === $bigPayload);
    var_dump("Test 16 passed");
    teardownSocket($ch16);

    // ═══════════════════════════════════════════════════════════════════
    // Test 17: IO dispatcher producer -> main consumer
    // A Dispatchers::IO child process produces values via a socket channel,
    // and the main process consumes them.
    // ═══════════════════════════════════════════════════════════════════
    var_dump("=== Test 17: IO dispatcher producer -> main consumer ===");
    $chName17 = "test_socket_io_prod_17_" . getmypid();
    $ch17 = Channels::createSocketInterProcess($chName17, 10);
    $port17 = $ch17->getSocketPort();

    RunBlocking::new(function () use ($chName17, $port17, $ch17) {
        // IO producer — child process connects to broker by port and sends data
        $job = Async::new(function () use ($chName17, $port17) {
            $client = Channel::connectSocketByPort($chName17, $port17);
            for ($i = 0; $i < 3; $i++) {
                $client->send("io_hello_{$i}");
            }
            $client->cleanup();
            return "producer_done";
        }, Dispatchers::IO);

        // Wait for producer to finish
        $result = Thread::await($job);

        // Now consume from the channel in the main process
        for ($i = 0; $i < 3; $i++) {
            $val = $ch17->receive();
            var_dump("IO Received: $val");
            assert($val === "io_hello_{$i}");
        }
    });
    var_dump("Test 17 passed");
    teardownSocket($ch17);

    // ═══════════════════════════════════════════════════════════════════
    // Test 18: Main producer -> IO dispatcher consumer
    // Main process sends data, IO child process receives and returns sum.
    // ═══════════════════════════════════════════════════════════════════
    var_dump("=== Test 18: Main producer -> IO dispatcher consumer ===");
    $chName18 = "test_socket_io_cons_18_" . getmypid();
    $ch18 = Channels::createSocketInterProcess($chName18, 20);
    $port18 = $ch18->getSocketPort();

    // Send data from main process first (do NOT close yet — let child read first)
    for ($i = 0; $i < 10; $i++) {
        $ch18->send($i);
    }

    // Use $async->await() pattern (same as test_channel_io_dispatchers.php)
    RunBlocking::new(function () use ($chName18, $port18, $ch18) {
        $async = Async::new(function () use ($chName18, $port18) {
            try {
                $client = Channel::connectSocketByPort($chName18, $port18);
                $sum = 0;
                $received = 0;
                // Values 0..9 are already buffered in the broker
                for (
                    $attempt = 0;
                    $attempt < 500 && $received < 10;
                    $attempt++
                ) {
                    $val = $client->tryReceive();
                    if ($val !== null) {
                        $sum += (int) $val;
                        $received++;
                    } else {
                        usleep(10000); // 10ms
                    }
                }
                $client->cleanup();
                return $sum;
            } catch (\Throwable $e) {
                return "ERROR: " . $e->getMessage();
            }
        }, Dispatchers::IO);

        $sum = $async->await();
        var_dump("IO consumer sum: $sum");
        // Sum of 0..9 = 45
        assert(
            $sum === 45,
            "Expected sum=45 but got: " . var_export($sum, true),
        );

        $ch18->close();
    });
    var_dump("Test 18 passed");
    teardownSocket($ch18);

    // ═══════════════════════════════════════════════════════════════════
    // Test 19: Unbounded channel (capacity=0)
    // ═══════════════════════════════════════════════════════════════════
    var_dump("=== Test 19: Unbounded channel (capacity=0) ===");
    $ch19 = Channels::createSocketInterProcess("test_socket_unbounded_19", 0);
    $expectedSum = 0;
    for ($i = 1; $i <= 50; $i++) {
        $ch19->send($i);
        $expectedSum += $i;
    }
    var_dump("Sent 50 items to unbounded channel");
    $actualSum = 0;
    for ($i = 0; $i < 50; $i++) {
        $actualSum += $ch19->receive();
    }
    var_dump("Received 50 items from unbounded channel");
    var_dump("Sum matches: " . ($actualSum === $expectedSum ? "yes" : "no"));
    assert($actualSum === $expectedSum);
    var_dump("Test 19 passed");
    teardownSocket($ch19);

    var_dump("All socket channel tests passed!");
});
