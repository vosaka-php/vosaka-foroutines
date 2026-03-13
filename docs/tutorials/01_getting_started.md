# Tutorial: Getting Started

Welcome to VOsaka Foroutines! This tutorial will guide you through the initial setup and running your first asynchronous task.

## Prerequisites
- PHP 8.2+
- Composer

## Installation
Add the library to your project:
```bash
composer require venndev/vosaka-foroutines
```

## Your First Async Script
All entry points must be wrapped in `main()` or use the `#[AsyncMain]` attribute. Inside `main`, use `RunBlocking` to start the event loop.

```php
<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use function vosaka\foroutines\main;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Delay;

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
```

## Running the Example
Save the code above to a file and run it:
```bash
php your_file.php
```
You will notice that the execution pauses for 1 second without blocking the script's initialization.
