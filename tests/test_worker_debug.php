<?php

/**
 * Minimal debugging test for the new true WorkerPool implementation.
 * Tests only the WorkerPool directly — no raw proc_open preamble.
 */

require "../vendor/autoload.php";

use vosaka\foroutines\WorkerPool;
use vosaka\foroutines\Async;
use vosaka\foroutines\Dispatchers;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Thread;
use vosaka\foroutines\Pause;
use vosaka\foroutines\Launch;

use function vosaka\foroutines\main;

function debug(string $msg): void
{
    $ts = number_format(microtime(true), 4, ".", "");
    fwrite(STDERR, "[{$ts}] {$msg}" . PHP_EOL);
}

main(function () {
    debug("=== Test 1: WorkerPool::addAsync (single task) ===");

    WorkerPool::setPoolSize(2);

    RunBlocking::new(function () {
        debug("Inside RunBlocking");
        debug(
            "WorkerPool::isEmpty() = " .
                (WorkerPool::isEmpty() ? "true" : "false"),
        );

        $async = WorkerPool::addAsync(function () {
            return "hello_from_pool";
        });

        debug(
            "After addAsync, WorkerPool::isEmpty() = " .
                (WorkerPool::isEmpty() ? "true" : "false"),
        );
        debug("Calling await...");

        $result = $async->await();
        debug("await returned: " . var_export($result, true));

        if ($result === "hello_from_pool") {
            debug("PASS: WorkerPool returned correct value");
        } else {
            debug(
                "FAIL: Expected 'hello_from_pool', got " .
                    var_export($result, true),
            );
        }

        Thread::await();
    });
    Thread::await();

    debug("");
    debug("=== Test 2: Multiple concurrent tasks ===");

    RunBlocking::new(function () {
        $asyncA = WorkerPool::addAsync(function () {
            usleep(50_000);
            return "A";
        });

        $asyncB = WorkerPool::addAsync(function () {
            usleep(30_000);
            return "B";
        });

        $rA = $asyncA->await();
        $rB = $asyncB->await();

        debug("Result A: {$rA}");
        debug("Result B: {$rB}");

        if ($rA === "A" && $rB === "B") {
            debug("PASS: Multiple tasks returned correct values");
        } else {
            debug("FAIL: Unexpected results");
        }

        Thread::await();
    });
    Thread::await();

    debug("");
    debug("=== Shutdown ===");
    WorkerPool::shutdown();
    debug("All tests complete!");
});
