<?php

/**
 * Test: Structured Concurrency Patterns
 *
 * Demonstrates structured concurrency features of the library including:
 *
 *   - Multiple Launch jobs running concurrently with shared state
 *   - Async tasks returning values composed together
 *   - Sequential async pipeline (result of one feeds next)
 *   - Fan-out pattern: one parent launches many children
 *   - Fan-in pattern: many producers feed into a single result
 *   - Cancellation of a job before it completes
 *   - Cancellation does not affect already-completed siblings
 *   - Launch with Delay ordering (shortest delay finishes first)
 *   - Async wait inside Launch
 *   - Multiple Async waits in sequence inside one Launch
 *   - Parent collects results from child Launches via shared state
 *   - Deep Async return value propagation (nested Async::wait)
 *   - Combining Launch + Async in same scope
 *   - RunBlocking as a structured scope
 *   - Structured scope enforces completion order (sequential RunBlocking)
 *
 * Expected output:
 *   "=== Test 1: Multiple concurrent Launches ==="
 *   "Task A done"
 *   "Task B done"
 *   "Task C done"
 *   "All 3 tasks completed"
 *   "=== Test 2: Async value composition ==="
 *   "Sum of async results: 60"
 *   "=== Test 3: Sequential async pipeline ==="
 *   "Pipeline result: 20"
 *   "=== Test 4: Fan-out pattern ==="
 *   "Fan-out total: 10"
 *   "=== Test 5: Fan-in with shared accumulator ==="
 *   "Fan-in sum: 150"
 *   "=== Test 6: Cancel pending job ==="
 *   "Job cancelled: yes"
 *   "Is final: yes"
 *   "=== Test 7: Cancel does not affect completed sibling ==="
 *   "Completed before cancel: yes"
 *   "Cancelled job state: cancelled"
 *   "=== Test 8: Launch with Delay ordering ==="
 *   "Fastest"
 *   "Medium"
 *   "Slowest"
 *   "Order: [Fastest, Medium, Slowest]"
 *   "=== Test 9: Async wait inside Launch ==="
 *   "Launch got async value: 77"
 *   "=== Test 10: Multiple Async waits in sequence ==="
 *   "Sequential sum: 60"
 *   "=== Test 11: Parent collects results via shared state ==="
 *   "Collected results: [10, 20, 30]"
 *   "=== Test 12: Deep Async return value propagation ==="
 *   "Deep value: 1000"
 *   "=== Test 13: Launch + Async combined in same scope ==="
 *   "Launch side effect done"
 *   "Async returned: 99"
 *   "=== Test 14: RunBlocking as structured scope ==="
 *   "Child A done"
 *   "Child B done"
 *   "Child C done"
 *   "All children completed"
 *   "=== Test 15: Sequential RunBlocking scopes ==="
 *   "Scope 1 done"
 *   "Between scopes"
 *   "Scope 2 done"
 *   "Final"
 *   "=== Test 16: Shared mutable state across children ==="
 *   "Counter: 5"
 *   "=== Test 17: Delay interleaving (fast before slow) ==="
 *   "Fast done"
 *   "Slow done"
 *   "Fast before slow: yes"
 *   "=== Test 18: Three Async tasks composed ==="
 *   "Composed: hello-world-42"
 *   "=== Test 19: Launch many tasks (stress) ==="
 *   "All 20 tasks done, counter: 20"
 *   "=== Test 20: Cancel before run prevents execution ==="
 *   "Cancelled immediately: yes"
 *   "All structured concurrency tests passed"
 */

require "../vendor/autoload.php";

use vosaka\foroutines\Async;
use vosaka\foroutines\Delay;
use vosaka\foroutines\Launch;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Thread;

use function vosaka\foroutines\main;

main(function () {
    // ================================================================
    // Test 1: Multiple concurrent Launches with shared state
    // ================================================================
    var_dump("=== Test 1: Multiple concurrent Launches ===");
    RunBlocking::new(function () {
        $done = 0;

        Launch::new(function () use (&$done) {
            Delay::new(300);
            var_dump("Task A done");
            $done++;
        });
        Launch::new(function () use (&$done) {
            Delay::new(200);
            var_dump("Task B done");
            $done++;
        });
        Launch::new(function () use (&$done) {
            Delay::new(100);
            var_dump("Task C done");
            $done++;
        });

        Thread::wait();
        var_dump("All $done tasks completed");
    });
    Thread::wait();

    // ================================================================
    // Test 2: Async value composition
    // ================================================================
    var_dump("=== Test 2: Async value composition ===");
    RunBlocking::new(function () {
        $a = Async::new(function () {
            Delay::new(100);
            return 10;
        });
        $b = Async::new(function () {
            Delay::new(200);
            return 20;
        });
        $c = Async::new(function () {
            Delay::new(150);
            return 30;
        });

        $sum = $a->wait() + $b->wait() + $c->wait();
        var_dump("Sum of async results: $sum");

        Thread::wait();
    });
    Thread::wait();

    // ================================================================
    // Test 3: Sequential async pipeline
    // ================================================================
    var_dump("=== Test 3: Sequential async pipeline ===");
    RunBlocking::new(function () {
        $step1 = Async::new(function () {
            Delay::new(50);
            return 5;
        });
        $val1 = $step1->wait();

        $step2 = Async::new(function () use ($val1) {
            Delay::new(50);
            return $val1 * 2; // 10
        });
        $val2 = $step2->wait();

        $step3 = Async::new(function () use ($val2) {
            Delay::new(50);
            return $val2 + 10; // 20
        });
        $val3 = $step3->wait();

        var_dump("Pipeline result: $val3");
        Thread::wait();
    });
    Thread::wait();

    // ================================================================
    // Test 4: Fan-out pattern
    // ================================================================
    var_dump("=== Test 4: Fan-out pattern ===");
    RunBlocking::new(function () {
        $total = 0;
        for ($i = 0; $i < 10; $i++) {
            Launch::new(function () use (&$total) {
                Delay::new(50);
                $total++;
            });
        }
        Thread::wait();
        var_dump("Fan-out total: $total");
    });
    Thread::wait();

    // ================================================================
    // Test 5: Fan-in with shared accumulator
    // ================================================================
    var_dump("=== Test 5: Fan-in with shared accumulator ===");
    RunBlocking::new(function () {
        $sum = 0;
        $values = [10, 20, 30, 40, 50];
        foreach ($values as $v) {
            Launch::new(function () use (&$sum, $v) {
                Delay::new(50);
                $sum += $v;
            });
        }
        Thread::wait();
        var_dump("Fan-in sum: $sum");
    });
    Thread::wait();

    // ================================================================
    // Test 6: Cancel pending job
    // ================================================================
    var_dump("=== Test 6: Cancel pending job ===");
    RunBlocking::new(function () {
        $job = Launch::new(function () {
            Delay::new(10000);
            var_dump("This should NOT print");
        });
        $job->cancel();
        var_dump("Job cancelled: " . ($job->isCancelled() ? "yes" : "no"));
        var_dump("Is final: " . ($job->isFinal() ? "yes" : "no"));
        Thread::wait();
    });
    Thread::wait();

    // ================================================================
    // Test 7: Cancel does not affect completed sibling
    // ================================================================
    var_dump("=== Test 7: Cancel does not affect completed sibling ===");
    RunBlocking::new(function () {
        $completedJob = Launch::new(function () {
            // Quick task - completes immediately
        });
        $longJob = Launch::new(function () {
            Delay::new(10000);
            var_dump("This should NOT print");
        });

        // Run the scheduler a few ticks so the quick job completes
        // but the long job is still suspended on Delay
        for ($i = 0; $i < 10; $i++) {
            Launch::getInstance()->runOnce();
        }

        $completedBeforeCancel = $completedJob->isCompleted();

        // Cancel the long job before it finishes
        $longJob->cancel();

        var_dump(
            "Completed before cancel: " .
                ($completedBeforeCancel ? "yes" : "no"),
        );
        var_dump("Cancelled job state: " . $longJob->getStatus()->value);
        Thread::wait();
    });
    Thread::wait();

    // ================================================================
    // Test 8: Launch with Delay ordering
    // ================================================================
    var_dump("=== Test 8: Launch with Delay ordering ===");
    $finishOrder = [];
    RunBlocking::new(function () use (&$finishOrder) {
        Launch::new(function () use (&$finishOrder) {
            Delay::new(300);
            var_dump("Slowest");
            $finishOrder[] = "Slowest";
        });
        Launch::new(function () use (&$finishOrder) {
            Delay::new(100);
            var_dump("Fastest");
            $finishOrder[] = "Fastest";
        });
        Launch::new(function () use (&$finishOrder) {
            Delay::new(200);
            var_dump("Medium");
            $finishOrder[] = "Medium";
        });
        Thread::wait();
    });
    Thread::wait();
    var_dump("Order: [" . implode(", ", $finishOrder) . "]");

    // ================================================================
    // Test 9: Async wait inside Launch
    // ================================================================
    var_dump("=== Test 9: Async wait inside Launch ===");
    RunBlocking::new(function () {
        Launch::new(function () {
            $async = Async::new(function () {
                Delay::new(100);
                return 77;
            });
            $val = $async->wait();
            var_dump("Launch got async value: $val");
        });
        Thread::wait();
    });
    Thread::wait();

    // ================================================================
    // Test 10: Multiple Async waits in sequence inside one Launch
    // ================================================================
    var_dump("=== Test 10: Multiple Async waits in sequence ===");
    RunBlocking::new(function () {
        Launch::new(function () {
            $a = Async::new(function () {
                Delay::new(50);
                return 10;
            });
            $b = Async::new(function () {
                Delay::new(50);
                return 20;
            });
            $c = Async::new(function () {
                Delay::new(50);
                return 30;
            });
            $sum = $a->wait() + $b->wait() + $c->wait();
            var_dump("Sequential sum: $sum");
        });
        Thread::wait();
    });
    Thread::wait();

    // ================================================================
    // Test 11: Parent collects results from child Launches via shared state
    // ================================================================
    var_dump("=== Test 11: Parent collects results via shared state ===");
    RunBlocking::new(function () {
        $results = [];
        Launch::new(function () use (&$results) {
            Delay::new(50);
            $results[] = 10;
        });
        Launch::new(function () use (&$results) {
            Delay::new(100);
            $results[] = 20;
        });
        Launch::new(function () use (&$results) {
            Delay::new(150);
            $results[] = 30;
        });
        Thread::wait();
        sort($results);
        var_dump("Collected results: [" . implode(", ", $results) . "]");
    });
    Thread::wait();

    // ================================================================
    // Test 12: Deep Async return value propagation (4 levels)
    // ================================================================
    var_dump("=== Test 12: Deep Async return value propagation ===");
    RunBlocking::new(function () {
        $deep = Async::new(function () {
            return Async::new(function () {
                return Async::new(function () {
                    return Async::new(function () {
                        return 1000;
                    })->wait();
                })->wait();
            })->wait();
        });
        $val = $deep->wait();
        var_dump("Deep value: $val");
        Thread::wait();
    });
    Thread::wait();

    // ================================================================
    // Test 13: Launch + Async combined in same scope
    // ================================================================
    var_dump("=== Test 13: Launch + Async combined in same scope ===");
    RunBlocking::new(function () {
        Launch::new(function () {
            Delay::new(50);
            var_dump("Launch side effect done");
        });

        $async = Async::new(function () {
            Delay::new(100);
            return 99;
        });
        $val = $async->wait();
        var_dump("Async returned: $val");

        Thread::wait();
    });
    Thread::wait();

    // ================================================================
    // Test 14: RunBlocking as structured scope waits for all children
    // ================================================================
    var_dump("=== Test 14: RunBlocking as structured scope ===");
    RunBlocking::new(function () {
        Launch::new(function () {
            Delay::new(300);
            var_dump("Child A done");
        });
        Launch::new(function () {
            Delay::new(200);
            var_dump("Child B done");
        });
        Launch::new(function () {
            Delay::new(100);
            var_dump("Child C done");
        });
        Thread::wait();
        var_dump("All children completed");
    });
    Thread::wait();

    // ================================================================
    // Test 15: Sequential RunBlocking scopes enforce order
    // ================================================================
    var_dump("=== Test 15: Sequential RunBlocking scopes ===");
    RunBlocking::new(function () {
        Launch::new(function () {
            Delay::new(100);
        });
        Thread::wait();
        var_dump("Scope 1 done");
    });
    Thread::wait();

    var_dump("Between scopes");

    RunBlocking::new(function () {
        Launch::new(function () {
            Delay::new(50);
        });
        Thread::wait();
        var_dump("Scope 2 done");
    });
    Thread::wait();

    var_dump("Final");

    // ================================================================
    // Test 16: Shared mutable state across children
    // ================================================================
    var_dump("=== Test 16: Shared mutable state across children ===");
    RunBlocking::new(function () {
        $counter = 0;
        for ($i = 0; $i < 5; $i++) {
            Launch::new(function () use (&$counter) {
                Delay::new(30 + rand(0, 50));
                $counter++;
            });
        }
        Thread::wait();
        var_dump("Counter: $counter");
    });
    Thread::wait();

    // ================================================================
    // Test 17: Delay interleaving - fast finishes before slow
    // ================================================================
    var_dump("=== Test 17: Delay interleaving (fast before slow) ===");
    $order = [];
    RunBlocking::new(function () use (&$order) {
        Launch::new(function () use (&$order) {
            Delay::new(400);
            var_dump("Slow done");
            $order[] = "slow";
        });
        Launch::new(function () use (&$order) {
            Delay::new(100);
            var_dump("Fast done");
            $order[] = "fast";
        });
        Thread::wait();
    });
    Thread::wait();
    $correct =
        count($order) >= 2 && $order[0] === "fast" && $order[1] === "slow";
    var_dump("Fast before slow: " . ($correct ? "yes" : "no"));

    // ================================================================
    // Test 18: Three Async tasks composed into a string
    // ================================================================
    var_dump("=== Test 18: Three Async tasks composed ===");
    RunBlocking::new(function () {
        $a = Async::new(function () {
            Delay::new(50);
            return "hello";
        });
        $b = Async::new(function () {
            Delay::new(80);
            return "world";
        });
        $c = Async::new(function () {
            Delay::new(30);
            return "42";
        });

        $composed = $a->wait() . "-" . $b->wait() . "-" . $c->wait();
        var_dump("Composed: $composed");
        Thread::wait();
    });
    Thread::wait();

    // ================================================================
    // Test 19: Launch many tasks (stress test)
    // ================================================================
    var_dump("=== Test 19: Launch many tasks (stress) ===");
    RunBlocking::new(function () {
        $counter = 0;
        for ($i = 0; $i < 20; $i++) {
            Launch::new(function () use (&$counter) {
                Delay::new(20 + rand(0, 30));
                $counter++;
            });
        }
        Thread::wait();
        var_dump("All 20 tasks done, counter: $counter");
    });
    Thread::wait();

    // ================================================================
    // Test 20: Cancel before scheduler runs prevents execution
    // ================================================================
    var_dump("=== Test 20: Cancel before run prevents execution ===");
    RunBlocking::new(function () {
        $executed = false;
        $job = Launch::new(function () use (&$executed) {
            $executed = true;
            var_dump("This should NOT print");
        });
        $job->cancel();
        Thread::wait();
        var_dump("Cancelled immediately: " . (!$executed ? "yes" : "no"));
    });
    Thread::wait();

    var_dump("All structured concurrency tests passed");
});
