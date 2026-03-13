<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use function vosaka\foroutines\main;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Delay;

/**
 * Tutorial 01: Getting Started
 * 
 * This tutorial covers the basic setup and running your first async task.
 * 
 * All entry points must be wrapped in main() or use the #[AsyncMain] attribute.
 * Inside main, use RunBlocking to start the event loop and wrap your async logic.
 */

main(function () {
    echo "Starting our first async task...\n";

    RunBlocking::new(function () {
        echo "Inside RunBlocking - Task 1 starts\n";
        
        // Delay is a non-blocking pause (milliseconds)
        Delay::new(1000);
        
        echo "Inside RunBlocking - Task 1 finished after 1s\n";
    });

    echo "Main scope finished.\n";
});
