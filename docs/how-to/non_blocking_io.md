# How-to: Non-blocking IO

VOsaka Foroutines provides the `AsyncIO` utility to handle network and file operations without blocking the main event loop.

## Key Features
- **Network**: HTTP GET/POST, TCP/TLS connections.
- **Files**: Non-blocking `file_get_contents` and `file_put_contents`.
- **DNS**: Non-blocking hostname resolution.

## Implementation Example

```php
<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use function vosaka\foroutines\main;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\AsyncIO;

main(function () {
    RunBlocking::new(function () {
        
        echo "--- Non-blocking HTTP GET ---\n";
        // AsyncIO::httpGet returns a Deferred. await() drives the loop until done.
        $html = AsyncIO::httpGet('https://example.com')->await();
        echo "Fetched " . strlen($html) . " bytes from example.com\n";

        echo "\n--- Non-blocking File Read ---\n";
        $file = __DIR__ . '/../../README.md';
        $content = AsyncIO::fileGetContents($file)->await();
        echo "Read " . strlen($content) . " bytes from README.md\n";

        echo "\nDone.\n";
    });
});
```
