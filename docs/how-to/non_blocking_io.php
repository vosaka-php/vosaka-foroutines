<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use function vosaka\foroutines\main;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\AsyncIO;

/**
 * How-to: Non-blocking IO
 * 
 * Learn how to perform network and file operations without blocking 
 * the entire process.
 */

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
