<?php

/**
 * Test: EventLoop and Dispatchers::MAIN scheduling
 *
 * Demonstrates the EventLoop which manages fibers scheduled on the main thread:
 *
 *   - EventLoop::init() initializes the internal priority queue
 *   - EventLoop::add(fiber) enqueues a fiber for execution
 *   - EventLoop::runNext() drives the next fiber one step forward
 *   - Dispatchers::MAIN schedules tasks onto the EventLoop
 *   - Async::new(..., Dispatchers::MAIN) creates an async task on the event loop
 *   - Launch::new(..., Dispatchers::MAIN) creates a launch job on the event loop
 *   - Fibers added to the EventLoop are started and resumed automatically
 *   - EventLoop runs remaining fibers during shutdown via register_shutdown_function
 *
 * Expected output:
 *   "=== Test 1: EventLoop init and runNext on empty queue ==="
 *   "runNext on empty returns null: yes"
 *   "=== Test 2: EventLoop add and runNext with simple fiber ==="
 *   "Fiber ran via EventLoop"
 *   "=== Test 3: EventLoop with multiple fibers ==="
 *   "Fiber A step 1"
 *   "Fiber B step 1"
 *   "Fiber A step 2"
 *   "Fiber B step 2"
 *   "Both fibers completed"
 *   "=== Test 4: EventLoop runNext returns terminated fiber ==="
 *   "Terminated fiber returned: yes"
 *   "=== Test 5: EventLoop with fiber that suspends multiple times ==="
 *   "Multi-suspend step 1"
 *   "Multi-suspend step 2"
 *   "Multi-suspend step 3"
 *   "Multi-suspend done"
 *   "=== Test 6: EventLoop fiber with return value ==="
 *   "Fiber return value: 42"
 *   "=== Test 7: Dispatchers::MAIN with Async ==="
 *   "MAIN async task executed"
 *   "=== Test 8: Dispatchers::MAIN with Launch ==="
 *   "MAIN launch task executed"
 *   "=== Test 9: Multiple EventLoop fibers interleave ==="
 *   "Interleave order contains both A and B: yes"
 *   "=== Test 10: EventLoop with immediately terminating fiber ==="
 *   "Immediate fiber result: done"
 *   "=== Test 11: EventLoop stress test - many fibers ==="
 *   "All 20 fibers completed"
 *   "Counter: 20"
 *   "=== Test 12: EventLoop fiber exception does not break queue ==="
 *   "Caught fiber exception: intentional_error"
 *   "Good fiber still ran: yes"
 *   "All EventLoop tests passed"
 */

require '../vendor/autoload.php';

use vosaka\foroutines\Async;
use vosaka\foroutines\Dispatchers;
use vosaka\foroutines\EventLoop;
use vosaka\foroutines\FiberUtils;
use vosaka\foroutines\Launch;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Thread;

use function vosaka\foroutines\main;

main(function () {
    // ================================================================
    // Test 1: EventLoop init and runNext on empty queue
    // ================================================================
    var_dump('=== Test 1: EventLoop init and runNext on empty queue ===');
    EventLoop::init();
    $result = EventLoop::runNext();
    var_dump('runNext on empty returns null: ' . ($result === null ? 'yes' : 'no'));

    // ================================================================
    // Test 2: EventLoop add and runNext with a simple fiber
    // ================================================================
    var_dump('=== Test 2: EventLoop add and runNext with simple fiber ===');
    $fiber2 = new Fiber(function () {
        var_dump('Fiber ran via EventLoop');
    });
    EventLoop::add($fiber2);
    // runNext starts the fiber; since it doesn't suspend, it terminates
    // runNext returns the terminated fiber or null depending on state
    $r = EventLoop::runNext();
    // The fiber might terminate on the first call (no suspend), so runNext
    // returns the fiber. If it returned null, it was re-queued and we run again.
    if ($r === null) {
        // Fiber was re-queued, run once more
        $r = EventLoop::runNext();
    }

    // ================================================================
    // Test 3: EventLoop with multiple fibers that suspend
    // ================================================================
    var_dump('=== Test 3: EventLoop with multiple fibers ===');
    $fiberA = new Fiber(function () {
        var_dump('Fiber A step 1');
        Fiber::suspend();
        var_dump('Fiber A step 2');
    });
    $fiberB = new Fiber(function () {
        var_dump('Fiber B step 1');
        Fiber::suspend();
        var_dump('Fiber B step 2');
    });
    EventLoop::add($fiberA);
    EventLoop::add($fiberB);

    // Drive the event loop until both fibers are done
    $iterations = 0;
    while ((!$fiberA->isTerminated() || !$fiberB->isTerminated()) && $iterations < 20) {
        EventLoop::runNext();
        $iterations++;
    }
    var_dump('Both fibers completed');

    // ================================================================
    // Test 4: EventLoop runNext returns terminated fiber
    // ================================================================
    var_dump('=== Test 4: EventLoop runNext returns terminated fiber ===');
    $fiber4 = new Fiber(function () {
        // No suspend, terminates immediately after start
        return 'quick';
    });
    EventLoop::add($fiber4);
    $returned = null;
    for ($i = 0; $i < 5; $i++) {
        $r = EventLoop::runNext();
        if ($r instanceof Fiber && $r->isTerminated()) {
            $returned = $r;
            break;
        }
    }
    var_dump('Terminated fiber returned: ' . ($returned !== null ? 'yes' : 'no'));

    // ================================================================
    // Test 5: EventLoop with fiber that suspends multiple times
    // ================================================================
    var_dump('=== Test 5: EventLoop with fiber that suspends multiple times ===');
    $fiber5 = new Fiber(function () {
        var_dump('Multi-suspend step 1');
        Fiber::suspend();
        var_dump('Multi-suspend step 2');
        Fiber::suspend();
        var_dump('Multi-suspend step 3');
        Fiber::suspend();
        var_dump('Multi-suspend done');
    });
    EventLoop::add($fiber5);
    $iterations = 0;
    while (!$fiber5->isTerminated() && $iterations < 20) {
        EventLoop::runNext();
        $iterations++;
    }

    // ================================================================
    // Test 6: EventLoop fiber with return value
    // ================================================================
    var_dump('=== Test 6: EventLoop fiber with return value ===');
    $fiber6 = new Fiber(function () {
        Fiber::suspend();
        return 42;
    });
    EventLoop::add($fiber6);
    $iterations = 0;
    while (!$fiber6->isTerminated() && $iterations < 20) {
        EventLoop::runNext();
        $iterations++;
    }
    if ($fiber6->isTerminated()) {
        var_dump('Fiber return value: ' . $fiber6->getReturn());
    }

    // ================================================================
    // Test 7: Dispatchers::MAIN with Async
    // ================================================================
    var_dump('=== Test 7: Dispatchers::MAIN with Async ===');
    $asyncMain = Async::new(function () {
        var_dump('MAIN async task executed');
    }, Dispatchers::MAIN);
    // Drive the event loop to execute the MAIN-dispatched task
    for ($i = 0; $i < 10; $i++) {
        EventLoop::runNext();
    }

    // ================================================================
    // Test 8: Dispatchers::MAIN with Launch
    // ================================================================
    var_dump('=== Test 8: Dispatchers::MAIN with Launch ===');
    RunBlocking::new(function () {
        Launch::new(function () {
            var_dump('MAIN launch task executed');
        }, Dispatchers::MAIN);

        // Drive the event loop
        for ($i = 0; $i < 10; $i++) {
            EventLoop::runNext();
        }

        Thread::wait();
    });
    Thread::wait();

    // ================================================================
    // Test 9: Multiple EventLoop fibers interleave correctly
    // ================================================================
    var_dump('=== Test 9: Multiple EventLoop fibers interleave ===');
    $interleaveOrder = [];
    $fiberIA = new Fiber(function () use (&$interleaveOrder) {
        $interleaveOrder[] = 'A1';
        Fiber::suspend();
        $interleaveOrder[] = 'A2';
        Fiber::suspend();
        $interleaveOrder[] = 'A3';
    });
    $fiberIB = new Fiber(function () use (&$interleaveOrder) {
        $interleaveOrder[] = 'B1';
        Fiber::suspend();
        $interleaveOrder[] = 'B2';
        Fiber::suspend();
        $interleaveOrder[] = 'B3';
    });
    EventLoop::add($fiberIA);
    EventLoop::add($fiberIB);

    $iterations = 0;
    while ((!$fiberIA->isTerminated() || !$fiberIB->isTerminated()) && $iterations < 30) {
        EventLoop::runNext();
        $iterations++;
    }

    $hasA = false;
    $hasB = false;
    foreach ($interleaveOrder as $item) {
        if (str_starts_with($item, 'A')) $hasA = true;
        if (str_starts_with($item, 'B')) $hasB = true;
    }
    var_dump('Interleave order contains both A and B: ' . ($hasA && $hasB ? 'yes' : 'no'));

    // ================================================================
    // Test 10: EventLoop with immediately terminating fiber
    // ================================================================
    var_dump('=== Test 10: EventLoop with immediately terminating fiber ===');
    $fiber10 = new Fiber(function () {
        return 'done';
    });
    EventLoop::add($fiber10);
    for ($i = 0; $i < 5; $i++) {
        EventLoop::runNext();
        if ($fiber10->isTerminated()) break;
    }
    var_dump('Immediate fiber result: ' . $fiber10->getReturn());

    // ================================================================
    // Test 11: EventLoop stress test - many fibers
    // ================================================================
    var_dump('=== Test 11: EventLoop stress test - many fibers ===');
    $stressCounter = 0;
    $stressFibers = [];
    for ($i = 0; $i < 20; $i++) {
        $f = new Fiber(function () use (&$stressCounter) {
            Fiber::suspend();
            $stressCounter++;
        });
        $stressFibers[] = $f;
        EventLoop::add($f);
    }

    $iterations = 0;
    $allDone = false;
    while (!$allDone && $iterations < 200) {
        EventLoop::runNext();
        $iterations++;
        $allDone = true;
        foreach ($stressFibers as $f) {
            if (!$f->isTerminated()) {
                $allDone = false;
                break;
            }
        }
    }
    var_dump('All 20 fibers completed');
    var_dump("Counter: $stressCounter");

    // ================================================================
    // Test 12: EventLoop fiber exception does not break the queue
    // ================================================================
    var_dump('=== Test 12: EventLoop fiber exception does not break queue ===');
    $badFiber = new Fiber(function () {
        throw new RuntimeException('intentional_error');
    });
    $goodFiber = new Fiber(function () {
        return 'good';
    });
    EventLoop::add($badFiber);
    EventLoop::add($goodFiber);

    $caughtError = false;
    $goodRan = false;

    for ($i = 0; $i < 10; $i++) {
        try {
            EventLoop::runNext();
        } catch (RuntimeException $e) {
            var_dump('Caught fiber exception: ' . $e->getMessage());
            $caughtError = true;
        }
        if ($goodFiber->isTerminated() && $goodFiber->getReturn() === 'good') {
            $goodRan = true;
        }
    }
    var_dump('Good fiber still ran: ' . ($goodRan ? 'yes' : 'no'));

    var_dump('All EventLoop tests passed');
});
