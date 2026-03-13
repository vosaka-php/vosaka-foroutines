<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use function vosaka\foroutines\main;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Launch;
use vosaka\foroutines\Dispatchers;
use vosaka\foroutines\channel\Channel;

/**
 * How-to: IPC Communication (Channels)
 * 
 * This guide shows how to communicate between a parent and a child process.
 */

main(function () {
    // Create a pool-backed IPC channel with capacity 5
    $ch = Channel::create(5);

    RunBlocking::new(function () use ($ch) {
        
        // Launch a task in a separate worker process (IO dispatcher)
        Launch::new(function () use ($ch) {
            $ch->connect(); // Reconnect in the child process
            
            echo "[Child] Sending data...\n";
            $ch->send("Hello from child process!");
            $ch->send("Process-to-process talk is easy.");
            
        }, Dispatchers::IO);

        // Receive data in the parent process
        echo "[Parent] Waiting for data...\n";
        echo "[Parent] Received: " . $ch->receive() . "\n";
        echo "[Parent] Received: " . $ch->receive() . "\n";

        $ch->close();
    });
});
