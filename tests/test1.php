<?php

require '../vendor/autoload.php';

use vosaka\foroutines\Async;
use vosaka\foroutines\Dispatchers;
use vosaka\foroutines\Launch;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Delay;

// This function simulates an asynchronous
// Dispatchers::IO operation that open new thread
function main(string $str): Async
{
    return Async::new(function () use ($str) {
        yield;
        sleep(2);
        file_put_contents('test.txt', $str);
        return 10;
    }, Dispatchers::IO);
}

$time = microtime(true);

RunBlocking::new(function () {
    Launch::new(function () {
        Delay::new(3);
        var_dump('Async 2 completed');
    });

    Launch::new(function (): Generator {
        Delay::new(1);
        var_dump('Generator 1 completed');
        return yield 20;
    });

    $hello = 'Hello, World!';
    $result = main($hello)->wait();
    var_dump('Result from main:', $result);
});

Delay::new(2);

var_dump('Total execution time:', microtime(true) - $time);
var_dump("Memory usage: " . memory_get_usage(true) / 1024 . 'KB');
