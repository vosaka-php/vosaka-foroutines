<?php

/**
 * Benchmark 06: Socket-based vs File-based Inter-Process Channel
 *
 * Compares the two inter-process channel transport implementations:
 *
 *   1. FILE transport (original):
 *      - Uses file_get_contents / file_put_contents for state persistence
 *      - Mutex (flock) for synchronization
 *      - Spin-wait polling with usleep() for receive
 *      - Full buffer serialize/unserialize on every operation
 *
 *   2. SOCKET transport (new):
 *      - Uses TCP loopback sockets via ChannelBroker background process
 *      - Event-driven via stream_select() — no spin-wait polling
 *      - Only serializes individual messages, not the entire buffer
 *      - Broker manages in-memory ring buffer
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
 *   Test 12: Channel creation + teardown cost
 *
 * Expected results:
 *   - Socket transport should have LOWER latency per message (no file I/O)
 *   - Socket transport should have HIGHER throughput (no full-buffer serialize)
 *   - Socket transport should have MUCH lower overhead for state queries
 *   - File transport may win on very first message (no broker startup cost)
 *   - Socket transport creation is slower (must spawn broker process)
 *   - Cross-process tests should show biggest socket advantage
 *     (file transport has Mutex contention + full file read/write)
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
 * Create a socket-based inter-process channel.
 */
function createSocketChannel(string $name, int $capacity): Channel
{
    return Channel::create($capacity);
}

main(function () {
    BenchHelper::header("Benchmark 06: Socket vs File Inter-Process Channel");
    BenchHelper::info(
        "File-based transport vs Socket-based transport for IPC channels",
    );
    BenchHelper::info("PHP " . PHP_VERSION . " | " . PHP_OS);
    BenchHelper::separator();

    $uniquePrefix = "bench06_" . getmypid() . "_";

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

    // --- Socket transport ---
    [, $socketMs] = BenchHelper::measure(function () use ($uniquePrefix) {
        $ch = createSocketChannel($uniquePrefix . "t1_socket", 10);
        $ch->send("hello");
        $val = $ch->receive();
        safeTeardown($ch);
        return $val;
    });
    BenchHelper::timing("Socket transport:", $socketMs);

    BenchHelper::comparison("1 msg round-trip", $fileMs, $socketMs);
    BenchHelper::record(
        "1 msg round-trip",
        $fileMs,
        $socketMs,
        "includes channel create+destroy",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 2: Sequential throughput — 500 messages, single process
    //
    // Pre-create the channel, then measure only the send/receive loop.
    // This isolates the per-message cost from channel creation overhead.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader("Test 2: Sequential throughput — 500 msgs");

    $msgCount2 = 500;

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

    // --- Socket transport ---
    $chSock2 = createSocketChannel(
        $uniquePrefix . "t2_socket",
        $msgCount2 + 10,
    );
    [, $socketMs2] = BenchHelper::measure(function () use (
        $chSock2,
        $msgCount2,
    ) {
        for ($i = 0; $i < $msgCount2; $i++) {
            $chSock2->send($i);
        }
        $sum = 0;
        for ($i = 0; $i < $msgCount2; $i++) {
            $sum += $chSock2->receive();
        }
        return $sum;
    });
    safeTeardown($chSock2);
    BenchHelper::timing("Socket transport:", $socketMs2);
    BenchHelper::info(
        "    Per-message (socket): ~" .
            BenchHelper::formatMs($socketMs2 / $msgCount2),
    );

    BenchHelper::comparison("500 sequential msgs", $fileMs2, $socketMs2);
    BenchHelper::record(
        "500 sequential msgs",
        $fileMs2,
        $socketMs2,
        "send then receive loop",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 3: Burst send then burst receive — 200 messages
    //
    // Send all messages first, then receive all. This stresses the
    // buffer storage — file transport must serialize/deserialize the
    // entire buffer each time it grows.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader("Test 3: Burst send → burst receive — 200 msgs");

    $msgCount3 = 200;

    // --- File transport ---
    $chFile3 = createFileChannel($uniquePrefix . "t3_file", $msgCount3 + 10);
    [, $fileMs3] = BenchHelper::measure(function () use ($chFile3, $msgCount3) {
        // Burst send
        for ($i = 0; $i < $msgCount3; $i++) {
            $chFile3->send("msg_{$i}");
        }
        // Burst receive
        $count = 0;
        for ($i = 0; $i < $msgCount3; $i++) {
            $chFile3->receive();
            $count++;
        }
        return $count;
    });
    safeTeardown($chFile3);
    BenchHelper::timing("File transport:", $fileMs3);

    // --- Socket transport ---
    $chSock3 = createSocketChannel(
        $uniquePrefix . "t3_socket",
        $msgCount3 + 10,
    );
    [, $socketMs3] = BenchHelper::measure(function () use (
        $chSock3,
        $msgCount3,
    ) {
        for ($i = 0; $i < $msgCount3; $i++) {
            $chSock3->send("msg_{$i}");
        }
        $count = 0;
        for ($i = 0; $i < $msgCount3; $i++) {
            $chSock3->receive();
            $count++;
        }
        return $count;
    });
    safeTeardown($chSock3);
    BenchHelper::timing("Socket transport:", $socketMs3);

    BenchHelper::comparison("200 burst msgs", $fileMs3, $socketMs3);
    BenchHelper::record(
        "200 burst msgs",
        $fileMs3,
        $socketMs3,
        "burst send then burst recv",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 4: trySend/tryReceive non-blocking throughput — 500 messages
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader("Test 4: trySend/tryReceive — 500 msgs");

    $msgCount4 = 500;

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

    // --- Socket transport ---
    $chSock4 = createSocketChannel(
        $uniquePrefix . "t4_socket",
        $msgCount4 + 10,
    );
    [, $socketMs4] = BenchHelper::measure(function () use (
        $chSock4,
        $msgCount4,
    ) {
        for ($i = 0; $i < $msgCount4; $i++) {
            $chSock4->trySend($i);
        }
        $sum = 0;
        for ($i = 0; $i < $msgCount4; $i++) {
            $val = $chSock4->tryReceive();
            if ($val !== null) {
                $sum += $val;
            }
        }
        return $sum;
    });
    safeTeardown($chSock4);
    BenchHelper::timing("Socket transport:", $socketMs4);

    BenchHelper::comparison("500 trySend/tryRecv", $fileMs4, $socketMs4);
    BenchHelper::record(
        "500 trySend/tryRecv",
        $fileMs4,
        $socketMs4,
        "non-blocking ops",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 5: Large payload — 10 KB messages × 50
    //
    // File transport must serialize the entire growing buffer every
    // send, so large payloads amplify the difference.
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

    // --- Socket transport ---
    $chSock5 = createSocketChannel(
        $uniquePrefix . "t5_socket",
        $msgCount5 + 10,
    );
    [, $socketMs5] = BenchHelper::measure(function () use (
        $chSock5,
        $msgCount5,
        $payload5,
    ) {
        for ($i = 0; $i < $msgCount5; $i++) {
            $chSock5->send($payload5);
        }
        $count = 0;
        for ($i = 0; $i < $msgCount5; $i++) {
            $chSock5->receive();
            $count++;
        }
        return $count;
    });
    safeTeardown($chSock5);
    BenchHelper::timing("Socket transport:", $socketMs5);

    BenchHelper::comparison("10KB × 50 msgs", $fileMs5, $socketMs5);
    BenchHelper::record(
        "10KB × 50 msgs",
        $fileMs5,
        $socketMs5,
        "large payload stress",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 6: Small payload — tiny integers × 1000
    //
    // Minimal serialization cost per message. This isolates the
    // transport overhead (file I/O vs socket I/O).
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader("Test 6: Small payload — int × 1000");

    $msgCount6 = 1000;

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

    // --- Socket transport ---
    $chSock6 = createSocketChannel(
        $uniquePrefix . "t6_socket",
        $msgCount6 + 10,
    );
    [, $socketMs6] = BenchHelper::measure(function () use (
        $chSock6,
        $msgCount6,
    ) {
        for ($i = 0; $i < $msgCount6; $i++) {
            $chSock6->send($i);
        }
        $sum = 0;
        for ($i = 0; $i < $msgCount6; $i++) {
            $sum += $chSock6->receive();
        }
        return $sum;
    });
    safeTeardown($chSock6);
    BenchHelper::timing("Socket transport:", $socketMs6);
    BenchHelper::info(
        "    Per-message (socket): ~" .
            BenchHelper::formatMs($socketMs6 / $msgCount6),
    );

    BenchHelper::comparison("1000 small msgs", $fileMs6, $socketMs6);
    BenchHelper::record(
        "1000 small msgs",
        $fileMs6,
        $socketMs6,
        "integer payloads",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 7: Channel state query overhead
    //
    // Repeated calls to isClosed(), isEmpty(), size(). File transport
    // must read the entire file each time. Socket transport sends a
    // short TCP command.
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

    // --- Socket transport ---
    $chSock7 = createSocketChannel($uniquePrefix . "t7_socket", 10);
    $chSock7->send("data");
    [, $socketMs7] = BenchHelper::measure(function () use (
        $chSock7,
        $queryCount,
    ) {
        for ($i = 0; $i < $queryCount; $i++) {
            $chSock7->isClosed();
            $chSock7->isEmpty();
            $chSock7->size();
        }
    });
    safeTeardown($chSock7);
    BenchHelper::timing("Socket transport:", $socketMs7);
    BenchHelper::info(
        "    Per-query (socket): ~" .
            BenchHelper::formatMs($socketMs7 / ($queryCount * 3)),
    );

    BenchHelper::comparison("600 state queries", $fileMs7, $socketMs7);
    BenchHelper::record(
        "600 state queries",
        $fileMs7,
        $socketMs7,
        "isClosed+isEmpty+size",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 8: Interleaved send/receive — 500 messages
    //
    // Alternating send then receive. This is the most common real-world
    // pattern (producer and consumer running concurrently).
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader("Test 8: Interleaved send/receive — 500 msgs");

    $msgCount8 = 500;

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

    // --- Socket transport ---
    $chSock8 = createSocketChannel($uniquePrefix . "t8_socket", 10);
    [, $socketMs8] = BenchHelper::measure(function () use (
        $chSock8,
        $msgCount8,
    ) {
        $sum = 0;
        for ($i = 0; $i < $msgCount8; $i++) {
            $chSock8->send($i);
            $sum += $chSock8->receive();
        }
        return $sum;
    });
    safeTeardown($chSock8);
    BenchHelper::timing("Socket transport:", $socketMs8);

    BenchHelper::comparison("500 interleaved msgs", $fileMs8, $socketMs8);
    BenchHelper::record(
        "500 interleaved msgs",
        $fileMs8,
        $socketMs8,
        "send-recv-send-recv",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 9: IO dispatcher producer -> main consumer (cross-process)
    //
    // An IO child process sends data through the channel, and the main
    // process receives it. This tests the actual cross-process path.
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

    // --- Socket transport ---
    $chSock9 = Channel::create($msgCount9 + 10);
    $chSock9Port = $chSock9->getSocketPort();
    $chSock9Name = $chSock9->getName();
    [, $socketMs9] = BenchHelper::measure(function () use (
        $chSock9,
        $chSock9Port,
        $chSock9Name,
        $msgCount9,
    ) {
        $sum = 0;
        RunBlocking::new(function () use (
            $chSock9,
            $chSock9Port,
            $chSock9Name,
            $msgCount9,
            &$sum,
        ) {
            $job = Async::new(function () use (
                $chSock9Name,
                $chSock9Port,
                $msgCount9,
            ) {
                $ch = Channel::connectSocketByPort($chSock9Name, $chSock9Port);
                for ($i = 0; $i < $msgCount9; $i++) {
                    $ch->send($i);
                }
                $ch->cleanup();
                return true;
            }, Dispatchers::IO);

            $job->await();

            for ($i = 0; $i < $msgCount9; $i++) {
                $val = $chSock9->tryReceive();
                if ($val !== null) {
                    $sum += $val;
                }
            }
            Thread::await();
        });
        Thread::await();
        return $sum;
    });
    safeTeardown($chSock9);
    BenchHelper::timing("Socket transport:", $socketMs9);

    BenchHelper::comparison("IO→main 50 msgs", $fileMs9, $socketMs9);
    BenchHelper::record(
        "IO→main 50 msgs",
        $fileMs9,
        $socketMs9,
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

    // --- Socket transport ---
    $chSock10 = Channel::create($msgCount10 + 10);
    $chSock10Port = $chSock10->getSocketPort();
    $chSock10Name = $chSock10->getName();
    for ($i = 0; $i < $msgCount10; $i++) {
        $chSock10->send($i);
    }

    [, $socketMs10] = BenchHelper::measure(function () use (
        $chSock10Name,
        $chSock10Port,
        $msgCount10,
    ) {
        $sum = 0;
        RunBlocking::new(function () use (
            $chSock10Name,
            $chSock10Port,
            $msgCount10,
            &$sum,
        ) {
            $job = Async::new(function () use (
                $chSock10Name,
                $chSock10Port,
                $msgCount10,
            ) {
                $ch = Channel::connectSocketByPort(
                    $chSock10Name,
                    $chSock10Port,
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
    safeTeardown($chSock10);
    BenchHelper::timing("Socket transport:", $socketMs10);

    BenchHelper::comparison("main→IO 50 msgs", $fileMs10, $socketMs10);
    BenchHelper::record(
        "main→IO 50 msgs",
        $fileMs10,
        $socketMs10,
        "cross-process consumer",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 11: Throughput scaling — increasing message counts
    //
    // Measure per-message cost at different scales to see if file
    // transport degrades as the buffer grows.
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::subHeader("Test 11: Throughput scaling");

    $scaleCounts = [50, 100, 250, 500];

    BenchHelper::info(
        sprintf(
            "    %-8s  %12s  %12s  %12s  %12s",
            "Msgs",
            "File(total)",
            "File/msg",
            "Socket(total)",
            "Socket/msg",
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

        // Socket
        $chS = createSocketChannel($uniquePrefix . "t11_socket_{$n}", $n + 10);
        [, $sMs] = BenchHelper::measure(function () use ($chS, $n) {
            for ($i = 0; $i < $n; $i++) {
                $chS->send($i);
            }
            for ($i = 0; $i < $n; $i++) {
                $chS->receive();
            }
        });
        safeTeardown($chS);

        $fPerMsg = $fMs / $n;
        $sPerMsg = $sMs / $n;

        BenchHelper::info(
            sprintf(
                "    %-8d  %12s  %12s  %12s  %12s",
                $n,
                BenchHelper::formatMs($fMs),
                BenchHelper::formatMs($fPerMsg),
                BenchHelper::formatMs($sMs),
                BenchHelper::formatMs($sPerMsg),
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
    // File transport: create temp file + mutex files
    // Socket transport: spawn broker process + TCP server + connect
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

    // --- Socket transport ---
    [, $socketCreateMs] = BenchHelper::measure(function () use ($createCount) {
        for ($i = 0; $i < $createCount; $i++) {
            $ch = Channel::create(10);
            safeTeardown($ch);
        }
    });
    BenchHelper::timing(
        "Socket ({$createCount}× create+destroy):",
        $socketCreateMs,
    );
    BenchHelper::info(
        "    Per-channel (socket): ~" .
            BenchHelper::formatMs($socketCreateMs / $createCount),
    );

    BenchHelper::comparison(
        "{$createCount}× create+destroy",
        $fileCreateMs,
        $socketCreateMs,
    );
    BenchHelper::record(
        "5× create+destroy",
        $fileCreateMs,
        $socketCreateMs,
        "channel lifecycle cost",
    );

    // ═════════════════════════════════════════════════════════════════
    // Print Summary
    // ═════════════════════════════════════════════════════════════════
    BenchHelper::printSummary();

    // Additional interpretation
    echo "\n";
    echo "    \033[1mInterpretation:\033[0m\n";
    echo "    ┌──────────────────────────────────────────────────────────────────────┐\n";
    echo "    │ ASPECT                  │ FILE TRANSPORT      │ SOCKET TRANSPORT     │\n";
    echo "    ├──────────────────────────────────────────────────────────────────────┤\n";
    echo "    │ Per-message overhead    │ High (full buffer   │ Low (single msg      │\n";
    echo "    │                         │ serialize + file IO)│ serialize + TCP)     │\n";
    echo "    │ State queries           │ File read each time │ Short TCP command    │\n";
    echo "    │ Scaling with buffer     │ Degrades (O(n))     │ Constant (O(1))      │\n";
    echo "    │ Channel creation        │ Fast (temp files)   │ Slow (spawn process) │\n";
    echo "    │ Cross-process           │ Mutex contention    │ Event-driven broker  │\n";
    echo "    │ Windows compatibility   │ Works (flock)       │ Works (TCP sockets)  │\n";
    echo "    │ Idle resource usage     │ None (files)        │ Background process   │\n";
    echo "    └──────────────────────────────────────────────────────────────────────┘\n";
    echo "\n";
    echo "    \033[1mRecommendation:\033[0m\n";
    echo "      • Use \033[32mChannel::create()\033[0m for high-throughput / cross-process channels\n";
    echo "      • Use \033[32m\$chan->connect()\033[0m in child processes (no args needed)\n";
    echo "      • Use \033[33mfile transport\033[0m for one-shot / low-frequency channels\n";
    echo "      • Use \033[33mfile transport\033[0m when process spawn overhead is unacceptable\n";
    echo "      • Use \033[36min-process channels\033[0m when no IPC is needed (fastest)\n";
    echo "\n";
});
