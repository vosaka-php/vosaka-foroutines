<?php

/**
 * Test: SharedFlow and StateFlow (hot flows)
 *
 * Demonstrates the hot flow APIs which share emissions among collectors:
 *
 * SharedFlow:
 *   - SharedFlow::new() creates a hot flow that can have multiple collectors
 *   - emit() sends values to all active collectors
 *   - Supports replay buffer to cache recent emissions for late collectors
 *   - complete() stops the flow
 *   - getCollectorCount() tracks active collectors
 *
 * StateFlow:
 *   - StateFlow::new() creates a flow that always holds a current value
 *   - getValue() / setValue() to read/write the current state
 *   - update() to atomically modify the value via a callback
 *   - Collectors immediately receive the current value on subscription
 *   - Only emits to collectors when the value actually changes
 *   - Supports operators like map, filter, etc.
 *
 * Expected output:
 *   "=== Test 1: SharedFlow basic emit and collect ==="
 *   "Collector A received: 10"
 *   "Collector B received: 10"
 *   "Collector A received: 20"
 *   "Collector B received: 20"
 *   "Collector count: 2"
 *   "=== Test 2: SharedFlow with replay ==="
 *   (replayed values from late collector)
 *   "=== Test 3: SharedFlow complete stops emissions ==="
 *   "Before complete: alpha"
 *   "After complete, nothing emitted"
 *   "=== Test 4: SharedFlow collector count tracking ==="
 *   "Collectors: 0"
 *   "Collectors after add: 2"
 *   "=== Test 5: StateFlow initial value ==="
 *   "Initial value: 0"
 *   "=== Test 6: StateFlow setValue and getValue ==="
 *   "After setValue(42): 42"
 *   "After setValue(100): 100"
 *   "=== Test 7: StateFlow collect receives current value ==="
 *   "Collector got current value: hello"
 *   "=== Test 8: StateFlow only emits on change ==="
 *   "State changed to: first"
 *   "State changed to: second"
 *   "Change count: 2"
 *   "=== Test 9: StateFlow update() ==="
 *   "After increment: 6"
 *   "After double: 12"
 *   "=== Test 10: StateFlow with map operator ==="
 *   "Mapped state: [VALUE=start]"
 *   "=== Test 11: StateFlow collector count ==="
 *   "Collectors before: 0"
 *   "Collectors after adding two: 2"
 *   "Has collectors: yes"
 *   "=== Test 12: SharedFlow replay=0 gives nothing to late collector ==="
 *   "Late collector received nothing (count=0)"
 *   "=== Test 13: SharedFlow with multiple emits ==="
 *   "Emit log count: 5"
 *   "=== Test 14: StateFlow distinctUntilChanged ==="
 *   "Distinct: A"
 *   "=== Test 15: StateFlow with various data types ==="
 *   "Int state: 42"
 *   "String state: hello"
 *   "Array state count: 3"
 *   "Bool state: true"
 *   "=== Test 16: SharedFlow replay buffer size limit ==="
 *   "Replay buffer respects size: yes"
 *   "=== Test 17: StateFlow update chaining ==="
 *   "Chained update result: 25"
 *   "=== Test 18: SharedFlow multiple collectors independence ==="
 *   "Collector X count: 3"
 *   "Collector Y count: 3"
 *   "All SharedFlow and StateFlow tests passed"
 */

require "../vendor/autoload.php";

use vosaka\foroutines\flow\SharedFlow;
use vosaka\foroutines\flow\StateFlow;

use function vosaka\foroutines\main;

main(function () {
    // ==========================================================
    // SharedFlow Tests
    // ==========================================================

    // Test 1: Basic emit and collect with multiple collectors
    var_dump("=== Test 1: SharedFlow basic emit and collect ===");
    $shared = SharedFlow::new();
    $shared->collect(function ($value) {
        var_dump("Collector A received: $value");
    });
    $shared->collect(function ($value) {
        var_dump("Collector B received: $value");
    });
    $shared->emit(10);
    $shared->emit(20);
    var_dump("Collector count: " . $shared->getCollectorCount());

    // Test 2: SharedFlow with replay - late collectors get buffered values
    var_dump("=== Test 2: SharedFlow with replay ===");
    $replayed = SharedFlow::new(3); // replay last 3 values
    $replayed->emit(77);
    $replayed->emit(88);
    $replayed->emit(99);
    // Late collector should receive replayed values
    $replayedValues = [];
    $replayed->collect(function ($value) use (&$replayedValues) {
        $replayedValues[] = $value;
    });
    var_dump("Replayed count: " . count($replayedValues));
    foreach ($replayedValues as $v) {
        var_dump("Replayed: $v");
    }

    // Test 3: SharedFlow complete stops emissions
    var_dump("=== Test 3: SharedFlow complete stops emissions ===");
    $completable = SharedFlow::new();
    $received = [];
    $completable->collect(function ($value) use (&$received) {
        $received[] = $value;
    });
    $completable->emit("alpha");
    var_dump("Before complete: " . $received[0]);
    $completable->complete();
    $completable->emit("beta"); // Should NOT be received
    if (count($received) === 1) {
        var_dump("After complete, nothing emitted");
    }

    // Test 4: SharedFlow collector count tracking
    var_dump("=== Test 4: SharedFlow collector count tracking ===");
    $tracked = SharedFlow::new();
    var_dump("Collectors: " . $tracked->getCollectorCount());
    $tracked->collect(function ($v) {});
    $tracked->collect(function ($v) {});
    var_dump("Collectors after add: " . $tracked->getCollectorCount());

    // ==========================================================
    // StateFlow Tests
    // ==========================================================

    // Test 5: StateFlow initial value
    var_dump("=== Test 5: StateFlow initial value ===");
    $state = StateFlow::new(0);
    var_dump("Initial value: " . $state->getValue());

    // Test 6: StateFlow setValue and getValue
    var_dump("=== Test 6: StateFlow setValue and getValue ===");
    $state->setValue(42);
    var_dump("After setValue(42): " . $state->getValue());
    $state->setValue(100);
    var_dump("After setValue(100): " . $state->getValue());

    // Test 7: StateFlow collect immediately receives current value
    var_dump("=== Test 7: StateFlow collect receives current value ===");
    $greetState = StateFlow::new("hello");
    $greetState->collect(function ($value) {
        var_dump("Collector got current value: $value");
    });

    // Test 8: StateFlow only emits to collectors when value actually changes
    var_dump("=== Test 8: StateFlow only emits on change ===");
    $changeState = StateFlow::new("initial");
    $changeCount = 0;
    $changeState->collect(function ($value) use (&$changeCount) {
        // Skip the initial emission from collect
        if ($value !== "initial") {
            $changeCount++;
            var_dump("State changed to: $value");
        }
    });
    $changeState->setValue("first"); // changed -> emits
    $changeState->setValue("first"); // same value -> should NOT emit
    $changeState->setValue("second"); // changed -> emits
    $changeState->setValue("second"); // same value -> should NOT emit
    var_dump("Change count: $changeCount");

    // Test 9: StateFlow update() with a transformation callback
    var_dump("=== Test 9: StateFlow update() ===");
    $counter = StateFlow::new(5);
    $counter->update(fn($current) => $current + 1);
    var_dump("After increment: " . $counter->getValue());
    $counter->update(fn($current) => $current * 2);
    var_dump("After double: " . $counter->getValue());

    // Test 10: StateFlow with map operator
    var_dump("=== Test 10: StateFlow with map operator ===");
    $stateWithMap = StateFlow::new("start");
    $stateWithMap->map(fn($v) => "[VALUE=$v]")->collect(function ($value) {
        var_dump("Mapped state: $value");
    });

    // Test 11: StateFlow collector count tracking
    var_dump("=== Test 11: StateFlow collector count ===");
    $trackedState = StateFlow::new("tracking");
    var_dump("Collectors before: " . $trackedState->getCollectorCount());
    $trackedState->collect(function ($v) {});
    $trackedState->collect(function ($v) {});
    var_dump(
        "Collectors after adding two: " . $trackedState->getCollectorCount(),
    );
    var_dump(
        "Has collectors: " . ($trackedState->hasCollectors() ? "yes" : "no"),
    );

    // Test 12: SharedFlow with replay=0 gives nothing to late collectors
    var_dump(
        "=== Test 12: SharedFlow replay=0 gives nothing to late collector ===",
    );
    $noReplay = SharedFlow::new(0);
    $noReplay->emit("early1");
    $noReplay->emit("early2");
    $lateReceived = [];
    $noReplay->collect(function ($value) use (&$lateReceived) {
        $lateReceived[] = $value;
    });
    var_dump(
        "Late collector received nothing (count=" . count($lateReceived) . ")",
    );

    // Test 13: SharedFlow with multiple sequential emits
    var_dump("=== Test 13: SharedFlow with multiple emits ===");
    $multiEmit = SharedFlow::new();
    $emitLog = [];
    $multiEmit->collect(function ($value) use (&$emitLog) {
        $emitLog[] = $value;
    });
    $multiEmit->emit("a");
    $multiEmit->emit("b");
    $multiEmit->emit("c");
    $multiEmit->emit("d");
    $multiEmit->emit("e");
    var_dump("Emit log count: " . count($emitLog));

    // Test 14: StateFlow distinctUntilChanged
    var_dump("=== Test 14: StateFlow distinctUntilChanged ===");
    $distinctState = StateFlow::new("A");
    $distinctState->distinctUntilChanged()->collect(function ($value) {
        var_dump("Distinct: $value");
    });

    // Test 15: StateFlow with various data types
    var_dump("=== Test 15: StateFlow with various data types ===");
    $intState = StateFlow::new(42);
    var_dump("Int state: " . $intState->getValue());

    $strState = StateFlow::new("hello");
    var_dump("String state: " . $strState->getValue());

    $arrState = StateFlow::new([1, 2, 3]);
    var_dump("Array state count: " . count($arrState->getValue()));

    $boolState = StateFlow::new(true);
    var_dump("Bool state: " . ($boolState->getValue() ? "true" : "false"));

    // Test 16: SharedFlow replay buffer size limit
    var_dump("=== Test 16: SharedFlow replay buffer size limit ===");
    $limitedReplay = SharedFlow::new(2); // only keep last 2
    $limitedReplay->emit("old");
    $limitedReplay->emit("recent1");
    $limitedReplay->emit("recent2");
    $replayBuf = [];
    $limitedReplay->collect(function ($value) use (&$replayBuf) {
        $replayBuf[] = $value;
    });
    // Should only have 'recent1' and 'recent2' (not 'old')
    $hasOld = in_array("old", $replayBuf);
    var_dump("Replay buffer respects size: " . (!$hasOld ? "yes" : "no"));

    // Test 17: StateFlow update chaining
    var_dump("=== Test 17: StateFlow update chaining ===");
    $chainState = StateFlow::new(1);
    $chainState->update(fn($v) => $v + 4); // 5
    $chainState->update(fn($v) => $v * 5); // 25
    var_dump("Chained update result: " . $chainState->getValue());

    // Test 18: SharedFlow multiple collectors are independent
    var_dump("=== Test 18: SharedFlow multiple collectors independence ===");
    $indepFlow = SharedFlow::new();
    $xLog = [];
    $yLog = [];
    $indepFlow->collect(function ($value) use (&$xLog) {
        $xLog[] = $value;
    });
    $indepFlow->collect(function ($value) use (&$yLog) {
        $yLog[] = $value;
    });
    $indepFlow->emit("p");
    $indepFlow->emit("q");
    $indepFlow->emit("r");
    var_dump("Collector X count: " . count($xLog));
    var_dump("Collector Y count: " . count($yLog));

    var_dump("All SharedFlow and StateFlow tests passed");
});
