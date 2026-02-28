<?php

/**
 * Test: Select for multiplexing channel operations
 *
 * Demonstrates the Select API which allows multiplexing over multiple
 * channel operations (similar to Kotlin's select expression):
 *
 *   - select->onSend(channel, value, action) registers a send case
 *   - select->onReceive(channel, action) registers a receive case
 *   - select->default(value) provides a default when no case is ready
 *   - select->execute() runs the first ready case or falls back to default
 *
 * Expected output:
 *   "=== Test 1: Select onReceive from ready channel ==="
 *   "Selected receive: hello"
 *   "=== Test 2: Select onSend to channel with capacity ==="
 *   "Send action executed"
 *   "Value in channel: 42"
 *   "=== Test 3: Select with default when no case ready ==="
 *   "Default selected: nothing_available"
 *   "=== Test 4: Select among multiple receive channels ==="
 *   "Got value from a channel: alpha"
 *   "=== Test 5: Select onReceive with transformation in action ==="
 *   "Transformed: VALUE=world"
 *   "=== Test 6: Select onSend when channel is full falls to default ==="
 *   "Fell through to default: channel_full"
 *   "=== Test 7: Select with multiple onSend cases ==="
 *   "Sent to a channel successfully"
 *   "=== Test 8: Select receive returns action result ==="
 *   "Action returned: 30"
 *   "=== Test 9: Select onSend action return value ==="
 *   "Send action result: sent_ok"
 *   "=== Test 10: Select default with null ==="
 *   "Default is null: yes"
 *   "=== Test 11: Select with both send and receive ready ==="
 *   "At least one case executed"
 *   "=== Test 12: Select without default blocks on random case ==="
 *   "Blocked select got value"
 *   "All Select tests passed"
 */

require '../vendor/autoload.php';

use vosaka\foroutines\channel\Channels;
use vosaka\foroutines\selects\Select;

use function vosaka\foroutines\main;

main(function () {
    // Test 1: Select onReceive from a channel that already has data
    var_dump('=== Test 1: Select onReceive from ready channel ===');
    $ch1 = Channels::createBuffered(5);
    $ch1->send('hello');

    $select1 = new Select();
    $result1 = $select1
        ->onReceive($ch1, function ($value) {
            return "Selected receive: $value";
        })
        ->execute();
    var_dump($result1);

    // Test 2: Select onSend to a channel with available capacity
    var_dump('=== Test 2: Select onSend to channel with capacity ===');
    $ch2 = Channels::createBuffered(5);

    $select2 = new Select();
    $result2 = $select2
        ->onSend($ch2, 42, function () {
            return 'Send action executed';
        })
        ->execute();
    var_dump($result2);
    var_dump('Value in channel: ' . $ch2->receive());

    // Test 3: Select with default when no channel case is ready
    // Use an empty channel for receive - nothing to receive
    var_dump('=== Test 3: Select with default when no case ready ===');
    $chEmpty = Channels::createBuffered(5);

    $select3 = new Select();
    $result3 = $select3
        ->onReceive($chEmpty, function ($value) {
            return "Received: $value";
        })
        ->default('nothing_available')
        ->execute();
    var_dump("Default selected: $result3");

    // Test 4: Select among multiple receive channels - first ready wins
    var_dump('=== Test 4: Select among multiple receive channels ===');
    $chA = Channels::createBuffered(5);
    $chB = Channels::createBuffered(5);
    $chC = Channels::createBuffered(5);

    $chA->send('alpha');
    // chB and chC are empty

    $select4 = new Select();
    $result4 = $select4
        ->onReceive($chA, function ($value) {
            return "Got value from a channel: $value";
        })
        ->onReceive($chB, function ($value) {
            return "Got from B: $value";
        })
        ->onReceive($chC, function ($value) {
            return "Got from C: $value";
        })
        ->execute();
    var_dump($result4);

    // Test 5: Select onReceive with transformation in the action callback
    var_dump('=== Test 5: Select onReceive with transformation in action ===');
    $ch5 = Channels::createBuffered(5);
    $ch5->send('world');

    $select5 = new Select();
    $result5 = $select5
        ->onReceive($ch5, function ($value) {
            return 'Transformed: VALUE=' . $value;
        })
        ->execute();
    var_dump($result5);

    // Test 6: Select onSend when channel is full, falls through to default
    var_dump('=== Test 6: Select onSend when channel is full falls to default ===');
    $chFull = Channels::createBuffered(1);
    $chFull->send('occupant'); // now full

    $select6 = new Select();
    $result6 = $select6
        ->onSend($chFull, 'overflow', function () {
            return 'sent_to_full';
        })
        ->default('channel_full')
        ->execute();
    var_dump("Fell through to default: $result6");

    // Test 7: Select with multiple onSend cases - at least one channel has space
    var_dump('=== Test 7: Select with multiple onSend cases ===');
    $chSend1 = Channels::createBuffered(1);
    $chSend1->send('blocker'); // full
    $chSend2 = Channels::createBuffered(5); // has space

    $select7 = new Select();
    $result7 = $select7
        ->onSend($chSend1, 'val1', function () {
            return 'Sent to channel 1';
        })
        ->onSend($chSend2, 'val2', function () {
            return 'Sent to a channel successfully';
        })
        ->execute();
    var_dump($result7);

    // Test 8: Select receive action can compute and return a derived value
    var_dump('=== Test 8: Select receive returns action result ===');
    $ch8 = Channels::createBuffered(5);
    $ch8->send(10);

    $select8 = new Select();
    $result8 = $select8
        ->onReceive($ch8, function ($value) {
            return 'Action returned: ' . ($value * 3);
        })
        ->execute();
    var_dump($result8);

    // Test 9: Select onSend action return value is propagated
    var_dump('=== Test 9: Select onSend action return value ===');
    $ch9 = Channels::createBuffered(5);

    $select9 = new Select();
    $result9 = $select9
        ->onSend($ch9, 'payload', function () {
            return 'sent_ok';
        })
        ->execute();
    var_dump("Send action result: $result9");

    // Test 10: Select default with null value
    var_dump('=== Test 10: Select default with null ===');
    $chNone = Channels::createBuffered(5);

    $select10 = new Select();
    $result10 = $select10
        ->onReceive($chNone, function ($value) {
            return $value;
        })
        ->default(null)
        ->execute();
    var_dump('Default is null: ' . ($result10 === null ? 'yes' : 'no'));

    // Test 11: Select with both send and receive cases ready
    var_dump('=== Test 11: Select with both send and receive ready ===');
    $chRecv = Channels::createBuffered(5);
    $chRecv->send('recv_data');
    $chSendOk = Channels::createBuffered(5);

    $select11 = new Select();
    $result11 = $select11
        ->onReceive($chRecv, function ($value) {
            return 'received';
        })
        ->onSend($chSendOk, 'send_data', function () {
            return 'sent';
        })
        ->execute();
    // Either 'received' or 'sent' is valid - both cases are ready
    if ($result11 === 'received' || $result11 === 'sent') {
        var_dump('At least one case executed');
    } else {
        var_dump("Unexpected result: $result11");
    }

    // Test 12: Select without default blocks on a random case
    // We ensure at least one channel has data so it won't block forever
    var_dump('=== Test 12: Select without default blocks on random case ===');
    $chBlock = Channels::createBuffered(5);
    $chBlock->send('blocked_value');

    $select12 = new Select();
    $result12 = $select12
        ->onReceive($chBlock, function ($value) {
            return $value;
        })
        ->execute();
    if ($result12 === 'blocked_value') {
        var_dump('Blocked select got value');
    } else {
        var_dump("Unexpected: $result12");
    }

    var_dump('All Select tests passed');
});
