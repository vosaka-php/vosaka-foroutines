<?php

require __DIR__ . '/../vendor/autoload.php';

use vosaka\foroutines\Async;
use vosaka\foroutines\Dispatchers;
use vosaka\foroutines\Launch;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Delay;
use vosaka\foroutines\Repeat;
use vosaka\foroutines\Thread;
use vosaka\foroutines\WithTimeout;

use vosaka\foroutines\AsyncMain;

// This function simulates an asynchronous
// Dispatchers::IO operation that open new thread
function work(string $str): Async
{
    return Async::new(function () use ($str) {
        Delay::new(0); // Yield cooperatively
        sleep(2);
        file_put_contents("test.txt", $str);
        return 10;
    }, Dispatchers::IO);
}

// Must be run in the main thread
// If you dont make this check, the code IO will cause memory leak
#[AsyncMain]
function main()
{
    $time = microtime(true);

    RunBlocking::new(function () {
        Launch::new(function () {
            sleep(2);
            Delay::new(3000);
            var_dump("Async 2 completed");

            Launch::new(fn() => file_put_contents("test1.txt", "BBB"));
        }, Dispatchers::IO);

        Launch::new(function (): void {
            Launch::new(function () {
                Launch::new(function () {
                    file_put_contents("test2.txt", "BBB");
                    Launch::new(function () {
                        file_put_contents("test2.txt", "BBB");
                    }, Dispatchers::IO);
                }, Dispatchers::IO);
            }, Dispatchers::IO);
            sleep(2);
            var_dump("Generator 1 completed");
            Delay::new(0); // Yield cooperatively
        }, Dispatchers::IO);

        Repeat::new(5, function () {
            var_dump("Repeat function executed");
        });

        WithTimeout::new(1500, function () {
            Delay::new(1000);
            var_dump("Timeout reached");
        });

        $hello = "Hello, World!";
        $result = work($hello)->await();
        var_dump("Result from main:", $result);

        file_put_contents("tests.txt", "Hello, World! from main");

        Thread::await();
    }, Dispatchers::IO);

    Thread::await();

    var_dump("Total execution time:", microtime(true) - $time);
    var_dump("Memory usage: " . memory_get_usage(true) / 1024 . "KB");
}
