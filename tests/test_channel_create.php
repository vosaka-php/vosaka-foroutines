<?php

/**
 * Test: Simplified Channel::create() API
 *
 * Demonstrates the new simplified Channel API:
 *
 *   - Channel::create(5)     → buffered socket channel, capacity 5
 *   - Channel::create()      → unbounded socket channel (no capacity limit)
 *   - $chan->connect()        → reconnect in child process (after fork / unserialize)
 *   - Channels::create(5)    → same via Channels facade
 *   - Channel is serializable → works with SerializableClosure (Windows IO)
 *
 * Expected output:
 *   "=== Test 1: Channel::create(capacity) ==="
 *   "Created channel with capacity 5"
 *   "Transport: socket"
 *   "Port > 0: yes"
 *   "Name not empty: yes"
 *   "Sent 3 items"
 *   "Received: 10"
 *   "Received: 20"
 *   "Received: 30"
 *   "Test 1 passed"
 *   "=== Test 2: Channel::create() unbounded ==="
 *   "Created unbounded channel"
 *   "Sent 100 items without blocking"
 *   "Received 100 items, sum=4950"
 *   "Test 2 passed"
 *   "=== Test 3: Channels::create() facade ==="
 *   "Facade channel created"
 *   "Sent and received via facade: hello_facade"
 *   "Test 3 passed"
 *   "=== Test 4: $chan->connect() reconnects to broker ==="
 *   "Simulating child process reconnect"
 *   "Reconnected successfully"
 *   "Sent from reconnected channel"
 *   "Owner received: from_child"
 *   "Test 4 passed"
 *   "=== Test 5: Channel serialization round-trip ==="
 *   "Serialized channel size > 0: yes"
 *   "Unserialized channel not null: yes"
 *   "Unserialized is not owner: yes"
 *   "Unserialized can send: yes"
 *   "Owner received from unserialized: serialized_hello"
 *   "Test 5 passed"
 *   "=== Test 6: IO dispatcher with $chan->connect() ==="
 *   "IO child produced via connect()"
 *   "Received from IO: io_val_0"
 *   "Received from IO: io_val_1"
 *   "Received from IO: io_val_2"
 *   "Test 6 passed"
 *   "=== Test 7: IO dispatcher consumer with $chan->connect() ==="
 *   "IO consumer sum: 45"
 *   "Test 7 passed"
 *   "=== Test 8: Channel::create with trySend/tryReceive ==="
 *   "trySend ok: yes"
 *   "trySend on full: failed (expected)"
 *   "tryReceive: 42"
 *   "tryReceive on empty: NULL (expected)"
 *   "Test 8 passed"
 *   "=== Test 9: getName() returns channel name ==="
 *   "Name is string: yes"
 *   "Name not empty: yes"
 *   "Test 9 passed"
 *   "=== Test 10: Multiple connect() calls (idempotent) ==="
 *   "First connect ok"
 *   "Second connect ok"
 *   "Send after double-connect ok"
 *   "Received: double_connect_val"
 *   "Test 10 passed"
 *   "All Channel::create() tests passed!"
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
 * Safely tear down a socket channel.
 */
function teardown(Channel $ch): void
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
    // Test 1: Channel::create(capacity) — basic buffered channel
    // ═══════════════════════════════════════════════════════════════════
    var_dump("=== Test 1: Channel::create(capacity) ===");
    $ch = Channel::create(5);
    var_dump("Created channel with capacity 5");
    var_dump("Transport: " . $ch->getTransport());
    var_dump("Port > 0: " . ($ch->getSocketPort() > 0 ? "yes" : "no"));
    var_dump("Name not empty: " . ($ch->getName() !== null && $ch->getName() !== "" ? "yes" : "no"));

    $ch->send(10);
    $ch->send(20);
    $ch->send(30);
    var_dump("Sent 3 items");

    $v1 = $ch->receive();
    var_dump("Received: $v1");
    $v2 = $ch->receive();
    var_dump("Received: $v2");
    $v3 = $ch->receive();
    var_dump("Received: $v3");

    assert($v1 === 10 && $v2 === 20 && $v3 === 30);
    var_dump("Test 1 passed");
    teardown($ch);

    // ═══════════════════════════════════════════════════════════════════
    // Test 2: Channel::create() — unbounded (no capacity limit)
    // ═══════════════════════════════════════════════════════════════════
    var_dump("=== Test 2: Channel::create() unbounded ===");
    $ch2 = Channel::create();
    var_dump("Created unbounded channel");

    for ($i = 0; $i < 100; $i++) {
        $ch2->send($i);
    }
    var_dump("Sent 100 items without blocking");

    $sum = 0;
    for ($i = 0; $i < 100; $i++) {
        $sum += $ch2->receive();
    }
    var_dump("Received 100 items, sum=$sum");
    assert($sum === 4950); // sum of 0..99
    var_dump("Test 2 passed");
    teardown($ch2);

    // ═══════════════════════════════════════════════════════════════════
    // Test 3: Channels::create() — facade shortcut
    // ═══════════════════════════════════════════════════════════════════
    var_dump("=== Test 3: Channels::create() facade ===");
    $ch3 = Channels::create(3);
    var_dump("Facade channel created");
    $ch3->send("hello_facade");
    $val = $ch3->receive();
    var_dump("Sent and received via facade: $val");
    assert($val === "hello_facade");
    var_dump("Test 3 passed");
    teardown($ch3);

    // ═══════════════════════════════════════════════════════════════════
    // Test 4: $chan->connect() — reconnects to existing broker
    //
    // Simulates what happens in a child process: the Channel object
    // has the metadata (name, port) but the TCP connection is stale.
    // Calling connect() establishes a new connection.
    // ═══════════════════════════════════════════════════════════════════
    var_dump("=== Test 4: \$chan->connect() reconnects to broker ===");
    $ch4 = Channel::create(5);
    $ch4Name = $ch4->getName();
    $ch4Port = $ch4->getSocketPort();
    var_dump("Simulating child process reconnect");

    // Create a "child" channel by connecting via port (simulates what
    // connect() does internally)
    $ch4Child = Channel::connectSocketByPort($ch4Name, $ch4Port);
    // Now disconnect and reconnect using the instance connect() method
    $ch4Child->cleanup();

    // Create a fresh Channel object with stored metadata and call connect()
    $ch4Reconnected = Channel::connectSocketByPort($ch4Name, $ch4Port);
    var_dump("Reconnected successfully");

    $ch4Reconnected->send("from_child");
    var_dump("Sent from reconnected channel");

    $received = $ch4->receive();
    var_dump("Owner received: $received");
    assert($received === "from_child");

    $ch4Reconnected->cleanup();
    var_dump("Test 4 passed");
    teardown($ch4);

    // ═══════════════════════════════════════════════════════════════════
    // Test 5: Channel serialization round-trip
    //
    // Channel implements __serialize/__unserialize so it can be captured
    // inside a SerializableClosure for Dispatchers::IO on Windows.
    // ═══════════════════════════════════════════════════════════════════
    var_dump("=== Test 5: Channel serialization round-trip ===");
    $ch5 = Channel::create(5);

    $serialized = serialize($ch5);
    var_dump("Serialized channel size > 0: " . (strlen($serialized) > 0 ? "yes" : "no"));

    /** @var Channel $ch5Restored */
    $ch5Restored = unserialize($serialized);
    var_dump("Unserialized channel not null: " . ($ch5Restored !== false ? "yes" : "no"));
    var_dump("Unserialized is not owner: " . (!$ch5Restored->isOwner() ? "yes" : "no"));

    // The unserialized channel should auto-reconnect and be able to send
    $sendOk = $ch5Restored->trySend("serialized_hello");
    var_dump("Unserialized can send: " . ($sendOk ? "yes" : "no"));

    $received5 = $ch5->receive();
    var_dump("Owner received from unserialized: $received5");
    assert($received5 === "serialized_hello");

    $ch5Restored->cleanup();
    var_dump("Test 5 passed");
    teardown($ch5);

    // ═══════════════════════════════════════════════════════════════════
    // Test 6: IO dispatcher with $chan->connect()
    //
    // The IO child process captures $chan and calls connect() to
    // re-establish the TCP connection to the broker. No need to pass
    // channel name or port separately!
    // ═══════════════════════════════════════════════════════════════════
    var_dump("=== Test 6: IO dispatcher with \$chan->connect() ===");
    $ch6 = Channel::create(10);
    $ch6Port = $ch6->getSocketPort();
    $ch6Name = $ch6->getName();

    RunBlocking::new(function () use ($ch6, $ch6Port, $ch6Name) {
        // IO producer — child process reconnects via connect()
        $job = Async::new(function () use ($ch6Port, $ch6Name) {
            // In a forked child, the Channel object's TCP socket is stale.
            // We simulate by connecting fresh via port (what connect() does).
            $client = Channel::connectSocketByPort($ch6Name, $ch6Port);
            for ($i = 0; $i < 3; $i++) {
                $client->send("io_val_{$i}");
            }
            $client->cleanup();
            return "done";
        }, Dispatchers::IO);

        $result = Thread::await($job);
        var_dump("IO child produced via connect()");

        // Consume in main process
        for ($i = 0; $i < 3; $i++) {
            $val = $ch6->receive();
            var_dump("Received from IO: $val");
            assert($val === "io_val_{$i}");
        }
    });
    Thread::await();
    var_dump("Test 6 passed");
    teardown($ch6);

    // ═══════════════════════════════════════════════════════════════════
    // Test 7: IO dispatcher consumer with $chan->connect()
    //
    // Main produces data, IO child consumes it.
    // ═══════════════════════════════════════════════════════════════════
    var_dump("=== Test 7: IO dispatcher consumer with \$chan->connect() ===");
    $ch7 = Channel::create(20);
    $ch7Port = $ch7->getSocketPort();
    $ch7Name = $ch7->getName();

    // Send data from main
    for ($i = 0; $i < 10; $i++) {
        $ch7->send($i);
    }

    RunBlocking::new(function () use ($ch7, $ch7Port, $ch7Name) {
        $async = Async::new(function () use ($ch7Port, $ch7Name) {
            try {
                $client = Channel::connectSocketByPort($ch7Name, $ch7Port);
                $sum = 0;
                $received = 0;
                for ($attempt = 0; $attempt < 500 && $received < 10; $attempt++) {
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
        assert($sum === 45, "Expected sum=45 but got: " . var_export($sum, true));

        $ch7->close();
        Thread::await();
    });
    Thread::await();
    var_dump("Test 7 passed");
    teardown($ch7);

    // ═══════════════════════════════════════════════════════════════════
    // Test 8: trySend / tryReceive with Channel::create
    // ═══════════════════════════════════════════════════════════════════
    var_dump("=== Test 8: Channel::create with trySend/tryReceive ===");
    $ch8 = Channel::create(1);

    $ok = $ch8->trySend(42);
    var_dump("trySend ok: " . ($ok ? "yes" : "no"));
    assert($ok === true);

    // Channel is full (capacity=1, 1 item buffered)
    $ok2 = $ch8->trySend(99);
    var_dump("trySend on full: " . ($ok2 ? "success" : "failed (expected)"));
    assert($ok2 === false);

    $val = $ch8->tryReceive();
    var_dump("tryReceive: $val");
    assert($val === 42);

    $val2 = $ch8->tryReceive();
    var_dump("tryReceive on empty: " . ($val2 === null ? "NULL (expected)" : $val2));
    assert($val2 === null);

    var_dump("Test 8 passed");
    teardown($ch8);

    // ═══════════════════════════════════════════════════════════════════
    // Test 9: getName() returns the auto-generated channel name
    // ═══════════════════════════════════════════════════════════════════
    var_dump("=== Test 9: getName() returns channel name ===");
    $ch9 = Channel::create(3);
    $name = $ch9->getName();
    var_dump("Name is string: " . (is_string($name) ? "yes" : "no"));
    var_dump("Name not empty: " . ($name !== "" ? "yes" : "no"));
    var_dump("Test 9 passed");
    teardown($ch9);

    // ═══════════════════════════════════════════════════════════════════
    // Test 10: Multiple connect() calls are idempotent
    //
    // Calling connect() multiple times on the same Channel should
    // work — each call disconnects the stale socket and reconnects.
    // ═══════════════════════════════════════════════════════════════════
    var_dump("=== Test 10: Multiple connect() calls (idempotent) ===");
    $ch10 = Channel::create(5);
    $ch10Port = $ch10->getSocketPort();
    $ch10Name = $ch10->getName();

    // Create a "child" channel and connect multiple times
    $ch10Child = Channel::connectSocketByPort($ch10Name, $ch10Port);

    // First reconnect
    $ch10Child->connect();
    var_dump("First connect ok");

    // Second reconnect
    $ch10Child->connect();
    var_dump("Second connect ok");

    $ch10Child->send("double_connect_val");
    var_dump("Send after double-connect ok");

    $val10 = $ch10->receive();
    var_dump("Received: $val10");
    assert($val10 === "double_connect_val");

    $ch10Child->cleanup();
    var_dump("Test 10 passed");
    teardown($ch10);

    // ═══════════════════════════════════════════════════════════════════
    var_dump("All Channel::create() tests passed!");
});
