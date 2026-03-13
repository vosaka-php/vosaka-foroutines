# How-to: Handling Timeouts

Learn how to set limits on how long a task can run using `WithTimeout` and `WithTimeoutOrNull`.

## Timeout Strategies

### 1. WithTimeout
Throws a `RuntimeException` if the task exceeds the given time limit.

### 2. WithTimeoutOrNull
Returns `null` instead of throwing an exception if the timeout is reached.

## Implementation Example

```php
<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use function vosaka\foroutines\main;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\WithTimeout;
use vosaka\foroutines\WithTimeoutOrNull;
use vosaka\foroutines\Delay;

main(function () {
    RunBlocking::new(function () {
        
        echo "--- WithTimeout (throws exception) ---\n";
        try {
            WithTimeout::new(1000, function () {
                echo "Task starts (will take 2s)...\n";
                Delay::new(2000);
                return "Completed";
            });
        } catch (\RuntimeException $e) {
            echo "Caught expected timeout: " . $e->getMessage() . "\n";
        }

        echo "\n--- WithTimeoutOrNull (returns null) ---\n";
        $result = WithTimeoutOrNull::new(1000, function () {
            echo "Task starts (will take 2s)...\n";
            Delay::new(2000);
            return "Completed";
        });

        if ($result === null) {
            echo "Task timed out as expected.\n";
        }
    });
});
```
