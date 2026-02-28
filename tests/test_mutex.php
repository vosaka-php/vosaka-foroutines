<?php

/**
 * Test: Mutex for synchronization (file-based locking)
 *
 * Demonstrates the Mutex API for mutual exclusion / synchronization:
 *
 *   - Mutex::create(name) factory method
 *   - mutex->acquire() / mutex->release() for manual lock management
 *   - mutex->tryLock() for non-blocking lock attempt
 *   - mutex->isLocked() to check lock state
 *   - mutex->synchronized(callback) to run code under lock automatically
 *   - Mutex::protect(name, callback) static shorthand
 *   - mutex->getInfo() for debug information
 *   - Mutex::getPlatformInfo() for platform capabilities
 *   - Error handling: empty name, invalid type, timeout, double release
 *   - Lock types: LOCK_FILE, LOCK_AUTO
 *   - Nested synchronized calls with different mutexes (no deadlock for distinct names)
 *
 * Expected output:
 *   "=== Test 1: Mutex create and basic acquire/release ==="
 *   "Acquired: yes"
 *   "Is locked: yes"
 *   "Released: yes"
 *   "Is locked after release: no"
 *   "=== Test 2: Double acquire returns true (reentrant for same instance) ==="
 *   "First acquire: yes"
 *   "Second acquire: yes"
 *   "=== Test 3: Release when not locked returns true ==="
 *   "Release when not locked: yes"
 *   "=== Test 4: tryLock non-blocking ==="
 *   "tryLock result: yes"
 *   "Is locked after tryLock: yes"
 *   "Released after tryLock: yes"
 *   "=== Test 5: synchronized runs callback under lock ==="
 *   "Inside synchronized, is locked: yes"
 *   "Synchronized returned: 42"
 *   "Is locked after synchronized: no"
 *   "=== Test 6: synchronized releases lock even on exception ==="
 *   "Caught inside synchronized: intentional error"
 *   "Is locked after exception: no"
 *   "=== Test 7: Mutex::protect static shorthand ==="
 *   "Protect returned: hello from protect"
 *   "=== Test 8: Mutex getInfo returns useful data ==="
 *   "Info has name: yes"
 *   "Info has locked: yes"
 *   "Info has actual_type: yes"
 *   "Info has platform: yes"
 *   "Info name matches: yes"
 *   "=== Test 9: Mutex getPlatformInfo ==="
 *   "Platform info has platform: yes"
 *   "Platform info has available_methods: yes"
 *   "Platform info file always available: yes"
 *   "=== Test 10: Empty name throws InvalidArgumentException ==="
 *   "Caught empty name: Mutex name cannot be empty"
 *   "=== Test 11: Invalid lock type throws ==="
 *   "Caught invalid type: Invalid lock type. Use LOCK_FILE, LOCK_SEMAPHORE, LOCK_APCU, or LOCK_AUTO"
 *   "=== Test 12: Mutex with explicit LOCK_FILE type ==="
 *   "File lock acquired: yes"
 *   "File lock released: yes"
 *   "=== Test 13: Mutex with LOCK_AUTO type ==="
 *   "Auto lock acquired: yes"
 *   "Auto lock info actual_type is set: yes"
 *   "=== Test 14: Multiple mutexes with different names ==="
 *   "Mutex A locked: yes"
 *   "Mutex B locked: yes"
 *   "Both independent: yes"
 *   "=== Test 15: synchronized with accumulation ==="
 *   "Accumulated value: 10"
 *   "=== Test 16: synchronized returns complex data ==="
 *   "Returned array count: 3"
 *   "Returned array sum: 60"
 *   "=== Test 17: Nested synchronized with different mutexes ==="
 *   "Outer locked: yes"
 *   "Inner locked: yes"
 *   "Nested result: inner_done"
 *   "=== Test 18: Mutex::protect with exception propagates ==="
 *   "Caught protect exception: protect_error"
 *   "=== Test 19: tryLock after release works ==="
 *   "Acquire: yes"
 *   "Release: yes"
 *   "tryLock after release: yes"
 *   "Release again: yes"
 *   "=== Test 20: Mutex info reflects lock state changes ==="
 *   "Locked in info before: no"
 *   "Locked in info during: yes"
 *   "Locked in info after: no"
 *   "All Mutex tests passed"
 */

require '../vendor/autoload.php';

use vosaka\foroutines\sync\Mutex;

use function vosaka\foroutines\main;

main(function () {
    // ================================================================
    // Test 1: Basic acquire and release
    // ================================================================
    var_dump('=== Test 1: Mutex create and basic acquire/release ===');
    $mutex = Mutex::create('test_basic_' . uniqid());
    $acquired = $mutex->acquire();
    var_dump('Acquired: ' . ($acquired ? 'yes' : 'no'));
    var_dump('Is locked: ' . ($mutex->isLocked() ? 'yes' : 'no'));
    $released = $mutex->release();
    var_dump('Released: ' . ($released ? 'yes' : 'no'));
    var_dump('Is locked after release: ' . ($mutex->isLocked() ? 'yes' : 'no'));

    // ================================================================
    // Test 2: Double acquire returns true (reentrant for same instance)
    // ================================================================
    var_dump('=== Test 2: Double acquire returns true (reentrant for same instance) ===');
    $mutex2 = Mutex::create('test_double_' . uniqid());
    $first = $mutex2->acquire();
    var_dump('First acquire: ' . ($first ? 'yes' : 'no'));
    $second = $mutex2->acquire(); // Already locked by this instance, returns true
    var_dump('Second acquire: ' . ($second ? 'yes' : 'no'));
    $mutex2->release();

    // ================================================================
    // Test 3: Release when not locked returns true
    // ================================================================
    var_dump('=== Test 3: Release when not locked returns true ===');
    $mutex3 = Mutex::create('test_release_' . uniqid());
    $result = $mutex3->release();
    var_dump('Release when not locked: ' . ($result ? 'yes' : 'no'));

    // ================================================================
    // Test 4: tryLock non-blocking
    // ================================================================
    var_dump('=== Test 4: tryLock non-blocking ===');
    $mutex4 = Mutex::create('test_trylock_' . uniqid());
    $tryResult = $mutex4->tryLock();
    var_dump('tryLock result: ' . ($tryResult ? 'yes' : 'no'));
    var_dump('Is locked after tryLock: ' . ($mutex4->isLocked() ? 'yes' : 'no'));
    $released = $mutex4->release();
    var_dump('Released after tryLock: ' . ($released ? 'yes' : 'no'));

    // ================================================================
    // Test 5: synchronized runs callback under lock
    // ================================================================
    var_dump('=== Test 5: synchronized runs callback under lock ===');
    $mutex5 = Mutex::create('test_sync_' . uniqid());
    $result = $mutex5->synchronized(function () use ($mutex5) {
        var_dump('Inside synchronized, is locked: ' . ($mutex5->isLocked() ? 'yes' : 'no'));
        return 42;
    });
    var_dump("Synchronized returned: $result");
    var_dump('Is locked after synchronized: ' . ($mutex5->isLocked() ? 'yes' : 'no'));

    // ================================================================
    // Test 6: synchronized releases lock even on exception
    // ================================================================
    var_dump('=== Test 6: synchronized releases lock even on exception ===');
    $mutex6 = Mutex::create('test_sync_exc_' . uniqid());
    try {
        $mutex6->synchronized(function () {
            throw new RuntimeException('intentional error');
        });
    } catch (RuntimeException $e) {
        var_dump('Caught inside synchronized: ' . $e->getMessage());
    }
    var_dump('Is locked after exception: ' . ($mutex6->isLocked() ? 'yes' : 'no'));

    // ================================================================
    // Test 7: Mutex::protect static shorthand
    // ================================================================
    var_dump('=== Test 7: Mutex::protect static shorthand ===');
    $protectResult = Mutex::protect('test_protect_' . uniqid(), function () {
        return 'hello from protect';
    });
    var_dump("Protect returned: $protectResult");

    // ================================================================
    // Test 8: Mutex getInfo returns useful data
    // ================================================================
    var_dump('=== Test 8: Mutex getInfo returns useful data ===');
    $infoName = 'test_info_' . uniqid();
    $mutex8 = Mutex::create($infoName);
    $info = $mutex8->getInfo();
    var_dump('Info has name: ' . (array_key_exists('name', $info) ? 'yes' : 'no'));
    var_dump('Info has locked: ' . (array_key_exists('locked', $info) ? 'yes' : 'no'));
    var_dump('Info has actual_type: ' . (array_key_exists('actual_type', $info) ? 'yes' : 'no'));
    var_dump('Info has platform: ' . (array_key_exists('platform', $info) ? 'yes' : 'no'));
    var_dump('Info name matches: ' . ($info['name'] === $infoName ? 'yes' : 'no'));

    // ================================================================
    // Test 9: Mutex getPlatformInfo
    // ================================================================
    var_dump('=== Test 9: Mutex getPlatformInfo ===');
    $platformInfo = Mutex::getPlatformInfo();
    var_dump('Platform info has platform: ' . (array_key_exists('platform', $platformInfo) ? 'yes' : 'no'));
    var_dump('Platform info has available_methods: ' . (array_key_exists('available_methods', $platformInfo) ? 'yes' : 'no'));
    var_dump('Platform info file always available: ' . ($platformInfo['available_methods']['file'] === true ? 'yes' : 'no'));

    // ================================================================
    // Test 10: Empty name throws InvalidArgumentException
    // ================================================================
    var_dump('=== Test 10: Empty name throws InvalidArgumentException ===');
    try {
        Mutex::create('');
    } catch (InvalidArgumentException $e) {
        var_dump('Caught empty name: ' . $e->getMessage());
    }

    // ================================================================
    // Test 11: Invalid lock type throws
    // ================================================================
    var_dump('=== Test 11: Invalid lock type throws ===');
    try {
        new Mutex('test_invalid_type_' . uniqid(), 'invalid_type');
    } catch (InvalidArgumentException $e) {
        var_dump('Caught invalid type: ' . $e->getMessage());
    }

    // ================================================================
    // Test 12: Mutex with explicit LOCK_FILE type
    // ================================================================
    var_dump('=== Test 12: Mutex with explicit LOCK_FILE type ===');
    $mutexFile = new Mutex('test_file_lock_' . uniqid(), Mutex::LOCK_FILE);
    $acquired = $mutexFile->acquire();
    var_dump('File lock acquired: ' . ($acquired ? 'yes' : 'no'));
    $released = $mutexFile->release();
    var_dump('File lock released: ' . ($released ? 'yes' : 'no'));

    // ================================================================
    // Test 13: Mutex with LOCK_AUTO type
    // ================================================================
    var_dump('=== Test 13: Mutex with LOCK_AUTO type ===');
    $mutexAuto = new Mutex('test_auto_lock_' . uniqid(), Mutex::LOCK_AUTO);
    $acquired = $mutexAuto->acquire();
    var_dump('Auto lock acquired: ' . ($acquired ? 'yes' : 'no'));
    $infoAuto = $mutexAuto->getInfo();
    var_dump('Auto lock info actual_type is set: ' . (!empty($infoAuto['actual_type']) ? 'yes' : 'no'));
    $mutexAuto->release();

    // ================================================================
    // Test 14: Multiple mutexes with different names are independent
    // ================================================================
    var_dump('=== Test 14: Multiple mutexes with different names ===');
    $mutexA = Mutex::create('test_multi_a_' . uniqid());
    $mutexB = Mutex::create('test_multi_b_' . uniqid());
    $mutexA->acquire();
    $mutexB->acquire();
    var_dump('Mutex A locked: ' . ($mutexA->isLocked() ? 'yes' : 'no'));
    var_dump('Mutex B locked: ' . ($mutexB->isLocked() ? 'yes' : 'no'));
    var_dump('Both independent: ' . ($mutexA->isLocked() && $mutexB->isLocked() ? 'yes' : 'no'));
    $mutexA->release();
    $mutexB->release();

    // ================================================================
    // Test 15: synchronized with accumulation across calls
    // ================================================================
    var_dump('=== Test 15: synchronized with accumulation ===');
    $mutexAcc = Mutex::create('test_accumulate_' . uniqid());
    $value = 0;
    for ($i = 0; $i < 10; $i++) {
        $mutexAcc->synchronized(function () use (&$value) {
            $value++;
        });
    }
    var_dump("Accumulated value: $value");

    // ================================================================
    // Test 16: synchronized returns complex data (array)
    // ================================================================
    var_dump('=== Test 16: synchronized returns complex data ===');
    $mutexComplex = Mutex::create('test_complex_' . uniqid());
    $data = $mutexComplex->synchronized(function () {
        return [10, 20, 30];
    });
    var_dump('Returned array count: ' . count($data));
    var_dump('Returned array sum: ' . array_sum($data));

    // ================================================================
    // Test 17: Nested synchronized with different mutexes
    // ================================================================
    var_dump('=== Test 17: Nested synchronized with different mutexes ===');
    $outerMutex = Mutex::create('test_outer_' . uniqid());
    $innerMutex = Mutex::create('test_inner_' . uniqid());
    $nestedResult = $outerMutex->synchronized(function () use ($outerMutex, $innerMutex) {
        var_dump('Outer locked: ' . ($outerMutex->isLocked() ? 'yes' : 'no'));
        return $innerMutex->synchronized(function () use ($innerMutex) {
            var_dump('Inner locked: ' . ($innerMutex->isLocked() ? 'yes' : 'no'));
            return 'inner_done';
        });
    });
    var_dump("Nested result: $nestedResult");

    // ================================================================
    // Test 18: Mutex::protect with exception propagates
    // ================================================================
    var_dump('=== Test 18: Mutex::protect with exception propagates ===');
    try {
        Mutex::protect('test_protect_exc_' . uniqid(), function () {
            throw new RuntimeException('protect_error');
        });
    } catch (RuntimeException $e) {
        var_dump('Caught protect exception: ' . $e->getMessage());
    }

    // ================================================================
    // Test 19: tryLock after release works (lock cycle)
    // ================================================================
    var_dump('=== Test 19: tryLock after release works ===');
    $mutexCycle = Mutex::create('test_cycle_' . uniqid());
    $a = $mutexCycle->acquire();
    var_dump('Acquire: ' . ($a ? 'yes' : 'no'));
    $r = $mutexCycle->release();
    var_dump('Release: ' . ($r ? 'yes' : 'no'));
    $t = $mutexCycle->tryLock();
    var_dump('tryLock after release: ' . ($t ? 'yes' : 'no'));
    $r2 = $mutexCycle->release();
    var_dump('Release again: ' . ($r2 ? 'yes' : 'no'));

    // ================================================================
    // Test 20: Mutex info reflects lock state changes
    // ================================================================
    var_dump('=== Test 20: Mutex info reflects lock state changes ===');
    $mutexState = Mutex::create('test_state_info_' . uniqid());
    $infoBefore = $mutexState->getInfo();
    var_dump('Locked in info before: ' . ($infoBefore['locked'] ? 'yes' : 'no'));
    $mutexState->acquire();
    $infoDuring = $mutexState->getInfo();
    var_dump('Locked in info during: ' . ($infoDuring['locked'] ? 'yes' : 'no'));
    $mutexState->release();
    $infoAfter = $mutexState->getInfo();
    var_dump('Locked in info after: ' . ($infoAfter['locked'] ? 'yes' : 'no'));

    var_dump('All Mutex tests passed');
});
