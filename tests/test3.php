<?php

require_once '../vendor/autoload.php';

use vosaka\foroutines\Async;
use vosaka\foroutines\Dispatchers;
use vosaka\foroutines\Launch;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Delay;

use function vosaka\foroutines\main;

// Simulate HTTP request
function httpRequest(int $id): Async
{
    return Async::new(function () use ($id) {
        // Simulate network delay using Delay
        Delay::new(rand(100, 500)); // 100-500ms random delay

        // Simulate some CPU work
        $result = 0;
        for ($i = 0; $i < 1000000; $i++) {
            $result += sqrt($i);
        }

        return [
            'id' => $id,
            'result' => $result,
            'timestamp' => microtime(true)
        ];
    }, Dispatchers::IO);
}

// Simulate database operation
function dbQuery(int $id): Async
{
    return Async::new(function () use ($id) {
        // Simulate DB query delay using Delay
        Delay::new(rand(50, 200)); // 50-200ms random delay

        // Simulate data processing
        $data = [];
        for ($i = 0; $i < 10000; $i++) {
            $data[] = "user_{$id}_record_{$i}";
        }

        return [
            'id' => $id,
            'records' => count($data),
            'sample' => array_slice($data, 0, 3)
        ];
    }, Dispatchers::IO);
}

main(function () {
    echo "Starting PHP Concurrent Benchmark...\n";
    $startTime = microtime(true);
    $startMemory = memory_get_usage(true);

    $httpResults = [];
    $dbResults = [];

    RunBlocking::new(function () use (&$httpResults, &$dbResults) {
        // Launch 100 concurrent HTTP requests
        for ($i = 0; $i < 100; $i++) {
            Launch::new(function () use ($i, &$httpResults) {
                $result = httpRequest($i)->wait();
                $httpResults[] = $result;
                if (count($httpResults) <= 3) {
                    echo "HTTP {$result['id']} completed: " . number_format($result['result'], 2) . "\n";
                }
            });
        }

        // Launch 50 concurrent DB queries
        for ($i = 0; $i < 50; $i++) {
            Launch::new(function () use ($i, &$dbResults) {
                $result = dbQuery($i)->wait();
                $dbResults[] = $result;
                if (count($dbResults) <= 3) {
                    echo "DB {$result['id']} completed: {$result['records']} records\n";
                }
            });
        }

        echo "Launched 150 concurrent tasks\n";
    });

    $endTime = microtime(true);
    $endMemory = memory_get_usage(true);

    echo "\n=== PHP Results ===\n";
    echo "Total execution time: " . round($endTime - $startTime, 3) . " seconds\n";
    echo "Memory used: " . round(($endMemory - $startMemory) / 1024 / 1024, 2) . " MB\n";
    echo "Peak memory: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB\n";
    echo "HTTP requests completed: " . count($httpResults) . "\n";
    echo "DB queries completed: " . count($dbResults) . "\n";
});
