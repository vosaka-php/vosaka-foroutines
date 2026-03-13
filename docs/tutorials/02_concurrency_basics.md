# Tutorial: Concurrency Basics

In this tutorial, you'll learn the difference between the two primary ways to start a coroutine: `Launch` and `Async`.

## Launch vs Async

- **Launch**: Used for "fire-and-forget" tasks. It returns a `Job` object, but it does not return a result from the task itself.
- **Async**: Used when you need a result back. It returns a `Deferred` object, and you must call `await()` to get the return value.

## Understanding Dispatchers
When you start a coroutine using `Launch` or `Async`, you can specify a **Dispatcher** as the second argument. A Dispatcher determines *where* and *how* the task is executed.

- **DEFAULT** (Implicit): If you don't specify a dispatcher, Foroutines uses the `DEFAULT` dispatcher. This runs the code as a **Fiber** within the same process. It is extremely fast but can't run CPU-blocking code without freezing other fibers.
- **IO**: Offloads the task to a **separate process** in the `WorkerPool`. This is best for heavy calculations or blocking calls that would otherwise freeze your application.

## Example Code

```php
<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use function vosaka\foroutines\main;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Launch;
use vosaka\foroutines\Async;
use vosaka\foroutines\Delay;

main(function () {
    RunBlocking::new(function () {
        echo "--- Launch Example ---\n";
        
        Launch::new(function () {
            Delay::new(500);
            echo "Task A (Launch) finished\n";
        });

        echo "Task A was launched, moving on immediately...\n";

        echo "\n--- Async Example ---\n";
        
        $deferred = Async::new(function () {
            Delay::new(1000);
            return "Result from Task B";
        });

        echo "Task B is running, now we await it...\n";
        $result = $deferred->await();
        echo "Task B finished: " . $result . "\n";
    });
});
```

## What happened?
1. `Launch` started Task A and immediately continued to the next line.
2. `Async` started Task B, but the script stopped at `$deferred->await()` until the result was ready.
