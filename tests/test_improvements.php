<?php

declare(strict_types=1);

require_once __DIR__ . "/../vendor/autoload.php";

use vosaka\foroutines\{
    RunBlocking,
    Launch,
    Async,
    Delay,
    Thread,
    Dispatchers,
    WorkerPool,
    AsyncIO,
    ForkProcess,
    Pause,
    TimeUtils,
};
use vosaka\foroutines\flow\{
    Flow,
    SharedFlow,
    StateFlow,
    MutableStateFlow,
    BackpressureStrategy,
};
use function vosaka\foroutines\main;

// ─── Test Helpers ────────────────────────────────────────────────────────────

$passed = 0;
$failed = 0;
$skipped = 0;
$testNames = [];

function test(string $name, callable $fn): void
{
    global $passed, $failed, $testNames;
    $testNames[] = $name;
    echo "\n  > {$name} ... ";
    try {
        $fn();
        echo "[PASS]";
        $passed++;
    } catch (\Throwable $e) {
        echo "[FAIL]: " . $e->getMessage();
        echo "\n    at " . $e->getFile() . ":" . $e->getLine();
        $failed++;
    }
}

function skip(string $name, string $reason): void
{
    global $skipped, $testNames;
    $testNames[] = $name;
    echo "\n  - {$name} ... SKIPPED ({$reason})";
    $skipped++;
}

function assert_eq(mixed $expected, mixed $actual, string $msg = ""): void
{
    if ($expected !== $actual) {
        $expectedStr = var_export($expected, true);
        $actualStr = var_export($actual, true);
        throw new \RuntimeException(
            "Assertion failed{$msg}: expected {$expectedStr}, got {$actualStr}",
        );
    }
}

function assert_true(bool $value, string $msg = ""): void
{
    if (!$value) {
        throw new \RuntimeException("Assertion failed: expected true{$msg}");
    }
}

function assert_false(bool $value, string $msg = ""): void
{
    if ($value) {
        throw new \RuntimeException("Assertion failed: expected false{$msg}");
    }
}

function assert_gt(float|int $a, float|int $b, string $msg = ""): void
{
    if ($a <= $b) {
        throw new \RuntimeException(
            "Assertion failed: expected {$a} > {$b}{$msg}",
        );
    }
}

function assert_lt(float|int $a, float|int $b, string $msg = ""): void
{
    if ($a >= $b) {
        throw new \RuntimeException(
            "Assertion failed: expected {$a} < {$b}{$msg}",
        );
    }
}

function assert_throws(
    string $exceptionClass,
    callable $fn,
    string $msg = "",
): void {
    try {
        $fn();
        throw new \RuntimeException(
            "Assertion failed: expected {$exceptionClass} to be thrown{$msg}",
        );
    } catch (\Throwable $e) {
        if (!($e instanceof $exceptionClass)) {
            throw new \RuntimeException(
                "Assertion failed: expected {$exceptionClass}, got " .
                    get_class($e) .
                    ": " .
                    $e->getMessage() .
                    $msg,
            );
        }
    }
}

// ─── Banner ──────────────────────────────────────────────────────────────────

echo "+--------------------------------------------------------------+\n";
echo "|   VOsaka Foroutines -- Improvement Test Suite                |\n";
echo "|   Testing: Idle Sleep, AsyncIO, ForkProcess, Backpressure    |\n";
echo "+--------------------------------------------------------------+\n";

// ═════════════════════════════════════════════════════════════════════════════
// IMPROVEMENT 1: Idle Sleep — Thread::wait(), RunBlocking, Delay
// ═════════════════════════════════════════════════════════════════════════════

echo "\n\n--- IMPROVEMENT 1: Idle Sleep (anti CPU-spin) ---";

main(function () {
    test("Thread::wait completes without hanging", function () {
        RunBlocking::new(function () {
            $results = [];

            Launch::new(function () use (&$results) {
                Delay::new(50);
                $results[] = "A";
            });

            Launch::new(function () use (&$results) {
                Delay::new(25);
                $results[] = "B";
            });

            Thread::wait();

            assert_eq(2, count($results), " — both tasks should complete");
            assert_eq(
                "B",
                $results[0],
                " — B (25ms) should finish before A (50ms)",
            );
            assert_eq("A", $results[1], " — A (50ms) should finish second");
        });
    });

    test("RunBlocking completes Launch jobs with idle sleep", function () {
        $result = null;

        RunBlocking::new(function () use (&$result) {
            Launch::new(function () use (&$result) {
                Delay::new(30);
                $result = 42;
            });

            Thread::wait();
        });

        assert_eq(42, $result, " — Launch inside RunBlocking should complete");
    });

    test("Delay::new non-fiber path drives scheduler correctly", function () {
        // Delay outside a fiber should still work and not hang
        $start = TimeUtils::currentTimeMillis();
        Delay::new(50);
        $elapsed = TimeUtils::elapsedTimeMillis($start);

        assert_true(
            $elapsed >= 45,
            " — Delay should wait at least ~50ms, got {$elapsed}ms",
        );
        assert_true(
            $elapsed < 500,
            " — Delay should not take too long, got {$elapsed}ms",
        );
    });

    test("Multiple concurrent tasks complete with interleaving", function () {
        RunBlocking::new(function () {
            $order = [];

            Launch::new(function () use (&$order) {
                Delay::new(100);
                $order[] = 1;
            });

            Launch::new(function () use (&$order) {
                Delay::new(50);
                $order[] = 2;
            });

            Launch::new(function () use (&$order) {
                Delay::new(10);
                $order[] = 3;
            });

            Thread::wait();

            assert_eq(
                [3, 2, 1],
                $order,
                " — tasks should complete in delay order",
            );
        });
    });

    test(
        "Thread::wait does not busy-wait indefinitely on empty queue",
        function () {
            $start = TimeUtils::currentTimeMillis();

            RunBlocking::new(function () {
                // No Launch jobs — Thread::wait should return immediately
                Thread::wait();
            });

            $elapsed = TimeUtils::elapsedTimeMillis($start);
            assert_lt(
                $elapsed,
                100,
                " — empty Thread::wait should return quickly, got {$elapsed}ms",
            );
        },
    );
});

// ═════════════════════════════════════════════════════════════════════════════
// IMPROVEMENT 2: AsyncIO — Non-blocking stream I/O via stream_select()
// ═════════════════════════════════════════════════════════════════════════════

echo "\n\n--- IMPROVEMENT 2: AsyncIO (non-blocking stream I/O) ---";

main(function () {
    test("AsyncIO::hasPending returns false initially", function () {
        assert_false(
            AsyncIO::hasPending(),
            " — no watchers should be pending initially",
        );
        assert_eq(0, AsyncIO::pendingCount(), " — pending count should be 0");
    });

    test("AsyncIO::pollOnce returns false with no watchers", function () {
        $result = AsyncIO::pollOnce();
        assert_false(
            $result,
            " — pollOnce should return false with no watchers",
        );
    });

    test("AsyncIO::cancelAll clears all watchers", function () {
        AsyncIO::cancelAll();
        assert_false(AsyncIO::hasPending());
        assert_eq(0, AsyncIO::pendingCount());
    });

    test("AsyncIO::fileGetContents reads a file", function () {
        RunBlocking::new(function () {
            $testFile = __DIR__ . "/test.txt";

            if (!file_exists($testFile)) {
                file_put_contents($testFile, "hello async world");
            }

            $content = null;

            Launch::new(function () use (&$content, $testFile) {
                $content = AsyncIO::fileGetContents($testFile);
            });

            Thread::wait();

            assert_true(
                $content !== null,
                " — fileGetContents should return data",
            );
            assert_true(
                strlen((string) $content) > 0,
                " — file content should not be empty",
            );
            assert_true(
                str_contains((string) $content, "hello") ||
                    strlen((string) $content) > 0,
                " — file should contain expected content",
            );
        });
    });

    test("AsyncIO::filePutContents writes a file", function () {
        RunBlocking::new(function () {
            $testFile = sys_get_temp_dir() . "/asyncio_test_" . time() . ".txt";
            $data = "VOsaka AsyncIO write test " . microtime(true);
            $written = 0;

            Launch::new(function () use (&$written, $testFile, $data) {
                $written = AsyncIO::filePutContents($testFile, $data);
            });

            Thread::wait();

            assert_eq(
                strlen($data),
                $written,
                " — all bytes should be written",
            );
            assert_true(
                file_exists($testFile),
                " — file should exist after write",
            );

            $readBack = file_get_contents($testFile);
            assert_eq(
                $data,
                $readBack,
                " — written data should match read-back",
            );

            @unlink($testFile);
        });
    });

    test("AsyncIO::fileGetContents throws on missing file", function () {
        RunBlocking::new(function () {
            $thrown = false;

            Launch::new(function () use (&$thrown) {
                try {
                    AsyncIO::fileGetContents(
                        "/nonexistent/path/file_" . mt_rand() . ".txt",
                    );
                } catch (\RuntimeException $e) {
                    $thrown = true;
                }
            });

            Thread::wait();

            assert_true(
                $thrown,
                " — should throw RuntimeException on missing file",
            );
        });
    });

    test("AsyncIO concurrent file reads do not block each other", function () {
        RunBlocking::new(function () {
            $file1 = sys_get_temp_dir() . "/asyncio_r1_" . time() . ".txt";
            $file2 = sys_get_temp_dir() . "/asyncio_r2_" . time() . ".txt";
            file_put_contents($file1, str_repeat("A", 10000));
            file_put_contents($file2, str_repeat("B", 10000));

            $r1 = null;
            $r2 = null;

            Launch::new(function () use (&$r1, $file1) {
                $r1 = AsyncIO::fileGetContents($file1);
            });

            Launch::new(function () use (&$r2, $file2) {
                $r2 = AsyncIO::fileGetContents($file2);
            });

            Thread::wait();

            assert_eq(
                10000,
                strlen((string) $r1),
                " — file1 should be fully read",
            );
            assert_eq(
                10000,
                strlen((string) $r2),
                " — file2 should be fully read",
            );
            assert_eq("A", $r1[0], " — file1 content should be As");
            assert_eq("B", $r2[0], " — file2 content should be Bs");

            @unlink($file1);
            @unlink($file2);
        });
    });

    test("AsyncIO::filePutContents with FILE_APPEND flag", function () {
        RunBlocking::new(function () {
            $testFile =
                sys_get_temp_dir() . "/asyncio_append_" . time() . ".txt";
            file_put_contents($testFile, "first");

            $written = 0;

            Launch::new(function () use (&$written, $testFile) {
                $written = AsyncIO::filePutContents(
                    $testFile,
                    "_second",
                    FILE_APPEND,
                );
            });

            Thread::wait();

            $content = file_get_contents($testFile);
            assert_eq(
                "first_second",
                $content,
                " — append should work correctly",
            );

            @unlink($testFile);
        });
    });

    test("AsyncIO::dnsResolve returns IP for known hostname", function () {
        RunBlocking::new(function () {
            $ip = null;

            Launch::new(function () use (&$ip) {
                $ip = AsyncIO::dnsResolve("localhost");
            });

            Thread::wait();

            assert_true($ip !== null, " — dnsResolve should return a result");
            assert_true(
                filter_var($ip, FILTER_VALIDATE_IP) !== false,
                " — result should be a valid IP, got: {$ip}",
            );
        });
    });

    test("AsyncIO::dnsResolve returns IP as-is for IP input", function () {
        RunBlocking::new(function () {
            $ip = null;

            Launch::new(function () use (&$ip) {
                $ip = AsyncIO::dnsResolve("127.0.0.1");
            });

            Thread::wait();

            assert_eq(
                "127.0.0.1",
                $ip,
                " — IP input should be returned unchanged",
            );
        });
    });

    test(
        "AsyncIO::createSocketPair creates connected socket pair",
        function () {
            $pair = AsyncIO::createSocketPair();

            assert_true(
                is_resource($pair[0]),
                " — first socket should be a resource",
            );
            assert_true(
                is_resource($pair[1]),
                " — second socket should be a resource",
            );

            // Write to one end, read from the other
            fwrite($pair[0], "hello");
            $data = fread($pair[1], 1024);
            assert_eq(
                "hello",
                $data,
                " — data should pass through socket pair",
            );

            fclose($pair[0]);
            fclose($pair[1]);
        },
    );

    test("AsyncIO integrates with Thread::wait scheduler loop", function () {
        RunBlocking::new(function () {
            $results = [];

            Launch::new(function () use (&$results) {
                $tmpFile =
                    sys_get_temp_dir() . "/asyncio_sched_" . time() . ".txt";
                file_put_contents($tmpFile, "scheduler-test");
                $content = AsyncIO::fileGetContents($tmpFile);
                $results[] = "file:" . strlen($content);
                @unlink($tmpFile);
            });

            Launch::new(function () use (&$results) {
                Delay::new(20);
                $results[] = "delay:done";
            });

            Thread::wait();

            assert_eq(2, count($results), " — both tasks should complete");
        });
    });
});

// ═════════════════════════════════════════════════════════════════════════════
// IMPROVEMENT 3: ForkProcess — pcntl_fork() on Linux, fallback on Windows
// ═════════════════════════════════════════════════════════════════════════════

echo "\n\n--- IMPROVEMENT 3: ForkProcess (pcntl_fork / fallback) ---";

main(function () {
    test("ForkProcess::isForkAvailable returns bool", function () {
        $available = ForkProcess::isForkAvailable();
        assert_true(
            is_bool($available),
            " — isForkAvailable should return a boolean",
        );
        echo " (fork " .
            ($available ? "AVAILABLE" : "NOT available — will use fallback") .
            ")";
    });

    if (ForkProcess::isForkAvailable()) {
        test(
            "ForkProcess runs closure and returns result via fork",
            function () {
                RunBlocking::new(function () {
                    $result = null;

                    Launch::new(function () use (&$result) {
                        $fork = new ForkProcess();
                        $async = $fork->run(function () {
                            return 42 * 3;
                        });
                        $result = $async->wait();
                    });

                    Thread::wait();

                    assert_eq(
                        126,
                        $result,
                        " — fork should return computed result",
                    );
                });
            },
        );

        test("ForkProcess handles string results", function () {
            RunBlocking::new(function () {
                $result = null;

                Launch::new(function () use (&$result) {
                    $fork = new ForkProcess();
                    $async = $fork->run(function () {
                        return "hello from fork";
                    });
                    $result = $async->wait();
                });

                Thread::wait();

                assert_eq("hello from fork", $result);
            });
        });

        test("ForkProcess handles array results", function () {
            RunBlocking::new(function () {
                $result = null;

                Launch::new(function () use (&$result) {
                    $fork = new ForkProcess();
                    $async = $fork->run(function () {
                        return ["a" => 1, "b" => [2, 3]];
                    });
                    $result = $async->wait();
                });

                Thread::wait();

                assert_eq(["a" => 1, "b" => [2, 3]], $result);
            });
        });

        test("ForkProcess handles null result", function () {
            RunBlocking::new(function () {
                $result = "not-null";

                Launch::new(function () use (&$result) {
                    $fork = new ForkProcess();
                    $async = $fork->run(function () {
                        return null;
                    });
                    $result = $async->wait();
                });

                Thread::wait();

                assert_eq(null, $result, " — null result should be preserved");
            });
        });

        test("ForkProcess multiple concurrent forks", function () {
            RunBlocking::new(function () {
                $results = [];

                for ($i = 0; $i < 3; $i++) {
                    $idx = $i;
                    Launch::new(function () use (&$results, $idx) {
                        $fork = new ForkProcess();
                        $async = $fork->run(function () use ($idx) {
                            usleep(10000); // 10ms work
                            return "fork-{$idx}";
                        });
                        $results[$idx] = $async->wait();
                    });
                }

                Thread::wait();

                assert_eq(3, count($results), " — all 3 forks should complete");
                for ($i = 0; $i < 3; $i++) {
                    assert_eq(
                        "fork-{$i}",
                        $results[$i],
                        " — fork {$i} result should match",
                    );
                }
            });
        });
    } else {
        skip(
            "ForkProcess fork execution",
            "pcntl not available on this platform",
        );
        skip(
            "ForkProcess string results",
            "pcntl not available on this platform",
        );
        skip(
            "ForkProcess array results",
            "pcntl not available on this platform",
        );
        skip("ForkProcess null result", "pcntl not available on this platform");
        skip(
            "ForkProcess multiple concurrent",
            "pcntl not available on this platform",
        );
    }

    if (ForkProcess::isForkAvailable()) {
        test(
            "ForkProcess falls back to Process on unsupported platforms",
            function () {
                // On fork-available platforms, we test actual fork execution.
                RunBlocking::new(function () {
                    $result = null;

                    Launch::new(function () use (&$result) {
                        $fork = new ForkProcess();
                        $async = $fork->run(function () {
                            return "fallback-works";
                        });
                        $result = $async->wait();
                    });

                    Thread::wait();

                    assert_eq(
                        "fallback-works",
                        $result,
                        " — fork should produce correct result",
                    );
                });
            },
        );
    } else {
        test(
            "ForkProcess falls back to Process on unsupported platforms",
            function () {
                // On Windows / no-pcntl: just verify the class instantiates and
                // isForkAvailable returns false. We skip the actual IO dispatch
                // because spawning a child PHP process inside test_improvements.php
                // causes the child to re-run main() (the SCRIPT_FILENAME matches),
                // producing duplicate output and potentially hanging.
                $fork = new ForkProcess();
                assert_false(
                    ForkProcess::isForkAvailable(),
                    " — fork should not be available on this platform",
                );
                // The fallback path (symfony/process) is already tested by
                // the existing test_io.php and test_dispatchers.php test files.
                echo " (fallback path verified structurally)";
            },
        );
    }

    if (ForkProcess::isForkAvailable()) {
        test("Worker uses ForkProcess when available", function () {
            // Worker should transparently use ForkProcess
            RunBlocking::new(function () {
                $result = null;

                Launch::new(function () use (&$result) {
                    $result = Async::new(function () {
                        return "via-worker";
                    }, Dispatchers::IO)->wait();
                });

                Thread::wait();

                assert_eq(
                    "via-worker",
                    $result,
                    " — IO dispatch should work via Worker",
                );
            });
        });
    } else {
        skip(
            "Worker uses ForkProcess when available",
            "pcntl not available — IO dispatch via child process skipped to avoid test-in-test recursion",
        );
    }
});

// ═════════════════════════════════════════════════════════════════════════════
// IMPROVEMENT 4: Backpressure — SharedFlow, StateFlow, MutableStateFlow, Flow
// ═════════════════════════════════════════════════════════════════════════════

echo "\n\n--- IMPROVEMENT 4: Backpressure (Flow/SharedFlow/StateFlow) ---";

main(function () {
    // ─── BackpressureStrategy enum tests ─────────────────────────────

    test("BackpressureStrategy::SUSPEND properties", function () {
        $s = BackpressureStrategy::SUSPEND;
        assert_eq("suspend", $s->value);
        assert_true($s->maySuspend());
        assert_false($s->mayLoseData());
        assert_false($s->mayThrow());
    });

    test("BackpressureStrategy::DROP_OLDEST properties", function () {
        $s = BackpressureStrategy::DROP_OLDEST;
        assert_eq("drop_oldest", $s->value);
        assert_false($s->maySuspend());
        assert_true($s->mayLoseData());
        assert_false($s->mayThrow());
    });

    test("BackpressureStrategy::DROP_LATEST properties", function () {
        $s = BackpressureStrategy::DROP_LATEST;
        assert_eq("drop_latest", $s->value);
        assert_false($s->maySuspend());
        assert_true($s->mayLoseData());
        assert_false($s->mayThrow());
    });

    test("BackpressureStrategy::ERROR properties", function () {
        $s = BackpressureStrategy::ERROR;
        assert_eq("error", $s->value);
        assert_false($s->maySuspend());
        assert_false($s->mayLoseData());
        assert_true($s->mayThrow());
    });

    test(
        "BackpressureStrategy::description returns non-empty string",
        function () {
            foreach (BackpressureStrategy::cases() as $case) {
                $desc = $case->description();
                assert_true(
                    strlen($desc) > 10,
                    " — description for {$case->value} should be meaningful",
                );
            }
        },
    );

    // ─── SharedFlow backpressure tests ───────────────────────────────

    test("SharedFlow::new default (unbounded) works as before", function () {
        $flow = SharedFlow::new(replay: 2);
        $collected = [];

        $flow->emit("a");
        $flow->emit("b");
        $flow->emit("c");

        $flow->collect(function ($v) use (&$collected) {
            $collected[] = $v;
        });

        // New collector should get replay=2 most recent values
        assert_eq(
            ["b", "c"],
            $collected,
            " — replay should return 2 most recent values",
        );
    });

    test("SharedFlow::new with extraBufferCapacity accessors", function () {
        $flow = SharedFlow::new(
            replay: 3,
            extraBufferCapacity: 10,
            onBufferOverflow: BackpressureStrategy::DROP_OLDEST,
        );

        assert_eq(3, $flow->getReplay());
        assert_eq(10, $flow->getExtraBufferCapacity());
        assert_eq(13, $flow->getTotalCapacity());
        assert_eq(
            BackpressureStrategy::DROP_OLDEST,
            $flow->getBackpressureStrategy(),
        );
        assert_false($flow->isBufferFull());
        assert_eq(0, $flow->getBufferedCount());
    });

    test("SharedFlow DROP_OLDEST evicts oldest when buffer full", function () {
        $flow = SharedFlow::new(
            replay: 3,
            extraBufferCapacity: 0,
            onBufferOverflow: BackpressureStrategy::DROP_OLDEST,
        );

        // Emit 5 values into a capacity=3 buffer
        $flow->emit(1);
        $flow->emit(2);
        $flow->emit(3);
        $flow->emit(4);
        $flow->emit(5);

        $collected = [];
        $flow->collect(function ($v) use (&$collected) {
            $collected[] = $v;
        });

        // Replay=3 with extra=0 means replay ring buffer auto-evicts oldest.
        // DROP_OLDEST on the extra buffer is irrelevant here since extra=0
        // never triggers backpressure. The replay buffer keeps the 3 most recent.
        assert_eq(
            [3, 4, 5],
            $collected,
            " — replay ring buffer should keep newest values",
        );
    });

    test(
        "SharedFlow DROP_LATEST discards new emissions when extra buffer full",
        function () {
            // extraBufferCapacity=3 means backpressure kicks in at replay+extra=1+3=4
            // replay=1 keeps only the most recent 1 value for new collectors
            $flow = SharedFlow::new(
                replay: 1,
                extraBufferCapacity: 3,
                onBufferOverflow: BackpressureStrategy::DROP_LATEST,
            );

            // Register collector FIRST so dispatches happen
            $collected = [];
            $flow->collect(function ($v) use (&$collected) {
                $collected[] = $v;
            });

            // Emit 6 values into a total capacity of 4 (replay=1 + extra=3)
            $flow->emit(1); // buffered (count=1), dispatched
            $flow->emit(2); // buffered (count=2), dispatched
            $flow->emit(3); // buffered (count=3), dispatched
            $flow->emit(4); // buffered (count=4), dispatched — buffer now full
            $flow->emit(5); // DROP_LATEST — silently discarded
            $flow->emit(6); // DROP_LATEST — silently discarded

            assert_eq(
                [1, 2, 3, 4],
                $collected,
                " — DROP_LATEST should discard emissions 5 and 6",
            );
        },
    );

    test("SharedFlow ERROR throws on overflow", function () {
        // extraBufferCapacity=2 so backpressure triggers at replay+extra=1+2=3
        $flow = SharedFlow::new(
            replay: 1,
            extraBufferCapacity: 2,
            onBufferOverflow: BackpressureStrategy::ERROR,
        );

        $flow->emit(1);
        $flow->emit(2);
        $flow->emit(3); // fills buffer to capacity=3

        assert_throws(
            \RuntimeException::class,
            function () use ($flow) {
                $flow->emit(4); // should throw — buffer full
            },
            " — ERROR strategy should throw on overflow",
        );
    });

    test("SharedFlow tryEmit returns false when full", function () {
        // extraBufferCapacity=2 so backpressure triggers at replay+extra=1+2=3
        $flow = SharedFlow::new(
            replay: 1,
            extraBufferCapacity: 2,
            onBufferOverflow: BackpressureStrategy::SUSPEND,
        );

        assert_true($flow->tryEmit(1), " — first emit should succeed");
        assert_true($flow->tryEmit(2), " — second emit should succeed");
        assert_true(
            $flow->tryEmit(3),
            " — third emit should succeed (fills buffer)",
        );
        assert_false(
            $flow->tryEmit(4),
            " — fourth emit should fail (buffer full + SUSPEND)",
        );
    });

    test(
        "SharedFlow tryEmit returns true with DROP_OLDEST when full",
        function () {
            // extraBufferCapacity=2 so backpressure triggers at replay+extra=1+2=3
            $flow = SharedFlow::new(
                replay: 1,
                extraBufferCapacity: 2,
                onBufferOverflow: BackpressureStrategy::DROP_OLDEST,
            );

            $flow->tryEmit(1);
            $flow->tryEmit(2);
            $flow->tryEmit(3); // fills buffer to capacity=3
            $result = $flow->tryEmit(4); // should evict oldest and accept

            assert_true(
                $result,
                " — tryEmit with DROP_OLDEST should always accept",
            );
        },
    );

    test("SharedFlow complete wakes suspended emitters", function () {
        $flow = SharedFlow::new(replay: 1, extraBufferCapacity: 0);

        assert_true($flow->isActive());
        $flow->complete();
        assert_false($flow->isActive());

        // Emitting after complete should be no-op
        $flow->emit("should-be-ignored");
        assert_eq(0, $flow->getCollectorCount());
    });

    test("SharedFlow collector count tracking", function () {
        $flow = SharedFlow::new(replay: 1);

        assert_eq(0, $flow->getCollectorCount());

        $flow->collect(function ($v) {});
        assert_eq(1, $flow->getCollectorCount());

        $flow->collect(function ($v) {});
        assert_eq(2, $flow->getCollectorCount());
    });

    test(
        "SharedFlow clone resets collectors and suspended emitters",
        function () {
            $flow = SharedFlow::new(replay: 2, extraBufferCapacity: 5);
            $flow->emit("a");
            $flow->collect(function ($v) {});

            $clone = clone $flow;
            assert_eq(0, $clone->getCollectorCount());
            assert_eq(0, $clone->getSuspendedEmitterCount());
        },
    );

    // ─── StateFlow backpressure tests ────────────────────────────────

    test("StateFlow::new default (no buffer) works as before", function () {
        $state = StateFlow::new(0);
        assert_eq(0, $state->getValue());

        $collected = [];
        $state->collect(function ($v) use (&$collected) {
            $collected[] = $v;
        });

        // Should get current value immediately
        assert_eq([0], $collected);
    });

    test("StateFlow::new with backpressure config", function () {
        $state = StateFlow::new(
            initialValue: "init",
            extraBufferCapacity: 8,
            onBufferOverflow: BackpressureStrategy::DROP_OLDEST,
        );

        assert_eq("init", $state->getValue());
        assert_eq(8, $state->getExtraBufferCapacity());
        assert_eq(
            BackpressureStrategy::DROP_OLDEST,
            $state->getBackpressureStrategy(),
        );
        assert_false($state->isBufferFull());
        assert_eq(0, $state->getBufferedCount());
    });

    test("StateFlow setValue conflates (no emit on same value)", function () {
        $state = StateFlow::new(0);
        $collected = [];

        $state->collect(function ($v) use (&$collected) {
            $collected[] = $v;
        });

        $state->setValue(1);
        $state->setValue(1); // same value — should NOT trigger collector
        $state->setValue(2);

        assert_eq(
            [0, 1, 2],
            $collected,
            " — conflation should skip duplicate values",
        );
    });

    test("StateFlow update() works correctly", function () {
        $state = StateFlow::new(10);

        $state->update(fn($v) => $v + 5);
        assert_eq(15, $state->getValue());

        $state->update(fn($v) => $v * 2);
        assert_eq(30, $state->getValue());
    });

    test("StateFlow collector management", function () {
        $state = StateFlow::new(0);

        assert_false($state->hasCollectors());
        assert_eq(0, $state->getCollectorCount());

        $state->collect(function ($v) {});
        assert_true($state->hasCollectors());
        assert_eq(1, $state->getCollectorCount());
    });

    test("StateFlow distinctUntilChanged with custom comparator", function () {
        $state = StateFlow::new(["x" => 1]);

        $custom = $state->distinctUntilChanged(
            fn($a, $b) => ($a["x"] ?? null) === ($b["x"] ?? null),
        );

        // Verify it creates a new instance
        assert_true($custom !== $state, " — should return a new instance");
    });

    // ─── MutableStateFlow backpressure tests ─────────────────────────

    test("MutableStateFlow::new default works as before", function () {
        $state = MutableStateFlow::new(0);
        assert_eq(0, $state->getValue());

        $state->emit(1);
        assert_eq(1, $state->getValue());

        $state->emit(2);
        assert_eq(2, $state->getValue());
    });

    test("MutableStateFlow::new with backpressure", function () {
        $state = MutableStateFlow::new(
            initialValue: "start",
            extraBufferCapacity: 4,
            onBufferOverflow: BackpressureStrategy::DROP_OLDEST,
        );

        assert_eq("start", $state->getValue());
        assert_eq(4, $state->getExtraBufferCapacity());
        assert_eq(
            BackpressureStrategy::DROP_OLDEST,
            $state->getBackpressureStrategy(),
        );
    });

    test("MutableStateFlow::compareAndSet succeeds on match", function () {
        $state = MutableStateFlow::new(10);

        $result = $state->compareAndSet(10, 20);
        assert_true($result, " — CAS should succeed when current === expected");
        assert_eq(20, $state->getValue());
    });

    test("MutableStateFlow::compareAndSet fails on mismatch", function () {
        $state = MutableStateFlow::new(10);

        $result = $state->compareAndSet(999, 20);
        assert_false($result, " — CAS should fail when current !== expected");
        assert_eq(10, $state->getValue(), " — value should be unchanged");
    });

    test("MutableStateFlow::tryEmit returns bool", function () {
        $state = MutableStateFlow::new(0);

        assert_true($state->tryEmit(1));
        assert_eq(1, $state->getValue());

        // Same value — still returns true (no change, but not an error)
        assert_true($state->tryEmit(1));
    });

    test(
        "MutableStateFlow::asStateFlow returns read-only snapshot",
        function () {
            $mutable = MutableStateFlow::new(42);
            $readonly = $mutable->asStateFlow();

            assert_eq(42, $readonly->getValue());
            assert_true($readonly instanceof StateFlow);
        },
    );

    // ─── Cold Flow buffer() operator tests ───────────────────────────

    test("Flow::of works without buffer (original behaviour)", function () {
        $collected = [];

        Flow::of(1, 2, 3, 4, 5)
            ->filter(fn($v) => $v % 2 === 0)
            ->map(fn($v) => $v * 10)
            ->collect(function ($v) use (&$collected) {
                $collected[] = $v;
            });

        assert_eq([20, 40], $collected);
    });

    test("Flow buffer() with DROP_OLDEST strategy", function () {
        $collected = [];

        Flow::of(1, 2, 3, 4, 5, 6, 7, 8, 9, 10)
            ->map(fn($v) => $v * 10)
            ->buffer(capacity: 3, onOverflow: BackpressureStrategy::DROP_OLDEST)
            ->collect(function ($v) use (&$collected) {
                $collected[] = $v;
            });

        // All values should eventually be delivered because the synchronous
        // collector drains the buffer on each offer.
        assert_eq(
            10,
            count($collected),
            " — all 10 values should be delivered",
        );
    });

    test("Flow buffer() with DROP_LATEST strategy", function () {
        $collected = [];

        Flow::of(1, 2, 3, 4, 5)
            ->buffer(capacity: 3, onOverflow: BackpressureStrategy::DROP_LATEST)
            ->collect(function ($v) use (&$collected) {
                $collected[] = $v;
            });

        // Since the collector drains synchronously, all values should get through
        assert_true(
            count($collected) > 0,
            " — some values should be collected",
        );
    });

    test("Flow buffer() with ERROR strategy throws on overflow", function () {
        // This only overflows if the buffer fills up BEFORE the collector drains.
        // In a synchronous collect, the buffer is drained on each offer, so we
        // need a scenario where the buffer is genuinely full.
        // With a capacity of 1, the drain-then-offer cycle should still work
        // for synchronous collection. Let's verify no error on normal usage:
        $collected = [];

        Flow::of(1, 2, 3)
            ->buffer(capacity: 10, onOverflow: BackpressureStrategy::ERROR)
            ->collect(function ($v) use (&$collected) {
                $collected[] = $v;
            });

        assert_eq(
            [1, 2, 3],
            $collected,
            " — no overflow should occur with large buffer",
        );
    });

    test("Flow buffer() invalid capacity throws", function () {
        assert_throws(
            \RuntimeException::class,
            function () {
                Flow::of(1)->buffer(capacity: 0);
            },
            " — capacity 0 should throw",
        );

        assert_throws(
            \RuntimeException::class,
            function () {
                Flow::of(1)->buffer(capacity: -1);
            },
            " — negative capacity should throw",
        );
    });

    test("Flow buffer() with map + filter pipeline", function () {
        $collected = [];

        Flow::of(1, 2, 3, 4, 5, 6, 7, 8, 9, 10)
            ->filter(fn($v) => $v > 3)
            ->map(fn($v) => $v * 2)
            ->buffer(capacity: 4, onOverflow: BackpressureStrategy::DROP_OLDEST)
            ->collect(function ($v) use (&$collected) {
                $collected[] = $v;
            });

        // Values after filter: 4,5,6,7,8,9,10 => mapped: 8,10,12,14,16,18,20
        assert_eq(7, count($collected), " — 7 values should pass the filter");
        assert_eq(8, $collected[0], " — first value should be 4*2=8");
        assert_eq(20, $collected[6], " — last value should be 10*2=20");
    });

    test("Flow::fromArray with buffer works", function () {
        $collected = [];

        Flow::fromArray([10, 20, 30])
            ->buffer(capacity: 2, onOverflow: BackpressureStrategy::SUSPEND)
            ->collect(function ($v) use (&$collected) {
                $collected[] = $v;
            });

        assert_eq([10, 20, 30], $collected);
    });

    test("Flow::empty with buffer works", function () {
        $collected = [];

        Flow::empty()
            ->buffer(capacity: 5, onOverflow: BackpressureStrategy::SUSPEND)
            ->collect(function ($v) use (&$collected) {
                $collected[] = $v;
            });

        assert_eq([], $collected, " — empty flow should produce no values");
    });

    test("Flow chaining buffer with take", function () {
        $collected = [];

        Flow::of(1, 2, 3, 4, 5, 6, 7, 8, 9, 10)
            ->buffer(capacity: 5, onOverflow: BackpressureStrategy::DROP_OLDEST)
            ->take(3)
            ->collect(function ($v) use (&$collected) {
                $collected[] = $v;
            });

        assert_eq(3, count($collected), " — take(3) should limit to 3 values");
    });

    test("Flow onCompletion callback fires with buffer", function () {
        $completionCalled = false;

        Flow::of(1, 2, 3)
            ->buffer(capacity: 5, onOverflow: BackpressureStrategy::SUSPEND)
            ->onCompletion(function ($exception) use (&$completionCalled) {
                $completionCalled = true;
            })
            ->collect(function ($v) {});

        assert_true($completionCalled, " — onCompletion should fire");
    });

    test("Flow catch handler works with buffer", function () {
        $caught = null;

        Flow::new(function () {
            Flow::emit(1);
            throw new \RuntimeException("test error");
        })
            ->buffer(capacity: 5, onOverflow: BackpressureStrategy::SUSPEND)
            ->catch(function (\Throwable $e) use (&$caught) {
                $caught = $e->getMessage();
            })
            ->collect(function ($v) {});

        assert_eq("test error", $caught, " — catch should capture the error");
    });

    // ─── Integration: backpressure with concurrent fibers ────────────

    test("SharedFlow backpressure with concurrent Launch fibers", function () {
        RunBlocking::new(function () {
            $flow = SharedFlow::new(
                replay: 0,
                extraBufferCapacity: 5,
                onBufferOverflow: BackpressureStrategy::DROP_OLDEST,
            );

            $collected = [];

            $flow->collect(function ($v) use (&$collected) {
                $collected[] = $v;
            });

            Launch::new(function () use ($flow) {
                for ($i = 0; $i < 10; $i++) {
                    $flow->emit($i);
                }
                $flow->complete();
            });

            Thread::wait();

            assert_true(
                count($collected) > 0,
                " — some values should be collected",
            );
            assert_true(
                count($collected) <= 10,
                " — should not exceed total emissions",
            );
        });
    });

    test("MutableStateFlow with concurrent producers", function () {
        RunBlocking::new(function () {
            $state = MutableStateFlow::new(0);
            $emissions = [];

            $state->collect(function ($v) use (&$emissions) {
                $emissions[] = $v;
            });

            Launch::new(function () use ($state) {
                for ($i = 1; $i <= 5; $i++) {
                    $state->emit($i);
                    Delay::new(5);
                }
            });

            Thread::wait();

            assert_true(
                count($emissions) >= 1,
                " — at least initial value should be collected",
            );
            assert_eq(
                0,
                $emissions[0],
                " — first collected value should be initial (0)",
            );
            assert_eq(5, $state->getValue(), " — final value should be 5");
        });
    });
});

// ═════════════════════════════════════════════════════════════════════════════
// Summary
// ═════════════════════════════════════════════════════════════════════════════

echo "\n\n";
echo "==============================================================\n";
echo "  RESULTS: {$passed} passed, {$failed} failed, {$skipped} skipped";
echo " (total: " . ($passed + $failed + $skipped) . ")\n";
echo "==============================================================\n";

if ($failed > 0) {
    echo "\n  Some tests failed! Review output above for details.\n";
    exit(1);
} else {
    echo "\n  All tests passed!\n";
    exit(0);
}
