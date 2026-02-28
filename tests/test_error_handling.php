<?php

/**
 * Test: Error propagation and exception handling in async contexts
 *
 * Demonstrates how exceptions and errors behave across the various
 * concurrency primitives in the library:
 *
 *   - Exceptions thrown inside WithTimeout::new() are wrapped in RuntimeException
 *   - WithTimeoutOrNull::new() returns null on timeout (no exception)
 *   - Repeat::new() propagates exceptions from the callable
 *   - Job state violations throw RuntimeException
 *   - SharedFlow collector exceptions are handled gracefully
 *   - Channel send-after-close throws
 *   - Error types: RuntimeException, InvalidArgumentException, LogicException, custom
 *   - Exception message and code are preserved
 *   - Side effects are preserved even when exceptions occur
 *   - Exception chain (previous) is preserved
 *
 * Expected output:
 *   "=== Test 1: Exception inside WithTimeout wraps correctly ==="
 *   "Caught from WithTimeout: inner_timeout_error"
 *   "=== Test 2: WithTimeout exceeds timeout ==="
 *   (timeout message)
 *   "=== Test 3: WithTimeoutOrNull returns null on timeout ==="
 *   "Timeout result is null: yes"
 *   "=== Test 4: WithTimeoutOrNull returns value when in time ==="
 *   "Result: fast_result"
 *   "=== Test 5: WithTimeout zero timeout ==="
 *   (immediate timeout message)
 *   "=== Test 6: WithTimeout negative timeout ==="
 *   (immediate timeout message)
 *   "=== Test 7: Repeat propagates exception from callable ==="
 *   "Repeat ran 3 times before error"
 *   "Caught from Repeat: repeat_error_on_3"
 *   "=== Test 8: InvalidArgumentException in Repeat (count=0) ==="
 *   "Caught: Count must be greater than zero."
 *   "=== Test 9: InvalidArgumentException in Repeat (negative) ==="
 *   "Caught: Count must be greater than zero."
 *   "=== Test 10: RuntimeException from Job state violations ==="
 *   "Cannot complete pending: Job must be running to complete it."
 *   "Cannot fail pending: Job must be running to fail it."
 *   "=== Test 11: SharedFlow bad collector removed ==="
 *   "Other collector still works: yes"
 *   "=== Test 12: SharedFlow complete stops emissions ==="
 *   "Received count: 1"
 *   "Only before-complete received: yes"
 *   "=== Test 13: Channel send after close throws ==="
 *   "Caught channel error: Channel is closed"
 *   "=== Test 14: Channel trySend on closed ==="
 *   "trySend on closed: failed"
 *   "=== Test 15: Channels::createBuffered invalid capacity ==="
 *   "Caught zero: ..."
 *   "Caught negative: ..."
 *   "=== Test 16: Multiple exception types in sequence ==="
 *   "Caught types: [RuntimeException, InvalidArgumentException, LogicException, CustomTestException]"
 *   "=== Test 17: Repeat exception preserves side effects ==="
 *   "Side effects count: 3"
 *   "Side effects: [1, 2, 3]"
 *   "Caught: stop_at_3"
 *   "=== Test 18: Exception preserves message and code ==="
 *   "Message: custom_message"
 *   "Code: 42"
 *   "=== Test 19: Exception with previous chain ==="
 *   "Wrapper: wrapper_error"
 *   "Previous: original_cause"
 *   "=== Test 20: Custom exception class preserves type ==="
 *   "Caught custom: custom_msg"
 *   "Is correct type: yes"
 *   "Custom code: 99"
 *   "All error handling tests passed"
 */

require "../vendor/autoload.php";

use vosaka\foroutines\Delay;
use vosaka\foroutines\Job;
use vosaka\foroutines\Repeat;
use vosaka\foroutines\WithTimeout;
use vosaka\foroutines\WithTimeoutOrNull;
use vosaka\foroutines\channel\Channels;
use vosaka\foroutines\flow\SharedFlow;

use function vosaka\foroutines\main;

/**
 * Custom exception class for testing exception type preservation.
 */
class CustomTestException extends RuntimeException
{
    public function __construct(string $message, int $code = 0)
    {
        parent::__construct($message, $code);
    }
}

main(function () {
    // ================================================================
    // Test 1: Exception inside WithTimeout wraps correctly
    // ================================================================
    var_dump("=== Test 1: Exception inside WithTimeout wraps correctly ===");
    try {
        WithTimeout::new(5000, function () {
            throw new RuntimeException("inner_timeout_error");
        });
    } catch (RuntimeException $e) {
        var_dump("Caught from WithTimeout: " . $e->getMessage());
    }

    // ================================================================
    // Test 2: WithTimeout exceeds timeout
    // ================================================================
    var_dump("=== Test 2: WithTimeout exceeds timeout ===");
    try {
        WithTimeout::new(200, function () {
            Delay::new(5000);
            return "should not reach";
        });
    } catch (RuntimeException $e) {
        var_dump("Caught timeout: " . $e->getMessage());
    }

    // ================================================================
    // Test 3: WithTimeoutOrNull returns null on timeout
    // ================================================================
    var_dump("=== Test 3: WithTimeoutOrNull returns null on timeout ===");
    $result = WithTimeoutOrNull::new(100, function () {
        Delay::new(10000);
        return "should_not_reach";
    });
    var_dump("Timeout result is null: " . ($result === null ? "yes" : "no"));

    // ================================================================
    // Test 4: WithTimeoutOrNull returns value when in time
    // ================================================================
    var_dump("=== Test 4: WithTimeoutOrNull returns value when in time ===");
    $result = WithTimeoutOrNull::new(3000, function () {
        return "fast_result";
    });
    var_dump("Result: $result");

    // ================================================================
    // Test 5: WithTimeout zero timeout throws immediately
    // ================================================================
    var_dump("=== Test 5: WithTimeout zero timeout ===");
    try {
        WithTimeout::new(0, function () {
            return "should not run";
        });
    } catch (RuntimeException $e) {
        var_dump("Caught immediate timeout: " . $e->getMessage());
    }

    // ================================================================
    // Test 6: WithTimeout negative timeout throws immediately
    // ================================================================
    var_dump("=== Test 6: WithTimeout negative timeout ===");
    try {
        WithTimeout::new(-100, function () {
            return "should not run";
        });
    } catch (RuntimeException $e) {
        var_dump("Caught negative timeout: " . $e->getMessage());
    }

    // ================================================================
    // Test 7: Repeat propagates exception from callable
    // ================================================================
    var_dump("=== Test 7: Repeat propagates exception from callable ===");
    $repeatCount = 0;
    try {
        Repeat::new(5, function () use (&$repeatCount) {
            $repeatCount++;
            if ($repeatCount === 3) {
                throw new RuntimeException("repeat_error_on_3");
            }
        });
    } catch (RuntimeException $e) {
        var_dump("Repeat ran $repeatCount times before error");
        var_dump("Caught from Repeat: " . $e->getMessage());
    }

    // ================================================================
    // Test 8: InvalidArgumentException in Repeat (count=0)
    // ================================================================
    var_dump("=== Test 8: InvalidArgumentException in Repeat (count=0) ===");
    try {
        Repeat::new(0, function () {
            var_dump("Should not execute");
        });
    } catch (InvalidArgumentException $e) {
        var_dump("Caught: " . $e->getMessage());
    }

    // ================================================================
    // Test 9: InvalidArgumentException in Repeat (negative count)
    // ================================================================
    var_dump("=== Test 9: InvalidArgumentException in Repeat (negative) ===");
    try {
        Repeat::new(-5, function () {
            var_dump("Should not execute");
        });
    } catch (InvalidArgumentException $e) {
        var_dump("Caught: " . $e->getMessage());
    }

    // ================================================================
    // Test 10: RuntimeException from Job state violations
    // ================================================================
    var_dump("=== Test 10: RuntimeException from Job state violations ===");

    // Cannot complete a job that is not RUNNING
    $job1 = new Job(100);
    try {
        $job1->complete();
    } catch (RuntimeException $e) {
        var_dump("Cannot complete pending: " . $e->getMessage());
    }

    // Cannot fail a job that is not RUNNING
    $job2 = new Job(200);
    try {
        $job2->fail();
    } catch (RuntimeException $e) {
        var_dump("Cannot fail pending: " . $e->getMessage());
    }

    // ================================================================
    // Test 11: SharedFlow bad collector removed, good collector survives
    // ================================================================
    var_dump("=== Test 11: SharedFlow bad collector removed ===");
    $sf = SharedFlow::new();
    $goodCollectorValues = [];
    // Bad collector throws on first value
    $sf->collect(function ($value) {
        throw new RuntimeException("bad_collector");
    });
    // Good collector works fine
    $sf->collect(function ($value) use (&$goodCollectorValues) {
        $goodCollectorValues[] = $value;
    });
    $sf->emit("test1");
    $sf->emit("test2");
    $otherWorks = count($goodCollectorValues) === 2;
    var_dump("Other collector still works: " . ($otherWorks ? "yes" : "no"));

    // ================================================================
    // Test 12: SharedFlow complete stops further emissions
    // ================================================================
    var_dump("=== Test 12: SharedFlow complete stops emissions ===");
    $completedFlow = SharedFlow::new();
    $receivedAfterComplete = [];
    $completedFlow->collect(function ($v) use (&$receivedAfterComplete) {
        $receivedAfterComplete[] = $v;
    });
    $completedFlow->emit("before");
    $completedFlow->complete();
    $completedFlow->emit("after");
    var_dump("Received count: " . count($receivedAfterComplete));
    var_dump(
        "Only before-complete received: " .
            ($receivedAfterComplete === ["before"] ? "yes" : "no"),
    );

    // ================================================================
    // Test 13: Channel send after close throws
    // ================================================================
    var_dump("=== Test 13: Channel send after close throws ===");
    $ch = Channels::createBuffered(5);
    $ch->close();
    try {
        $ch->send("should fail");
        var_dump("ERROR: send after close did not throw");
    } catch (Exception $e) {
        var_dump("Caught channel error: " . $e->getMessage());
    }

    // ================================================================
    // Test 14: Channel trySend on closed returns false
    // ================================================================
    var_dump("=== Test 14: Channel trySend on closed ===");
    $ch2 = Channels::createBuffered(5);
    $ch2->close();
    $result = $ch2->trySend("nope");
    var_dump("trySend on closed: " . ($result ? "success" : "failed"));

    // ================================================================
    // Test 15: Channels::createBuffered invalid capacity
    // ================================================================
    var_dump("=== Test 15: Channels::createBuffered invalid capacity ===");
    try {
        Channels::createBuffered(0);
    } catch (Exception $e) {
        var_dump("Caught zero: " . $e->getMessage());
    }
    try {
        Channels::createBuffered(-5);
    } catch (Exception $e) {
        var_dump("Caught negative: " . $e->getMessage());
    }

    // ================================================================
    // Test 16: Multiple exception types in sequence
    // ================================================================
    var_dump("=== Test 16: Multiple exception types in sequence ===");
    $caughtTypes = [];

    try {
        throw new RuntimeException("rt");
    } catch (RuntimeException $e) {
        $caughtTypes[] = "RuntimeException";
    }

    try {
        throw new InvalidArgumentException("ia");
    } catch (InvalidArgumentException $e) {
        $caughtTypes[] = "InvalidArgumentException";
    }

    try {
        throw new LogicException("le");
    } catch (LogicException $e) {
        $caughtTypes[] = "LogicException";
    }

    try {
        throw new CustomTestException("ct");
    } catch (CustomTestException $e) {
        $caughtTypes[] = "CustomTestException";
    }

    var_dump("Caught types: [" . implode(", ", $caughtTypes) . "]");

    // ================================================================
    // Test 17: Repeat exception preserves side effects
    // ================================================================
    var_dump("=== Test 17: Repeat exception preserves side effects ===");
    $sideEffectList = [];
    try {
        Repeat::new(5, function () use (&$sideEffectList) {
            $sideEffectList[] = count($sideEffectList) + 1;
            if (count($sideEffectList) === 3) {
                throw new RuntimeException("stop_at_3");
            }
        });
    } catch (RuntimeException $e) {
        var_dump("Side effects count: " . count($sideEffectList));
        var_dump("Side effects: [" . implode(", ", $sideEffectList) . "]");
        var_dump("Caught: " . $e->getMessage());
    }

    // ================================================================
    // Test 18: Exception preserves message and code
    // ================================================================
    var_dump("=== Test 18: Exception preserves message and code ===");
    try {
        throw new RuntimeException("custom_message", 42);
    } catch (RuntimeException $e) {
        var_dump("Message: " . $e->getMessage());
        var_dump("Code: " . $e->getCode());
    }

    // ================================================================
    // Test 19: Exception with previous chain
    // ================================================================
    var_dump("=== Test 19: Exception with previous chain ===");
    try {
        $original = new RuntimeException("original_cause");
        throw new RuntimeException("wrapper_error", 0, $original);
    } catch (RuntimeException $e) {
        var_dump("Wrapper: " . $e->getMessage());
        $prev = $e->getPrevious();
        var_dump(
            "Previous: " . ($prev !== null ? $prev->getMessage() : "none"),
        );
    }

    // ================================================================
    // Test 20: Custom exception class preserves type and code
    // ================================================================
    var_dump("=== Test 20: Custom exception class preserves type ===");
    try {
        throw new CustomTestException("custom_msg", 99);
    } catch (CustomTestException $e) {
        var_dump("Caught custom: " . $e->getMessage());
        var_dump(
            "Is correct type: " .
                ($e instanceof CustomTestException ? "yes" : "no"),
        );
        var_dump("Custom code: " . $e->getCode());
    }

    var_dump("All error handling tests passed");
});
