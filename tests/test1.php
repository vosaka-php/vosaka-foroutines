<?php

require '../vendor/autoload.php';

use vosaka\foroutines\Async;
use vosaka\foroutines\Dispatchers;
use vosaka\foroutines\Launch;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Delay;
use vosaka\foroutines\Repeat;
use vosaka\foroutines\WithTimeout;

// This function simulates an asynchronous
// Dispatchers::IO operation that open new thread
function work(string $str): Async
{
    return Async::new(function () use ($str) {
        yield;
        sleep(2);
        file_put_contents('test.txt', $str);
        return 10;
    }, Dispatchers::IO);
}

// Must be run in the main thread
// If you dont make this check, the code IO will cause memory leak
if (__FILE__ === realpath($_SERVER['SCRIPT_FILENAME'])) {
    $time = microtime(true);

    RunBlocking::new(function () {
        Launch::new(function () {
            Delay::new(3000);
            var_dump('Async 2 completed');
        });

        Launch::new(function (): Generator {
            Delay::new(1000);
            var_dump('Generator 1 completed');
            return yield 20;
        });

        Repeat::new(5, function () {
            var_dump('Repeat function executed');
        });

        WithTimeout::new(1500, function () {
            Delay::new(1000);
            var_dump('Timeout reached');
        });

        $hello = 'Hello, World!';
        $result = work($hello)->wait();
        var_dump('Result from main:', $result);

        file_put_contents('tests.txt', 'Hello, World! from main');
    }, Dispatchers::IO);

    Delay::new(10000);

    var_dump('Total execution time:', microtime(true) - $time);
    var_dump("Memory usage: " . memory_get_usage(true) / 1024 . 'KB');
}
