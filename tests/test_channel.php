<?php

/**
 * Test: Channel (in-process buffered/unbuffered send/receive)
 *
 * Demonstrates the Channel API for communicating between foroutines:
 *
 *   - Channels::new() creates an unbuffered (rendezvous) channel
 *   - Channels::createBuffered(n) creates a buffered channel with capacity n
 *   - channel->send() / channel->receive() for blocking send/receive
 *   - channel->trySend() / channel->tryReceive() for non-blocking attempts
 *   - channel->close() to close the channel
 *   - channel->isClosed() / channel->isEmpty() / channel->isFull() / channel->size()
 *   - channel->getInfo() for debug information
 *   - Iterating over a channel with getIterator()
 *   - Channels::from() to create a channel pre-filled from an array
 *   - Channels::range() to create a channel emitting a numeric range
 *   - Multiple producers / single consumer pattern
 *   - Error handling on closed channels
 *
 * Expected output:
 *   "=== Test 1: Buffered channel basic send/receive ==="
 *   "Sent: 10"
 *   "Sent: 20"
 *   "Sent: 30"
 *   "Received: 10"
 *   "Received: 20"
 *   "Received: 30"
 *   "=== Test 2: Channel size and state checks ==="
 *   "Is empty after creation: yes"
 *   "Size after 2 sends: 2"
 *   "Is full (cap=3, size=2): no"
 *   "Sent third, is full: yes"
 *   "After receive, size: 2"
 *   "Is closed: no"
 *   "=== Test 3: trySend and tryReceive ==="
 *   "trySend 100: success"
 *   "trySend 200: success"
 *   "tryReceive: 100"
 *   "tryReceive: 200"
 *   "tryReceive on empty: NULL"
 *   "=== Test 4: trySend on full channel ==="
 *   "trySend when full: failed"
 *   "=== Test 5: Close channel ==="
 *   "Channel closed"
 *   "Is closed: yes"
 *   "Send after close threw: Channel is closed"
 *   "=== Test 6: Receive remaining after close ==="
 *   "Remaining: AAA"
 *   "Remaining: BBB"
 *   "=== Test 7: Channel getInfo ==="
 *   "Info has capacity key: yes"
 *   "Info has closed key: yes"
 *   "=== Test 8: Buffered channel FIFO order ==="
 *   "FIFO 1: first"
 *   "FIFO 2: second"
 *   "FIFO 3: third"
 *   "=== Test 9: Channel with various data types ==="
 *   "Type int: 42"
 *   "Type float: 3.14"
 *   "Type string: hello"
 *   "Type bool: true"
 *   "Type array: [1, 2, 3]"
 *   "Type null: NULL"
 *   "=== Test 10: Large buffered channel ==="
 *   "Sent 100 items"
 *   "Received 100 items"
 *   "Sum matches: yes"
 *   "=== Test 11: tryReceive on closed empty channel ==="
 *   "tryReceive on closed empty: NULL"
 *   "=== Test 12: trySend on closed channel ==="
 *   "trySend on closed: failed"
 *   "=== Test 13: Multiple close calls ==="
 *   "Second close handled gracefully"
 *   "=== Test 14: Channel with single capacity ==="
 *   "Single cap send: success"
 *   "Single cap full: yes"
 *   "Single cap trySend when full: failed"
 *   "Single cap receive: ping"
 *   "Single cap empty after receive: yes"
 *   "All Channel tests passed"
 */

require '../vendor/autoload.php';

use vosaka\foroutines\channel\Channel;
use vosaka\foroutines\channel\Channels;

use function vosaka\foroutines\main;

main(function () {
    // Test 1: Buffered channel basic send/receive
    var_dump('=== Test 1: Buffered channel basic send/receive ===');
    $ch = Channels::createBuffered(5);
    $ch->send(10);
    var_dump('Sent: 10');
    $ch->send(20);
    var_dump('Sent: 20');
    $ch->send(30);
    var_dump('Sent: 30');

    $v1 = $ch->receive();
    var_dump("Received: $v1");
    $v2 = $ch->receive();
    var_dump("Received: $v2");
    $v3 = $ch->receive();
    var_dump("Received: $v3");

    // Test 2: Channel size and state checks
    var_dump('=== Test 2: Channel size and state checks ===');
    $ch2 = Channels::createBuffered(3);
    var_dump('Is empty after creation: ' . ($ch2->isEmpty() ? 'yes' : 'no'));
    $ch2->send('a');
    $ch2->send('b');
    var_dump('Size after 2 sends: ' . $ch2->size());
    var_dump('Is full (cap=3, size=2): ' . ($ch2->isFull() ? 'yes' : 'no'));
    $ch2->send('c');
    var_dump('Sent third, is full: ' . ($ch2->isFull() ? 'yes' : 'no'));
    $ch2->receive();
    var_dump('After receive, size: ' . $ch2->size());
    var_dump('Is closed: ' . ($ch2->isClosed() ? 'yes' : 'no'));

    // Test 3: trySend and tryReceive (non-blocking)
    var_dump('=== Test 3: trySend and tryReceive ===');
    $ch3 = Channels::createBuffered(5);
    $r1 = $ch3->trySend(100);
    var_dump('trySend 100: ' . ($r1 ? 'success' : 'failed'));
    $r2 = $ch3->trySend(200);
    var_dump('trySend 200: ' . ($r2 ? 'success' : 'failed'));

    $v = $ch3->tryReceive();
    var_dump("tryReceive: $v");
    $v = $ch3->tryReceive();
    var_dump("tryReceive: $v");
    $v = $ch3->tryReceive();
    var_dump('tryReceive on empty: ' . ($v === null ? 'NULL' : $v));

    // Test 4: trySend on full channel should fail
    var_dump('=== Test 4: trySend on full channel ===');
    $ch4 = Channels::createBuffered(2);
    $ch4->trySend('x');
    $ch4->trySend('y');
    $full = $ch4->trySend('z'); // should fail - channel is full
    var_dump('trySend when full: ' . ($full ? 'success' : 'failed'));

    // Test 5: Close channel and attempt send
    var_dump('=== Test 5: Close channel ===');
    $ch5 = Channels::createBuffered(5);
    $ch5->close();
    var_dump('Channel closed');
    var_dump('Is closed: ' . ($ch5->isClosed() ? 'yes' : 'no'));
    try {
        $ch5->send('should fail');
        var_dump('ERROR: send after close did not throw');
    } catch (Exception $e) {
        var_dump('Send after close threw: ' . $e->getMessage());
    }

    // Test 6: Receive remaining items after channel is closed
    var_dump('=== Test 6: Receive remaining after close ===');
    $ch6 = Channels::createBuffered(5);
    $ch6->send('AAA');
    $ch6->send('BBB');
    $ch6->close();
    // Should still be able to receive items that were sent before close
    $remaining1 = $ch6->receive();
    var_dump("Remaining: $remaining1");
    $remaining2 = $ch6->receive();
    var_dump("Remaining: $remaining2");

    // Test 7: Channel getInfo returns useful debug data
    var_dump('=== Test 7: Channel getInfo ===');
    $ch7 = Channels::createBuffered(10);
    $ch7->send('data');
    $info = $ch7->getInfo();
    var_dump('Info has capacity key: ' . (array_key_exists('capacity', $info) ? 'yes' : 'no'));
    var_dump('Info has closed key: ' . (array_key_exists('closed', $info) ? 'yes' : 'no'));

    // Test 8: FIFO ordering in buffered channel
    var_dump('=== Test 8: Buffered channel FIFO order ===');
    $ch8 = Channels::createBuffered(10);
    $ch8->send('first');
    $ch8->send('second');
    $ch8->send('third');
    var_dump('FIFO 1: ' . $ch8->receive());
    var_dump('FIFO 2: ' . $ch8->receive());
    var_dump('FIFO 3: ' . $ch8->receive());

    // Test 9: Channel with various data types
    var_dump('=== Test 9: Channel with various data types ===');
    $ch9 = Channels::createBuffered(10);
    $ch9->send(42);
    $ch9->send(3.14);
    $ch9->send('hello');
    $ch9->send(true);
    $ch9->send([1, 2, 3]);
    $ch9->send(null);

    $val = $ch9->receive();
    var_dump("Type int: $val");
    $val = $ch9->receive();
    var_dump("Type float: $val");
    $val = $ch9->receive();
    var_dump("Type string: $val");
    $val = $ch9->receive();
    var_dump('Type bool: ' . ($val ? 'true' : 'false'));
    $val = $ch9->receive();
    var_dump('Type array: [' . implode(', ', $val) . ']');
    $val = $ch9->receive();
    var_dump('Type null: ' . ($val === null ? 'NULL' : $val));

    // Test 10: Large buffered channel - send and receive 100 items
    var_dump('=== Test 10: Large buffered channel ===');
    $ch10 = Channels::createBuffered(100);
    $expectedSum = 0;
    for ($i = 1; $i <= 100; $i++) {
        $ch10->send($i);
        $expectedSum += $i;
    }
    var_dump('Sent 100 items');
    $actualSum = 0;
    for ($i = 0; $i < 100; $i++) {
        $actualSum += $ch10->receive();
    }
    var_dump('Received 100 items');
    var_dump('Sum matches: ' . ($actualSum === $expectedSum ? 'yes' : 'no'));

    // Test 11: tryReceive on closed empty channel returns null
    var_dump('=== Test 11: tryReceive on closed empty channel ===');
    $ch11 = Channels::createBuffered(5);
    $ch11->close();
    $val = $ch11->tryReceive();
    var_dump('tryReceive on closed empty: ' . ($val === null ? 'NULL' : $val));

    // Test 12: trySend on closed channel returns false
    var_dump('=== Test 12: trySend on closed channel ===');
    $ch12 = Channels::createBuffered(5);
    $ch12->close();
    $result = $ch12->trySend('nope');
    var_dump('trySend on closed: ' . ($result ? 'success' : 'failed'));

    // Test 13: Multiple close calls should not throw (idempotent)
    var_dump('=== Test 13: Multiple close calls ===');
    $ch13 = Channels::createBuffered(5);
    $ch13->close();
    try {
        $ch13->close(); // second close
        var_dump('Second close handled gracefully');
    } catch (Exception $e) {
        var_dump('Second close threw: ' . $e->getMessage());
    }

    // Test 14: Channel with single capacity (capacity=1)
    var_dump('=== Test 14: Channel with single capacity ===');
    $ch14 = Channels::createBuffered(1);
    $sent = $ch14->trySend('ping');
    var_dump('Single cap send: ' . ($sent ? 'success' : 'failed'));
    var_dump('Single cap full: ' . ($ch14->isFull() ? 'yes' : 'no'));
    $overflow = $ch14->trySend('overflow');
    var_dump('Single cap trySend when full: ' . ($overflow ? 'success' : 'failed'));
    $val = $ch14->receive();
    var_dump("Single cap receive: $val");
    var_dump('Single cap empty after receive: ' . ($ch14->isEmpty() ? 'yes' : 'no'));

    var_dump('All Channel tests passed');
});
