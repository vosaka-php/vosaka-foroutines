# How-to: Background Tasks (WorkerPool)

When a task is CPU-intensive (e.g., heavy math, image processing), it should be offloaded to a `WorkerPool` so it doesn't block the main process.

## Why use Dispatchers::IO?
In asynchronous programming, "blocking" is the enemy. If you run a heavy loop inside a standard Fiber (using the **DEFAULT** dispatcher), no other fibers can run until that loop finishes.

The **IO Dispatcher** solves this by sending the task to a **WorkerPool**. Instead of running in your main process, the task runs in a child process managed by the OS. This allows your main process to stay responsive and handle other async IO (like web requests) simultaneously.

## Implementation Example
In the example below, notice how we use `Dispatchers::IO` to offload work.

```php
<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use function vosaka\foroutines\main;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Launch;
use vosaka\foroutines\Dispatchers;
use vosaka\foroutines\WorkerPool;

main(function () {
    // Configure the worker pool
    WorkerPool::setPoolSize(4);

    RunBlocking::new(function () {
        
        echo "Offloading a heavy task to a background worker...\n";

        $deferred = WorkerPool::addAsync(function () {
            // This runs in a separate process
            $sum = 0;
            for ($i = 0; $i < 1_000_000; $i++) {
                $sum += $i;
            }
            return $sum;
        });

        echo "Parent can do other things while worker is busy...\n";
        
        $result = $deferred->await();
        echo "Worker result: $result\n";

        echo "\nYou can also use Launch with Dispatchers::IO:\n";

        Launch::new(function () {
            echo "[IO Worker] Doing some background work...\n";
            sleep(1); // Traditional blocking sleep is OK in a dedicated process
            echo "[IO Worker] Done.\n";
        }, Dispatchers::IO);

    });
});
```
