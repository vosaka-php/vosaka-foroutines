# How-to: Background Tasks (WorkerPool)

When a task is CPU-intensive (e.g., heavy math, image processing), it should be offloaded to a `WorkerPool` so it doesn't block the main process.

## WorkerPool Features
- **Dynamic Scaling**: Automatically spawns new workers under load.
- **Task Batching**: Groups small tasks together for efficient IPC.
- **Automatic Respawn**: Restarts workers if they crash.

## Implementation Example

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
