# VOsaka Foroutines
This is a library for writing asynchronous code in a more structured way, using the concept of "foroutines" (fiber + coroutines).
This is further improvements to the library [async-php](https://github.com/terremoth/php-async)

# Features
- **Structured Concurrency**: Foroutines allow you to write asynchronous code that is easier to reason about and maintain.
- **Error Handling**: Foroutines provide a way to handle errors in a structured manner, making it easier to manage exceptions in asynchronous code.
- **Cancellation**: Foroutines support cancellation, allowing you to cancel long-running operations gracefully.
- **Resource Management**: Foroutines help manage resources automatically, ensuring that resources are cleaned up properly when they are no longer needed.
- **Dispatchers**: Foroutines can be dispatched to different threads or execution contexts, allowing you to control where your asynchronous code runs.
- **Combined with VOsaka**: This library is designed to work seamlessly with the VOsaka library, providing a powerful toolset for building asynchronous applications.

# Why?
In the main VOsaka version you can see simple syntax and memory optimization through asynchronous processing based on VOsaka's Generator. This library provides asynchrony like Kotlin so you can easily control area segments in a piece of code. Although the syntax may be more difficult, the effect it brings is promising.

# Rules for working with this library!
<img src="https://github.com/vosaka-php/vosaka-foroutines/blob/main/rules.png" alt="Async PHP" width="800">

# Requirements
- PHP 8.1 or higher
- ext-shmop
- ext-fileinfo
- ext-zlib

# Installation
You can install the library using Composer. Run the following command in your terminal:

```bash
composer require venndev/vosaka-fourotines
```

# Example
```php
<?php

require '../vendor/autoload.php';

use vosaka\foroutines\Async;
use vosaka\foroutines\Dispatchers;
use vosaka\foroutines\Launch;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Delay;
use vosaka\foroutines\Repeat;
use vosaka\foroutines\Thread;
use vosaka\foroutines\WithTimeout;

use function vosaka\foroutines\main;

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
main(function () {
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

        Thread::wait();
    }, Dispatchers::IO);

    Thread::wait();

    var_dump('Total execution time:', microtime(true) - $time);
    var_dump("Memory usage: " . memory_get_usage(true) / 1024 . 'KB');
});
```
