# How-to: IPC Communication (Channels)

Channels are the primary way to communicate data between different coroutines or even different processes.

## Inter-Process Communication (IPC)
By using `Channel::create()`, you create a socket-backed channel that can be shared between a parent process and its child workers (launched via the `IO` dispatcher).

## Implementation Example

```php
<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use function vosaka\foroutines\main;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Launch;
use vosaka\foroutines\Dispatchers;
use vosaka\foroutines\channel\Channel;

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
```
