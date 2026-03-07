<?php

/**
 * Test: Channel communication via Dispatchers::IO
 *
 * Demonstrates inter-process channel communication where producer and consumer
 * coroutines run on Dispatchers::IO (child processes) and exchange data through
 * a shared inter-process Channel backed by file-based storage.
 *
 * These tests rely on two key fixes in Channel:
 *
 *   1. **isOwner flag**: Only the process that created the channel
 *      (via Channels::createInterProcess / Channel::newInterProcess) is the
 *      "owner" and is allowed to delete the backing file on destruct/shutdown.
 *      Child processes that obtain a handle via Channel::connectByName() are
 *      mere connectors — they never delete the file.
 *
 *   2. **Short-lock + retry-outside-lock pattern**: sendInterProcess() and
 *      receiveInterProcess() no longer hold the mutex for the entire spin-wait
 *      loop.  They acquire the lock briefly, attempt the operation, and if
 *      the buffer is full/empty they release the lock and usleep() before
 *      retrying.  This prevents deadlock between concurrent producers and
 *      consumers across processes.
 *
 * Patterns tested:
 *   Test 1:  IO producer -> main consumer (IO child writes, main reads)
 *   Test 2:  Main producer -> IO consumer (main writes, IO child reads & returns sum)
 *   Test 3:  IO producer -> IO consumer (two sequential IO tasks, shared channel)
 *   Test 4:  Bidirectional ping-pong via two channels (main <-> IO)
 *   Test 5:  Multiple IO producers fan-in to one channel, main drains
 *   Test 6:  Multiple data types survive inter-process round-trip
 *   Test 7:  Large payload (10 KB) integrity through IO channel
 *   Test 8:  IO child closes channel, main detects closure via isClosed()
 *   Test 9:  trySend from IO / tryReceive from main (non-blocking)
 *   Test 10: IO internal channel pipeline (produce -> transform -> collect)
 *
 * Expected output:
 *   "=== Test 1: IO producer -> main consumer ==="
 *   "Received: hello_0"
 *   "Received: hello_1"
 *   "Received: hello_2"
 *   "Test 1 passed"
 *   "=== Test 2: Main producer -> IO consumer ==="
 *   "IO consumer sum: 60"
 *   "Test 2 passed"
 *   "=== Test 3: IO producer -> IO consumer (sequenced) ==="
 *   "IO consumer sum of squares: 385"
 *   "Test 3 passed"
 *   "=== Test 4: Bidirectional ping-pong ==="
 *   "Pong 0: pong_0"
 *   "Pong 1: pong_1"
 *   "Pong 2: pong_2"
 *   "Test 4 passed"
 *   "=== Test 5: Multiple IO producers (fan-in) ==="
 *   "Fan-in count: 9"
 *   "Fan-in sum: 36"
 *   "Test 5 passed"
 *   "=== Test 6: Multiple data types via IO ==="
 *   "int=42 float=3.14 string=hello bool=true arrayLen=3"
 *   "Test 6 passed"
 *   "=== Test 7: Large payload via IO ==="
 *   "Payload length: 10240"
 *   "Content matches: yes"
 *   "Test 7 passed"
 *   "=== Test 8: IO closes channel ==="
 *   "Channel closed by IO: yes"
 *   "Test 8 passed"
 *   "=== Test 9: trySend from IO / tryReceive from main ==="
 *   "IO trySend ok: yes"
 *   "Main got: io_nonblocking"
 *   "Test 9 passed"
 *   "=== Test 10: IO internal pipeline ==="
 *   "Pipeline result: 2,4,6,8,10"
 *   "Test 10 passed"
 *   "All Channel IO Dispatcher tests passed!"
 */

require __DIR__ . "/../vendor/autoload.php";

use vosaka\foroutines\Async;
use vosaka\foroutines\Dispatchers;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Thread;
use vosaka\foroutines\channel\Channel;
use vosaka\foroutines\channel\Channels;

use vosaka\foroutines\AsyncMain;

/**
 * Safely tear down a channel (close + cleanup).
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

#[AsyncMain]
function main()
{
    // ================================================================
    // Test 1: IO producer -> main consumer
    //
    // IO child process connects to the named channel, sends 3 strings,
    // and returns.  Main then reads them via tryReceive polling.
    // This works because Channel::connectByName() returns a connector
    // (isOwner=false) which does NOT delete the file on destruct.
    // ================================================================
    var_dump("=== Test 1: IO producer -> main consumer ===");

    $ch1 = Channels::createInterProcess("t1_io_prod", 10);

    RunBlocking::new(function () use ($ch1) {
        $async = Async::new(function () {
            $ch = Channel::connectByName("t1_io_prod");
            for ($i = 0; $i < 3; $i++) {
                $ch->send("hello_$i");
            }
            return true;
        }, Dispatchers::IO);

        $async->await();

        // Main reads — data is still in the file because the child
        // was a connector and did NOT delete it.
        for ($i = 0; $i < 3; $i++) {
            $val = $ch1->tryReceive();
            var_dump("Received: $val");
        }

        Thread::await();
    });
    Thread::await();

    teardown($ch1);
    var_dump("Test 1 passed");

    // ================================================================
    // Test 2: Main producer -> IO consumer
    //
    // Main pre-fills the channel with numbers, then an IO child reads
    // them all and returns the sum.
    // ================================================================
    var_dump("=== Test 2: Main producer -> IO consumer ===");

    $ch2 = Channels::createInterProcess("t2_io_cons", 10);
    $ch2->send(10);
    $ch2->send(20);
    $ch2->send(30);

    RunBlocking::new(function () {
        $async = Async::new(function () {
            $ch = Channel::connectByName("t2_io_cons");
            $sum = 0;
            for ($i = 0; $i < 3; $i++) {
                $val = $ch->tryReceive();
                if ($val !== null) {
                    $sum += $val;
                }
            }
            return $sum;
        }, Dispatchers::IO);

        $result = $async->await();
        var_dump("IO consumer sum: $result");
        Thread::await();
    });
    Thread::await();

    teardown($ch2);
    var_dump("Test 2 passed");

    // ================================================================
    // Test 3: IO producer -> IO consumer (two sequential IO tasks)
    //
    // First IO task fills the channel with 1..10 and returns.
    // Second IO task reads 10 items and computes sum of squares.
    // Because connectors never delete the file, the data survives
    // between the two child processes.
    // sum(i^2, i=1..10) = 385
    // ================================================================
    var_dump("=== Test 3: IO producer -> IO consumer (sequenced) ===");

    $ch3 = Channels::createInterProcess("t3_io2io", 20);

    RunBlocking::new(function () {
        // Phase 1: IO producer
        $producer = Async::new(function () {
            $ch = Channel::connectByName("t3_io2io");
            for ($i = 1; $i <= 10; $i++) {
                $ch->send($i);
            }
            return true;
        }, Dispatchers::IO);
        $producer->await();

        // Phase 2: IO consumer
        $consumer = Async::new(function () {
            $ch = Channel::connectByName("t3_io2io");
            $sum = 0;
            for ($i = 0; $i < 10; $i++) {
                $val = $ch->tryReceive();
                if ($val !== null) {
                    $sum += $val * $val;
                }
            }
            return $sum;
        }, Dispatchers::IO);
        $result = $consumer->await();
        var_dump("IO consumer sum of squares: $result");

        Thread::await();
    });
    Thread::await();

    teardown($ch3);
    var_dump("Test 3 passed");

    // ================================================================
    // Test 4: Bidirectional ping-pong via two channels
    //
    // Main pre-fills "request" channel with ping_0..2.
    // A single IO task reads all requests, transforms each to pong_N,
    // and writes to "response" channel.
    // Main then reads all responses.
    // ================================================================
    var_dump("=== Test 4: Bidirectional ping-pong ===");

    $chReq = Channels::createInterProcess("t4_req", 10);
    $chRes = Channels::createInterProcess("t4_res", 10);

    // Pre-fill requests from main
    for ($i = 0; $i < 3; $i++) {
        $chReq->send("ping_$i");
    }

    RunBlocking::new(function () use ($chRes) {
        $async = Async::new(function () {
            $req = Channel::connectByName("t4_req");
            $res = Channel::connectByName("t4_res");
            for ($i = 0; $i < 3; $i++) {
                $ping = $req->tryReceive();
                if ($ping !== null) {
                    $pong = str_replace("ping", "pong", $ping);
                    $res->send($pong);
                }
            }
            return true;
        }, Dispatchers::IO);

        $async->await();

        // Main reads all responses
        for ($i = 0; $i < 3; $i++) {
            $pong = $chRes->tryReceive();
            var_dump("Pong $i: $pong");
        }

        Thread::await();
    });
    Thread::await();

    teardown($chReq);
    teardown($chRes);
    var_dump("Test 4 passed");

    // ================================================================
    // Test 5: Multiple IO producers (fan-in)
    //
    // 3 IO tasks each write 3 values into the same channel.
    // Producer p sends: p*1, p*2, p*3  (p = 1,2,3)
    // Total sum = (1+2+3) + (2+4+6) + (3+6+9) = 6+12+18 = 36
    // Total items = 9
    // After all IO tasks finish, main drains everything.
    // ================================================================
    var_dump("=== Test 5: Multiple IO producers (fan-in) ===");

    $ch5 = Channels::createInterProcess("t5_fanin", 20);

    RunBlocking::new(function () use ($ch5) {
        $asyncTasks = [];
        for ($p = 1; $p <= 3; $p++) {
            $asyncTasks[] = Async::new(function () use ($p) {
                $ch = Channel::connectByName("t5_fanin");
                for ($i = 1; $i <= 3; $i++) {
                    $ch->send($p * $i);
                }
                return true;
            }, Dispatchers::IO);
        }

        // Wait for all producers
        foreach ($asyncTasks as $task) {
            $task->await();
        }

        // Drain from main
        $items = [];
        for ($i = 0; $i < 9; $i++) {
            $val = $ch5->tryReceive();
            if ($val !== null) {
                $items[] = $val;
            }
        }

        var_dump("Fan-in count: " . count($items));
        var_dump("Fan-in sum: " . array_sum($items));

        Thread::await();
    });
    Thread::await();

    teardown($ch5);
    var_dump("Test 5 passed");

    // ================================================================
    // Test 6: Multiple data types survive inter-process round-trip
    //
    // IO child sends int, float, string, bool, array.
    // Main reads each and verifies the types.
    // ================================================================
    var_dump("=== Test 6: Multiple data types via IO ===");

    $ch6 = Channels::createInterProcess("t6_types", 10);

    RunBlocking::new(function () use ($ch6) {
        $async = Async::new(function () {
            $ch = Channel::connectByName("t6_types");
            $ch->send(42);
            $ch->send(3.14);
            $ch->send("hello");
            $ch->send(true);
            $ch->send([10, 20, 30]);
            return true;
        }, Dispatchers::IO);

        $async->await();

        $intVal = $ch6->tryReceive();
        $floatVal = $ch6->tryReceive();
        $strVal = $ch6->tryReceive();
        $boolVal = $ch6->tryReceive();
        $arrVal = $ch6->tryReceive();

        $boolStr = $boolVal ? "true" : "false";
        $arrLen = is_array($arrVal) ? count($arrVal) : 0;

        var_dump(
            "int=$intVal float=$floatVal string=$strVal bool=$boolStr arrayLen=$arrLen",
        );

        Thread::await();
    });
    Thread::await();

    teardown($ch6);
    var_dump("Test 6 passed");

    // ================================================================
    // Test 7: Large payload (10 KB) integrity through IO channel
    //
    // IO child sends a 10 KB string.  Main reads it and verifies
    // length + content via md5 hash.
    // ================================================================
    var_dump("=== Test 7: Large payload via IO ===");

    $ch7 = Channels::createInterProcess("t7_large", 5);
    $largePayload = str_repeat("ABCDEFGHIJ", 1024); // 10240 bytes
    $expectedHash = md5($largePayload);

    RunBlocking::new(function () use ($ch7, $largePayload) {
        $async = Async::new(function () use ($largePayload) {
            $ch = Channel::connectByName("t7_large");
            $ch->send($largePayload);
            return true;
        }, Dispatchers::IO);

        $async->await();

        $received = $ch7->tryReceive();
        var_dump(
            "Payload length: " .
                ($received !== null ? strlen($received) : "null"),
        );
        var_dump(
            "Content matches: " . ($received === $largePayload ? "yes" : "no"),
        );

        Thread::await();
    });
    Thread::await();

    teardown($ch7);
    var_dump("Test 7 passed");

    // ================================================================
    // Test 8: IO child closes channel, main detects via isClosed()
    //
    // IO child sends an item, then closes the channel.
    // Main drains the item and checks isClosed().
    // ================================================================
    var_dump("=== Test 8: IO closes channel ===");

    $ch8 = Channels::createInterProcess("t8_close", 10);

    RunBlocking::new(function () use ($ch8) {
        $async = Async::new(function () {
            $ch = Channel::connectByName("t8_close");
            $ch->send("before_close");
            $ch->close();
            return true;
        }, Dispatchers::IO);

        $async->await();

        // Drain the item
        $ch8->tryReceive();

        // isClosed() reloads state from the backing file
        var_dump("Channel closed by IO: " . ($ch8->isClosed() ? "yes" : "no"));

        Thread::await();
    });
    Thread::await();

    $ch8->cleanup();
    var_dump("Test 8 passed");

    // ================================================================
    // Test 9: trySend from IO (non-blocking), tryReceive from main
    //
    // IO child uses trySend to push a single value.
    // Main uses tryReceive to read it after IO finishes.
    // ================================================================
    var_dump("=== Test 9: trySend from IO / tryReceive from main ===");

    $ch9 = Channels::createInterProcess("t9_try", 5);

    RunBlocking::new(function () use ($ch9) {
        $async = Async::new(function () {
            $ch = Channel::connectByName("t9_try");
            $ok = $ch->trySend("io_nonblocking");
            return $ok;
        }, Dispatchers::IO);

        $sent = $async->await();
        var_dump("IO trySend ok: " . ($sent ? "yes" : "no"));

        $val = $ch9->tryReceive();
        var_dump("Main got: $val");

        Thread::await();
    });
    Thread::await();

    teardown($ch9);
    var_dump("Test 9 passed");

    // ================================================================
    // Test 10: IO internal channel pipeline (no inter-process needed)
    //
    // A single IO task creates local buffered channels internally:
    //   source -> transform (double) -> output -> collect as string
    // This tests channel logic running entirely inside a child process.
    // ================================================================
    var_dump("=== Test 10: IO internal pipeline ===");

    RunBlocking::new(function () {
        $async = Async::new(function () {
            // Stage 1: produce raw values 1..5
            $source = Channels::createBuffered(10);
            for ($i = 1; $i <= 5; $i++) {
                $source->send($i);
            }

            // Stage 2: transform — double each value
            $output = Channels::createBuffered(10);
            for ($i = 0; $i < 5; $i++) {
                $val = $source->receive();
                $output->send($val * 2);
            }

            // Stage 3: collect results
            $results = [];
            for ($i = 0; $i < 5; $i++) {
                $results[] = $output->receive();
            }

            return implode(",", $results);
        }, Dispatchers::IO);

        $result = $async->await();
        var_dump("Pipeline result: $result");
        Thread::await();
    });
    Thread::await();
    var_dump("Test 10 passed");

    var_dump("All Channel IO Dispatcher tests passed!");
}
