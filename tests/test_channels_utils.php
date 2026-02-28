<?php

/**
 * Test: Channels utility methods (from, createBuffered, getPlatformInfo, etc.)
 *
 * Demonstrates the Channels utility class which provides factory and
 * transformation methods for Channel instances:
 *
 *   - Channels::new() creates an unbuffered (rendezvous) channel
 *   - Channels::createBuffered(n) creates a buffered channel
 *   - Channels::from(array) creates a channel pre-filled from an array
 *   - Channels::getPlatformInfo() for platform capabilities
 *   - Channel::getInfo() for debug information
 *   - Error handling: invalid capacity, empty channel name, etc.
 *   - Various data types through channels
 *   - FIFO ordering guarantees
 *   - Channel state transitions (open -> closed)
 *   - trySend / tryReceive non-blocking operations
 *   - Channel iteration via getIterator (on closed channels)
 *
 * Note: Channels::range(), merge(), map(), filter(), take(), zip(), timer()
 * all use Launch::new() internally and require a RunBlocking + Thread::wait()
 * context to function. Those are tested indirectly via the dispatcher and
 * structured concurrency tests. This file focuses on synchronous channel ops.
 *
 * Expected output:
 *   "=== Test 1: Channels::new creates unbuffered channel ==="
 *   "Created unbuffered channel"
 *   "Is empty: yes"
 *   "=== Test 2: Channels::createBuffered ==="
 *   "Created buffered channel with capacity 5"
 *   "Send and receive: hello"
 *   "=== Test 3: Channels::createBuffered invalid capacity ==="
 *   "Caught: Buffered channel capacity must be greater than 0"
 *   "=== Test 4: Channels::from array ==="
 *   "From[0]: 10"
 *   "From[1]: 20"
 *   "From[2]: 30"
 *   "From[3]: 40"
 *   "All items received: yes"
 *   "=== Test 5: Channels::from empty array ==="
 *   "Empty from channel is empty: yes"
 *   "=== Test 6: Channels::from with single element ==="
 *   "Single element: 42"
 *   "=== Test 7: Channels::from preserves FIFO order ==="
 *   "FIFO order correct: yes"
 *   "=== Test 8: Channels::from with mixed types ==="
 *   "Mixed[0] type=integer value=42"
 *   "Mixed[1] type=string value=foo"
 *   "Mixed[2] type=double value=3.14"
 *   "Mixed[3] type=boolean value=1"
 *   "=== Test 9: Channel state transitions ==="
 *   "Before close - isClosed: no"
 *   "After close - isClosed: yes"
 *   "Can still receive after close: AAA"
 *   "Can still receive after close: BBB"
 *   "tryReceive on empty closed: NULL"
 *   "=== Test 10: Channel trySend/tryReceive round trip ==="
 *   "trySend 100: success"
 *   "trySend 200: success"
 *   "tryReceive: 100"
 *   "tryReceive: 200"
 *   "tryReceive on empty: NULL"
 *   "=== Test 11: Channel trySend on full ==="
 *   "trySend when full: failed"
 *   "=== Test 12: Channel trySend on closed ==="
 *   "trySend on closed: failed"
 *   "=== Test 13: Channel send after close throws ==="
 *   "Send after close threw: Channel is closed"
 *   "=== Test 14: Channel getInfo ==="
 *   "Info has capacity key: yes"
 *   "Info has closed key: yes"
 *   "Info has size key: yes"
 *   "=== Test 15: Channels::getPlatformInfo ==="
 *   "Platform info has platform: yes"
 *   "=== Test 16: Channel size tracking ==="
 *   "Size after 0 sends: 0"
 *   "Size after 3 sends: 3"
 *   "Size after 1 receive: 2"
 *   "Size after 2 receives: 0"
 *   "=== Test 17: Channel isFull and isEmpty ==="
 *   "Empty at start: yes"
 *   "Full after filling: yes"
 *   "Not empty when full: yes"
 *   "Not full after receive: yes"
 *   "=== Test 18: Large channel throughput ==="
 *   "Sent 200 items"
 *   "Received 200 items"
 *   "Sum matches (20100): yes"
 *   "=== Test 19: Channels::from large array ==="
 *   "Large from count: 50"
 *   "Large from sum: 1275"
 *   "=== Test 20: Multiple close calls idempotent ==="
 *   "First close ok"
 *   "Second close ok"
 *   "=== Test 21: Channel with string keys in array data ==="
 *   "Array data received keys: name,age"
 *   "=== Test 22: Channel with nested arrays ==="
 *   "Nested array: [[1, 2], [3, 4]]"
 *   "=== Test 23: Channels::from then close and drain ==="
 *   "Drained after close count: 3"
 *   "=== Test 24: tryReceive returns exact types ==="
 *   "Int check: yes"
 *   "String check: yes"
 *   "Float check: yes"
 *   "Bool check: yes"
 *   "Array check: yes"
 *   "=== Test 25: Channel capacity 1 (single slot) ==="
 *   "Single slot send: success"
 *   "Single slot full: yes"
 *   "Single slot overflow: failed"
 *   "Single slot receive: ping"
 *   "Single slot empty: yes"
 *   "All Channels utility tests passed"
 */

require "../vendor/autoload.php";

use vosaka\foroutines\channel\Channel;
use vosaka\foroutines\channel\Channels;

use function vosaka\foroutines\main;

main(function () {
    // ================================================================
    // Test 1: Channels::new creates an unbuffered (rendezvous) channel
    // ================================================================
    var_dump("=== Test 1: Channels::new creates unbuffered channel ===");
    $ch = Channels::new();
    var_dump("Created unbuffered channel");
    var_dump("Is empty: " . ($ch->isEmpty() ? "yes" : "no"));

    // ================================================================
    // Test 2: Channels::createBuffered
    // ================================================================
    var_dump("=== Test 2: Channels::createBuffered ===");
    $buf = Channels::createBuffered(5);
    var_dump("Created buffered channel with capacity 5");
    $buf->send("hello");
    $val = $buf->receive();
    var_dump("Send and receive: $val");

    // ================================================================
    // Test 3: Channels::createBuffered with invalid capacity
    // ================================================================
    var_dump("=== Test 3: Channels::createBuffered invalid capacity ===");
    try {
        Channels::createBuffered(0);
        var_dump("ERROR: Should have thrown");
    } catch (Exception $e) {
        var_dump("Caught: " . $e->getMessage());
    }

    // ================================================================
    // Test 4: Channels::from array (synchronous fill)
    // ================================================================
    var_dump("=== Test 4: Channels::from array ===");
    $fromCh = Channels::from([10, 20, 30, 40]);
    $received = [];
    for ($i = 0; $i < 4; $i++) {
        $v = $fromCh->tryReceive();
        if ($v === null) {
            break;
        }
        $received[] = $v;
        var_dump("From[$i]: $v");
    }
    var_dump("All items received: " . (count($received) === 4 ? "yes" : "no"));

    // ================================================================
    // Test 5: Channels::from empty array
    // ================================================================
    var_dump("=== Test 5: Channels::from empty array ===");
    $emptyCh = Channels::from([]);
    var_dump(
        "Empty from channel is empty: " . ($emptyCh->isEmpty() ? "yes" : "no"),
    );

    // ================================================================
    // Test 6: Channels::from with single element
    // ================================================================
    var_dump("=== Test 6: Channels::from with single element ===");
    $singleCh = Channels::from([42]);
    $singleVal = $singleCh->tryReceive();
    var_dump("Single element: $singleVal");

    // ================================================================
    // Test 7: Channels::from preserves FIFO order
    // ================================================================
    var_dump("=== Test 7: Channels::from preserves FIFO order ===");
    $fifoCh = Channels::from(["first", "second", "third", "fourth", "fifth"]);
    $fifoResult = [];
    for ($i = 0; $i < 5; $i++) {
        $v = $fifoCh->tryReceive();
        if ($v === null) {
            break;
        }
        $fifoResult[] = $v;
    }
    $expected = ["first", "second", "third", "fourth", "fifth"];
    var_dump(
        "FIFO order correct: " . ($fifoResult === $expected ? "yes" : "no"),
    );

    // ================================================================
    // Test 8: Channels::from with mixed types
    // ================================================================
    var_dump("=== Test 8: Channels::from with mixed types ===");
    $mixedCh = Channels::from([42, "foo", 3.14, true]);
    for ($i = 0; $i < 4; $i++) {
        $v = $mixedCh->tryReceive();
        if ($v === null) {
            break;
        }
        $type = gettype($v);
        $display = is_bool($v) ? ($v ? "1" : "0") : $v;
        var_dump("Mixed[$i] type=$type value=$display");
    }

    // ================================================================
    // Test 9: Channel state transitions (open -> closed, drain after close)
    // ================================================================
    var_dump("=== Test 9: Channel state transitions ===");
    $stateCh = Channels::createBuffered(5);
    $stateCh->send("AAA");
    $stateCh->send("BBB");
    var_dump(
        "Before close - isClosed: " . ($stateCh->isClosed() ? "yes" : "no"),
    );
    $stateCh->close();
    var_dump(
        "After close - isClosed: " . ($stateCh->isClosed() ? "yes" : "no"),
    );
    // Should still be able to receive items sent before close
    $v1 = $stateCh->receive();
    var_dump("Can still receive after close: $v1");
    $v2 = $stateCh->receive();
    var_dump("Can still receive after close: $v2");
    $v3 = $stateCh->tryReceive();
    var_dump("tryReceive on empty closed: " . ($v3 === null ? "NULL" : $v3));

    // ================================================================
    // Test 10: Channel trySend/tryReceive round trip
    // ================================================================
    var_dump("=== Test 10: Channel trySend/tryReceive round trip ===");
    $tryCh = Channels::createBuffered(5);
    $r1 = $tryCh->trySend(100);
    var_dump("trySend 100: " . ($r1 ? "success" : "failed"));
    $r2 = $tryCh->trySend(200);
    var_dump("trySend 200: " . ($r2 ? "success" : "failed"));
    $v = $tryCh->tryReceive();
    var_dump("tryReceive: $v");
    $v = $tryCh->tryReceive();
    var_dump("tryReceive: $v");
    $v = $tryCh->tryReceive();
    var_dump("tryReceive on empty: " . ($v === null ? "NULL" : $v));

    // ================================================================
    // Test 11: Channel trySend on full channel
    // ================================================================
    var_dump("=== Test 11: Channel trySend on full ===");
    $fullCh = Channels::createBuffered(2);
    $fullCh->trySend("x");
    $fullCh->trySend("y");
    $overflow = $fullCh->trySend("z"); // should fail
    var_dump("trySend when full: " . ($overflow ? "success" : "failed"));

    // ================================================================
    // Test 12: Channel trySend on closed channel
    // ================================================================
    var_dump("=== Test 12: Channel trySend on closed ===");
    $closedCh = Channels::createBuffered(5);
    $closedCh->close();
    $result = $closedCh->trySend("nope");
    var_dump("trySend on closed: " . ($result ? "success" : "failed"));

    // ================================================================
    // Test 13: Channel send after close throws
    // ================================================================
    var_dump("=== Test 13: Channel send after close throws ===");
    $throwCh = Channels::createBuffered(5);
    $throwCh->close();
    try {
        $throwCh->send("should fail");
        var_dump("ERROR: send after close did not throw");
    } catch (Exception $e) {
        var_dump("Send after close threw: " . $e->getMessage());
    }

    // ================================================================
    // Test 14: Channel getInfo returns useful data
    // ================================================================
    var_dump("=== Test 14: Channel getInfo ===");
    $infoCh = Channels::createBuffered(10);
    $infoCh->send("data");
    $info = $infoCh->getInfo();
    var_dump(
        "Info has capacity key: " .
            (array_key_exists("capacity", $info) ? "yes" : "no"),
    );
    var_dump(
        "Info has closed key: " .
            (array_key_exists("closed", $info) ? "yes" : "no"),
    );
    var_dump(
        "Info has size key: " .
            (array_key_exists("size", $info) ? "yes" : "no"),
    );

    // ================================================================
    // Test 15: Channels::getPlatformInfo
    // ================================================================
    var_dump("=== Test 15: Channels::getPlatformInfo ===");
    $platformInfo = Channels::getPlatformInfo();
    var_dump(
        "Platform info has platform: " .
            (array_key_exists("platform", $platformInfo) ? "yes" : "no"),
    );

    // ================================================================
    // Test 16: Channel size tracking
    // ================================================================
    var_dump("=== Test 16: Channel size tracking ===");
    $sizeCh = Channels::createBuffered(10);
    var_dump("Size after 0 sends: " . $sizeCh->size());
    $sizeCh->send("a");
    $sizeCh->send("b");
    $sizeCh->send("c");
    var_dump("Size after 3 sends: " . $sizeCh->size());
    $sizeCh->receive();
    var_dump("Size after 1 receive: " . $sizeCh->size());
    $sizeCh->receive();
    $sizeCh->receive();
    var_dump("Size after 2 receives: " . $sizeCh->size());

    // ================================================================
    // Test 17: Channel isFull and isEmpty
    // ================================================================
    var_dump("=== Test 17: Channel isFull and isEmpty ===");
    $capCh = Channels::createBuffered(3);
    var_dump("Empty at start: " . ($capCh->isEmpty() ? "yes" : "no"));
    $capCh->send(1);
    $capCh->send(2);
    $capCh->send(3);
    var_dump("Full after filling: " . ($capCh->isFull() ? "yes" : "no"));
    var_dump("Not empty when full: " . (!$capCh->isEmpty() ? "yes" : "no"));
    $capCh->receive();
    var_dump("Not full after receive: " . (!$capCh->isFull() ? "yes" : "no"));

    // ================================================================
    // Test 18: Large channel throughput
    // ================================================================
    var_dump("=== Test 18: Large channel throughput ===");
    $largeCh = Channels::createBuffered(200);
    $expectedSum = 0;
    for ($i = 1; $i <= 200; $i++) {
        $largeCh->send($i);
        $expectedSum += $i;
    }
    var_dump("Sent 200 items");
    $actualSum = 0;
    for ($i = 0; $i < 200; $i++) {
        $actualSum += $largeCh->receive();
    }
    var_dump("Received 200 items");
    var_dump(
        "Sum matches ($expectedSum): " .
            ($actualSum === $expectedSum ? "yes" : "no"),
    );

    // ================================================================
    // Test 19: Channels::from large array
    // ================================================================
    var_dump("=== Test 19: Channels::from large array ===");
    $largeArr = range(1, 50);
    $largeFromCh = Channels::from($largeArr);
    $count = 0;
    $sum = 0;
    while (true) {
        $v = $largeFromCh->tryReceive();
        if ($v === null) {
            break;
        }
        $count++;
        $sum += $v;
    }
    var_dump("Large from count: $count");
    var_dump("Large from sum: $sum");

    // ================================================================
    // Test 20: Multiple close calls are idempotent
    // ================================================================
    var_dump("=== Test 20: Multiple close calls idempotent ===");
    $multiClose = Channels::createBuffered(5);
    $multiClose->close();
    var_dump("First close ok");
    try {
        $multiClose->close();
        var_dump("Second close ok");
    } catch (Exception $e) {
        var_dump("Second close threw: " . $e->getMessage());
    }

    // ================================================================
    // Test 21: Channel with array data (string keys)
    // ================================================================
    var_dump("=== Test 21: Channel with string keys in array data ===");
    $arrCh = Channels::createBuffered(5);
    $arrCh->send(["name" => "Alice", "age" => 30]);
    $arrData = $arrCh->receive();
    var_dump("Array data received keys: " . implode(",", array_keys($arrData)));

    // ================================================================
    // Test 22: Channel with nested arrays
    // ================================================================
    var_dump("=== Test 22: Channel with nested arrays ===");
    $nestedCh = Channels::createBuffered(5);
    $nestedCh->send([[1, 2], [3, 4]]);
    $nestedData = $nestedCh->receive();
    $display =
        "[" .
        implode(
            ", ",
            array_map(function ($sub) {
                return "[" . implode(", ", $sub) . "]";
            }, $nestedData),
        ) .
        "]";
    var_dump("Nested array: $display");

    // ================================================================
    // Test 23: Channels::from then close and drain
    // ================================================================
    var_dump("=== Test 23: Channels::from then close and drain ===");
    $drainCh = Channels::from(["x", "y", "z"]);
    $drainCh->close();
    $drained = [];
    while (true) {
        $v = $drainCh->tryReceive();
        if ($v === null) {
            break;
        }
        $drained[] = $v;
    }
    var_dump("Drained after close count: " . count($drained));

    // ================================================================
    // Test 24: tryReceive returns exact types
    // ================================================================
    var_dump("=== Test 24: tryReceive returns exact types ===");
    $typeCh = Channels::createBuffered(10);
    $typeCh->send(123);
    $typeCh->send("hello");
    $typeCh->send(2.718);
    $typeCh->send(false);
    $typeCh->send([1, 2]);

    $intVal = $typeCh->tryReceive();
    var_dump(
        "Int check: " . (is_int($intVal) && $intVal === 123 ? "yes" : "no"),
    );
    $strVal = $typeCh->tryReceive();
    var_dump(
        "String check: " .
            (is_string($strVal) && $strVal === "hello" ? "yes" : "no"),
    );
    $floatVal = $typeCh->tryReceive();
    var_dump(
        "Float check: " .
            (is_float($floatVal) && abs($floatVal - 2.718) < 0.001
                ? "yes"
                : "no"),
    );
    $boolVal = $typeCh->tryReceive();
    var_dump(
        "Bool check: " .
            (is_bool($boolVal) && $boolVal === false ? "yes" : "no"),
    );
    $arrVal = $typeCh->tryReceive();
    var_dump(
        "Array check: " .
            (is_array($arrVal) && $arrVal === [1, 2] ? "yes" : "no"),
    );

    // ================================================================
    // Test 25: Channel capacity 1 (single slot)
    // ================================================================
    var_dump("=== Test 25: Channel capacity 1 (single slot) ===");
    $singleSlot = Channels::createBuffered(1);
    $sent = $singleSlot->trySend("ping");
    var_dump("Single slot send: " . ($sent ? "success" : "failed"));
    var_dump("Single slot full: " . ($singleSlot->isFull() ? "yes" : "no"));
    $overflowSent = $singleSlot->trySend("overflow");
    var_dump("Single slot overflow: " . ($overflowSent ? "success" : "failed"));
    $val = $singleSlot->receive();
    var_dump("Single slot receive: $val");
    var_dump("Single slot empty: " . ($singleSlot->isEmpty() ? "yes" : "no"));

    var_dump("All Channels utility tests passed");
});
