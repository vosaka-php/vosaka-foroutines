<?php

use vosaka\foroutines\channel\Channels;

require '../vendor/autoload.php';

echo "=== NATIVE FIBER CHANNEL TEST ===" . PHP_EOL;

// Clean up
Channels::remove("native_test");

// Create channel  
$mainChannel = Channels::createInterProcess("native_test", 5);
echo "✓ Channel created" . PHP_EOL;

// Give channel time to initialize
usleep(200000);

// Create sender fiber using native PHP Fiber
$senderFiber = new Fiber(function () {
    echo "Native Sender: Starting..." . PHP_EOL;

    // Wait for channel
    $attempts = 0;
    while (!Channels::exists("native_test") && $attempts < 20) {
        $attempts++;
        usleep(100000); // 100ms
        echo "Native Sender: Waiting for channel (attempt $attempts)" . PHP_EOL;
    }

    if (!Channels::exists("native_test")) {
        echo "Native Sender: Channel not found!" . PHP_EOL;
        return false;
    }

    try {
        $channel = Channels::connect("native_test");
        echo "Native Sender: Connected!" . PHP_EOL;

        $messages = ['Hello', 'World', 'Native'];
        foreach ($messages as $msg) {
            $success = $channel->trySend($msg);
            echo "Native Sender: Send '$msg' = " . ($success ? "✓" : "✗") . PHP_EOL;
            usleep(100000); // 100ms
        }

        echo "Native Sender: All messages sent!" . PHP_EOL;
        return true;
    } catch (Exception $e) {
        echo "Native Sender error: " . $e->getMessage() . PHP_EOL;
        return false;
    }
});

// Create receiver fiber using native PHP Fiber
$receiverFiber = new Fiber(function () {
    echo "Native Receiver: Starting..." . PHP_EOL;

    // Wait for channel
    $attempts = 0;
    while (!Channels::exists("native_test") && $attempts < 20) {
        $attempts++;
        usleep(100000); // 100ms
        echo "Native Receiver: Waiting for channel (attempt $attempts)" . PHP_EOL;
    }

    if (!Channels::exists("native_test")) {
        echo "Native Receiver: Channel not found!" . PHP_EOL;
        return [];
    }

    try {
        $channel = Channels::connect("native_test");
        echo "Native Receiver: Connected!" . PHP_EOL;

        $messages = [];
        $maxAttempts = 50;
        $attempts = 0;

        while ($attempts < $maxAttempts && count($messages) < 3) {
            $attempts++;

            $msg = $channel->tryReceive();
            if ($msg !== null) {
                $messages[] = $msg;
                echo "Native Receiver: Got '$msg' (total: " . count($messages) . ")" . PHP_EOL;
            }

            usleep(100000); // 100ms

            if ($attempts % 10 == 0) {
                echo "Native Receiver: Attempt $attempts, messages: " . count($messages) . PHP_EOL;
            }
        }

        echo "Native Receiver: Finished with " . count($messages) . " messages" . PHP_EOL;
        return $messages;
    } catch (Exception $e) {
        echo "Native Receiver error: " . $e->getMessage() . PHP_EOL;
        return [];
    }
});

echo "Starting native fibers..." . PHP_EOL;

// Start fibers
$senderFiber->start();
$receiverFiber->start();

echo "Fibers started, running..." . PHP_EOL;

// Run fibers alternately
$maxIterations = 100;
$iteration = 0;
$senderDone = false;
$receiverDone = false;

while ($iteration < $maxIterations && (!$senderDone || !$receiverDone)) {
    $iteration++;

    // Resume sender if not done
    if (!$senderDone && $senderFiber->isSuspended()) {
        try {
            $senderFiber->resume();
            if ($senderFiber->isTerminated()) {
                $senderResult = $senderFiber->getReturn();
                echo "Sender finished with result: " . ($senderResult ? "SUCCESS" : "FAILED") . PHP_EOL;
                $senderDone = true;
            }
        } catch (Exception $e) {
            echo "Sender error: " . $e->getMessage() . PHP_EOL;
            $senderDone = true;
        }
    } elseif ($senderFiber->isTerminated() && !$senderDone) {
        $senderDone = true;
        echo "Sender terminated" . PHP_EOL;
    }

    // Resume receiver if not done
    if (!$receiverDone && $receiverFiber->isSuspended()) {
        try {
            $receiverFiber->resume();
            if ($receiverFiber->isTerminated()) {
                $receiverResult = $receiverFiber->getReturn();
                echo "Receiver finished with " . count($receiverResult) . " messages: " .
                    implode(', ', $receiverResult) . PHP_EOL;
                $receiverDone = true;
            }
        } catch (Exception $e) {
            echo "Receiver error: " . $e->getMessage() . PHP_EOL;
            $receiverDone = true;
        }
    } elseif ($receiverFiber->isTerminated() && !$receiverDone) {
        $receiverDone = true;
        echo "Receiver terminated" . PHP_EOL;
    }

    // Progress indicator
    if ($iteration % 10 == 0) {
        echo "Iteration $iteration: Sender=" . ($senderDone ? "✓" : "⏳") .
            " Receiver=" . ($receiverDone ? "✓" : "⏳") . PHP_EOL;
    }

    // Small delay in main thread
    usleep(50000); // 50ms
}

if ($iteration >= $maxIterations) {
    echo "⚠️ Max iterations reached!" . PHP_EOL;
} else {
    echo "✓ Both fibers completed!" . PHP_EOL;
}

// Check final channel state
echo "Final channel size: " . $mainChannel->size() . PHP_EOL;

// Cleanup
$mainChannel->close();
Channels::remove("native_test");

echo "=== NATIVE FIBER TEST COMPLETED ===" . PHP_EOL;
