<?php

/**
 * Test: Job lifecycle - states, cancellation, timeout, invokeOnCompletion, onJoin
 *
 * Demonstrates the Job/Launch lifecycle management API:
 *
 *   - Job states: PENDING -> RUNNING -> COMPLETED / FAILED / CANCELLED
 *   - Launch::new() returns a Launch (extends Job) with lifecycle methods
 *   - job->getStatus() to inspect current state
 *   - job->isRunning() / job->isCompleted() / job->isCancelled() / job->isFailed()
 *   - job->isFinal() returns true for COMPLETED, FAILED, CANCELLED
 *   - job->cancel() to cancel a pending/running job
 *   - job->cancelAfter(seconds) to set a timeout that auto-cancels the job
 *   - job->invokeOnCompletion(callback) to register a callback on any final state
 *   - job->onJoin(callback) to register a callback for join (completed/failed)
 *   - JobState enum values and isFinal() helper
 *   - Error handling: cancel after complete throws, join before complete throws, etc.
 *
 * Expected output:
 *   "=== Test 1: JobState enum values ==="
 *   "PENDING value: pending"
 *   "RUNNING value: running"
 *   "COMPLETED value: completed"
 *   "FAILED value: failed"
 *   "CANCELLED value: cancelled"
 *   "PENDING isFinal: no"
 *   "RUNNING isFinal: no"
 *   "COMPLETED isFinal: yes"
 *   "FAILED isFinal: yes"
 *   "CANCELLED isFinal: yes"
 *   "=== Test 2: Launch creates a job in correct initial state ==="
 *   "Job created, status: pending"
 *   "Is running before execution: no"
 *   "Is completed before execution: no"
 *   "Is final before execution: no"
 *   "=== Test 3: Job completes after Thread::wait ==="
 *   "Task ran"
 *   "Is completed: yes"
 *   "Is final: yes"
 *   "Status: completed"
 *   "End time is set: yes"
 *   "=== Test 4: Job cancellation ==="
 *   "Status before cancel: pending"
 *   "Is cancelled: yes"
 *   "Is final: yes"
 *   "Status: cancelled"
 *   "=== Test 5: Cancel after complete throws ==="
 *   "Caught: Job cannot be cancelled after it has completed or failed."
 *   "=== Test 6: Cancel after failed throws ==="
 *   "Caught cancel-after-fail: Job cannot be cancelled after it has completed or failed."
 *   "=== Test 7: invokeOnCompletion callback fires on cancel ==="
 *   "invokeOnCompletion fired, state: cancelled"
 *   "=== Test 8: invokeOnCompletion callback fires on complete ==="
 *   "Completion task executed"
 *   "invokeOnCompletion fired, state: completed"
 *   "=== Test 9: invokeOnCompletion on already final job throws ==="
 *   "Caught: Cannot add invoke callback to a job that has already completed."
 *   "=== Test 10: onJoin callback fires on complete ==="
 *   "Join task executed"
 *   "onJoin fired, state: completed"
 *   "=== Test 11: onJoin on already final job throws ==="
 *   "Caught: Cannot add join callback to a job that has already completed."
 *   "=== Test 12: cancelAfter sets timeout ==="
 *   "isTimedOut before time passes: no"
 *   "=== Test 13: cancelAfter on already final job throws ==="
 *   "Caught: Cannot set timeout for a job that has already completed."
 *   "=== Test 14: getStartTime returns a valid time ==="
 *   "Start time is positive: yes"
 *   "=== Test 15: getEndTime is null before completion ==="
 *   "End time before completion: NULL"
 *   "=== Test 16: Multiple invokeOnCompletion callbacks ==="
 *   "Callback 1 fired"
 *   "Callback 2 fired"
 *   "Callback 3 fired"
 *   "=== Test 17: Multiple onJoin callbacks ==="
 *   "Join callback A fired"
 *   "Join callback B fired"
 *   "=== Test 18: Job with Delay completes normally ==="
 *   "Delayed task done"
 *   "Delayed job completed: yes"
 *   "All Job lifecycle tests passed"
 */

require '../vendor/autoload.php';

use vosaka\foroutines\Delay;
use vosaka\foroutines\Job;
use vosaka\foroutines\JobState;
use vosaka\foroutines\Launch;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Thread;

use function vosaka\foroutines\main;

main(function () {
    // ==========================================================
    // Test 1: JobState enum values and isFinal()
    // ==========================================================
    var_dump('=== Test 1: JobState enum values ===');
    var_dump('PENDING value: ' . JobState::PENDING->value);
    var_dump('RUNNING value: ' . JobState::RUNNING->value);
    var_dump('COMPLETED value: ' . JobState::COMPLETED->value);
    var_dump('FAILED value: ' . JobState::FAILED->value);
    var_dump('CANCELLED value: ' . JobState::CANCELLED->value);
    var_dump('PENDING isFinal: ' . (JobState::PENDING->isFinal() ? 'yes' : 'no'));
    var_dump('RUNNING isFinal: ' . (JobState::RUNNING->isFinal() ? 'yes' : 'no'));
    var_dump('COMPLETED isFinal: ' . (JobState::COMPLETED->isFinal() ? 'yes' : 'no'));
    var_dump('FAILED isFinal: ' . (JobState::FAILED->isFinal() ? 'yes' : 'no'));
    var_dump('CANCELLED isFinal: ' . (JobState::CANCELLED->isFinal() ? 'yes' : 'no'));

    // ==========================================================
    // Test 2: Launch creates a job in correct initial state
    // ==========================================================
    var_dump('=== Test 2: Launch creates a job in correct initial state ===');
    RunBlocking::new(function () {
        $job = Launch::new(function () {
            Delay::new(100);
        });
        // Right after Launch::new, the job should be pending (not yet started by scheduler)
        var_dump('Job created, status: ' . $job->getStatus()->value);
        var_dump('Is running before execution: ' . ($job->isRunning() ? 'yes' : 'no'));
        var_dump('Is completed before execution: ' . ($job->isCompleted() ? 'yes' : 'no'));
        var_dump('Is final before execution: ' . ($job->isFinal() ? 'yes' : 'no'));

        Thread::wait();
    });
    Thread::wait();

    // ==========================================================
    // Test 3: Job completes after Thread::wait
    // ==========================================================
    var_dump('=== Test 3: Job completes after Thread::wait ===');
    RunBlocking::new(function () {
        $job = Launch::new(function () {
            var_dump('Task ran');
        });

        Thread::wait();

        var_dump('Is completed: ' . ($job->isCompleted() ? 'yes' : 'no'));
        var_dump('Is final: ' . ($job->isFinal() ? 'yes' : 'no'));
        var_dump('Status: ' . $job->getStatus()->value);
        var_dump('End time is set: ' . ($job->getEndTime() !== null ? 'yes' : 'no'));
    });
    Thread::wait();

    // ==========================================================
    // Test 4: Job cancellation
    // ==========================================================
    var_dump('=== Test 4: Job cancellation ===');
    RunBlocking::new(function () {
        $job = Launch::new(function () {
            Delay::new(10000); // long delay - should be cancelled before it completes
            var_dump('This should NOT print');
        });

        var_dump('Status before cancel: ' . $job->getStatus()->value);
        $job->cancel();
        var_dump('Is cancelled: ' . ($job->isCancelled() ? 'yes' : 'no'));
        var_dump('Is final: ' . ($job->isFinal() ? 'yes' : 'no'));
        var_dump('Status: ' . $job->getStatus()->value);

        Thread::wait();
    });
    Thread::wait();

    // ==========================================================
    // Test 5: Cancel after complete throws RuntimeException
    // ==========================================================
    var_dump('=== Test 5: Cancel after complete throws ===');
    RunBlocking::new(function () {
        $job = Launch::new(function () {
            // Quick task
        });

        Thread::wait();

        try {
            $job->cancel();
            var_dump('ERROR: cancel after complete did not throw');
        } catch (RuntimeException $e) {
            var_dump('Caught: ' . $e->getMessage());
        }
    });
    Thread::wait();

    // ==========================================================
    // Test 6: Cancel after failed throws
    // ==========================================================
    var_dump('=== Test 6: Cancel after failed throws ===');
    RunBlocking::new(function () {
        $job = Launch::new(function () {
            throw new RuntimeException('intentional failure');
        });

        try {
            Thread::wait();
        } catch (RuntimeException $e) {
            // Expected - the job threw
        }

        try {
            $job->cancel();
            var_dump('ERROR: cancel after fail did not throw');
        } catch (RuntimeException $e) {
            var_dump('Caught cancel-after-fail: ' . $e->getMessage());
        }
    });
    Thread::wait();

    // ==========================================================
    // Test 7: invokeOnCompletion fires on cancel
    // ==========================================================
    var_dump('=== Test 7: invokeOnCompletion callback fires on cancel ===');
    RunBlocking::new(function () {
        $job = Launch::new(function () {
            Delay::new(10000);
        });

        $job->invokeOnCompletion(function (Job $j) {
            var_dump('invokeOnCompletion fired, state: ' . $j->getStatus()->value);
        });

        $job->cancel();

        Thread::wait();
    });
    Thread::wait();

    // ==========================================================
    // Test 8: invokeOnCompletion fires on complete
    // ==========================================================
    var_dump('=== Test 8: invokeOnCompletion callback fires on complete ===');
    RunBlocking::new(function () {
        $job = Launch::new(function () {
            var_dump('Completion task executed');
        });

        $job->invokeOnCompletion(function (Job $j) {
            var_dump('invokeOnCompletion fired, state: ' . $j->getStatus()->value);
        });

        Thread::wait();
    });
    Thread::wait();

    // ==========================================================
    // Test 9: invokeOnCompletion on already final job throws
    // ==========================================================
    var_dump('=== Test 9: invokeOnCompletion on already final job throws ===');
    RunBlocking::new(function () {
        $job = Launch::new(function () {
            // Quick task
        });

        Thread::wait();

        try {
            $job->invokeOnCompletion(function (Job $j) {
                var_dump('This should not run');
            });
            var_dump('ERROR: invokeOnCompletion on final job did not throw');
        } catch (RuntimeException $e) {
            var_dump('Caught: ' . $e->getMessage());
        }
    });
    Thread::wait();

    // ==========================================================
    // Test 10: onJoin callback fires on complete
    // ==========================================================
    var_dump('=== Test 10: onJoin callback fires on complete ===');
    RunBlocking::new(function () {
        $job = Launch::new(function () {
            var_dump('Join task executed');
        });

        $job->onJoin(function (Job $j) {
            var_dump('onJoin fired, state: ' . $j->getStatus()->value);
        });

        Thread::wait();
    });
    Thread::wait();

    // ==========================================================
    // Test 11: onJoin on already final job throws
    // ==========================================================
    var_dump('=== Test 11: onJoin on already final job throws ===');
    RunBlocking::new(function () {
        $job = Launch::new(function () {
            // Quick task
        });

        Thread::wait();

        try {
            $job->onJoin(function (Job $j) {
                var_dump('This should not run');
            });
            var_dump('ERROR: onJoin on final job did not throw');
        } catch (RuntimeException $e) {
            var_dump('Caught: ' . $e->getMessage());
        }
    });
    Thread::wait();

    // ==========================================================
    // Test 12: cancelAfter sets timeout (isTimedOut)
    // ==========================================================
    var_dump('=== Test 12: cancelAfter sets timeout ===');
    RunBlocking::new(function () {
        $job = Launch::new(function () {
            Delay::new(5000);
        });

        $job->cancelAfter(10.0); // 10 second timeout - won't trigger yet
        var_dump('isTimedOut before time passes: ' . ($job->isTimedOut() ? 'yes' : 'no'));

        $job->cancel(); // cancel manually so we don't wait
        Thread::wait();
    });
    Thread::wait();

    // ==========================================================
    // Test 13: cancelAfter on already final job throws
    // ==========================================================
    var_dump('=== Test 13: cancelAfter on already final job throws ===');
    RunBlocking::new(function () {
        $job = Launch::new(function () {
            // Quick task
        });

        Thread::wait();

        try {
            $job->cancelAfter(5.0);
            var_dump('ERROR: cancelAfter on final job did not throw');
        } catch (RuntimeException $e) {
            var_dump('Caught: ' . $e->getMessage());
        }
    });
    Thread::wait();

    // ==========================================================
    // Test 14: getStartTime returns a valid time
    // ==========================================================
    var_dump('=== Test 14: getStartTime returns a valid time ===');
    RunBlocking::new(function () {
        $job = Launch::new(function () {
            // Quick task
        });

        $startTime = $job->getStartTime();
        var_dump('Start time is positive: ' . ($startTime > 0 ? 'yes' : 'no'));

        Thread::wait();
    });
    Thread::wait();

    // ==========================================================
    // Test 15: getEndTime is null before completion
    // ==========================================================
    var_dump('=== Test 15: getEndTime is null before completion ===');
    RunBlocking::new(function () {
        $job = Launch::new(function () {
            Delay::new(100);
        });

        $endTime = $job->getEndTime();
        var_dump('End time before completion: ' . ($endTime === null ? 'NULL' : $endTime));

        Thread::wait();
    });
    Thread::wait();

    // ==========================================================
    // Test 16: Multiple invokeOnCompletion callbacks
    // ==========================================================
    var_dump('=== Test 16: Multiple invokeOnCompletion callbacks ===');
    RunBlocking::new(function () {
        $job = Launch::new(function () {
            // Quick task
        });

        $job->invokeOnCompletion(function (Job $j) {
            var_dump('Callback 1 fired');
        });
        $job->invokeOnCompletion(function (Job $j) {
            var_dump('Callback 2 fired');
        });
        $job->invokeOnCompletion(function (Job $j) {
            var_dump('Callback 3 fired');
        });

        Thread::wait();
    });
    Thread::wait();

    // ==========================================================
    // Test 17: Multiple onJoin callbacks
    // ==========================================================
    var_dump('=== Test 17: Multiple onJoin callbacks ===');
    RunBlocking::new(function () {
        $job = Launch::new(function () {
            // Quick task
        });

        $job->onJoin(function (Job $j) {
            var_dump('Join callback A fired');
        });
        $job->onJoin(function (Job $j) {
            var_dump('Join callback B fired');
        });

        Thread::wait();
    });
    Thread::wait();

    // ==========================================================
    // Test 18: Job with Delay completes normally
    // ==========================================================
    var_dump('=== Test 18: Job with Delay completes normally ===');
    RunBlocking::new(function () {
        $job = Launch::new(function () {
            Delay::new(200);
            var_dump('Delayed task done');
        });

        Thread::wait();

        var_dump('Delayed job completed: ' . ($job->isCompleted() ? 'yes' : 'no'));
    });
    Thread::wait();

    var_dump('All Job lifecycle tests passed');
});
