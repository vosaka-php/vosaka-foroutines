# VOsaka Foroutines

A PHP library for structured asynchronous programming using foroutines (fiber + coroutines), inspired by Kotlin coroutines. Built as an improvement over [async-php](https://github.com/terremoth/php-async).

## Features

- Structured concurrency with RunBlocking, Launch, and Async
- Dispatchers: DEFAULT, IO (separate process), MAIN (event loop)
- Delay, Repeat, WithTimeout, WithTimeoutOrNull
- Job lifecycle management (cancel, join, invokeOnCompletion)
- Channel for communication between foroutines (including inter-process)
- Flow API: cold Flow, SharedFlow, StateFlow, MutableStateFlow
- Select expression for channel multiplexing
- Mutex for multi-process synchronization
- WorkerPool for parallel execution

## Rules

<img src="https://github.com/vosaka-php/vosaka-foroutines/blob/main/rules.png" alt="Rules" width="800">

## Requirements

- PHP 8.1+
- ext-shmop
- ext-fileinfo
- ext-zlib

## Installation

```
composer require venndev/vosaka-fourotines
```

## Usage

All entry points must be wrapped in `main()` to prevent issues with IO dispatchers.

### Basic: RunBlocking + Launch

```php
<?php

require 'vendor/autoload.php';

use vosaka\foroutines\{RunBlocking, Launch, Delay, Thread};
use function vosaka\foroutines\main;

main(function () {
    RunBlocking::new(function () {
        Launch::new(function () {
            Delay::new(1000);
            var_dump('Task 1 done');
        });

        Launch::new(function () {
            Delay::new(500);
            var_dump('Task 2 done');
        });

        Thread::wait();
    });
});
```

### Async / Await

```php
use vosaka\foroutines\{Async, Dispatchers};

$result = Async::new(function () {
    Delay::new(100);
    return 42;
})->wait(); // blocks until result is ready

// Run in a separate process
$io = Async::new(function () {
    return file_get_contents('data.txt');
}, Dispatchers::IO)->wait();
```

### WithTimeout

```php
use vosaka\foroutines\{WithTimeout, WithTimeoutOrNull, Delay};

// Throws RuntimeException if exceeded
$val = WithTimeout::new(2000, function () {
    Delay::new(1000);
    return 'ok';
});

// Returns null instead of throwing
$val = WithTimeoutOrNull::new(500, function () {
    Delay::new(3000);
    return 'too slow';
});
```

### Repeat

```php
use vosaka\foroutines\Repeat;

Repeat::new(3, function () {
    var_dump('executed');
});
```

### Job Lifecycle

```php
use vosaka\foroutines\Launch;

$job = Launch::new(function () {
    Delay::new(5000);
    return 'done';
});

$job->invokeOnCompletion(function ($j) {
    var_dump('Job finished: ' . $j->getStatus()->name);
});

$job->cancelAfter(2.0); // cancel after 2 seconds
```

### Channel

```php
use vosaka\foroutines\channel\Channel;

$ch = Channel::new(capacity: 2);
$ch->send('hello');
$ch->send('world');

var_dump($ch->receive()); // "hello"
var_dump($ch->receive()); // "world"

$ch->close();
```

### Select

```php
use vosaka\foroutines\channel\Channel;
use vosaka\foroutines\selects\Select;

$ch1 = Channel::new(1);
$ch2 = Channel::new(1);
$ch1->send('from ch1');

$result = (new Select())
    ->onReceive($ch1, fn($v) => "Got: $v")
    ->onReceive($ch2, fn($v) => "Got: $v")
    ->default('nothing ready')
    ->execute();
```

### Flow

```php
use vosaka\foroutines\flow\Flow;

Flow::of(1, 2, 3, 4, 5)
    ->filter(fn($v) => $v % 2 === 0)
    ->map(fn($v) => $v * 10)
    ->collect(fn($v) => var_dump($v)); // 20, 40
```

### StateFlow / SharedFlow

```php
use vosaka\foroutines\flow\{MutableStateFlow, SharedFlow};

// StateFlow: holds current value, emits on change
$state = MutableStateFlow::new(0);
$state->collect(fn($v) => var_dump("State: $v"));
$state->emit(1);
$state->emit(2);

// SharedFlow: hot stream, multiple collectors
$shared = SharedFlow::new(replay: 1);
$shared->collect(fn($v) => var_dump("A: $v"));
$shared->emit('event');
```

### Mutex

```php
use vosaka\foroutines\sync\Mutex;

// Quick protection
Mutex::protect('my-resource', function () {
    file_put_contents('shared.txt', 'safe write');
});

// Manual control
$mutex = Mutex::create('my-lock');
$mutex->acquire();
// critical section
$mutex->release();
```

### Dispatchers

| Dispatcher | Description |
|---|---|
| `Dispatchers::DEFAULT` | Runs in the current fiber context |
| `Dispatchers::IO` | Spawns a separate process via WorkerPool |
| `Dispatchers::MAIN` | Schedules on the main event loop |

```php
use vosaka\foroutines\{RunBlocking, Launch, Async, Dispatchers, Thread};

RunBlocking::new(function () {
    Launch::new(function () {
        return heavy_io_work();
    }, Dispatchers::IO);

    Thread::wait();
});
```

### WorkerPool

```php
use vosaka\foroutines\WorkerPool;

WorkerPool::setPoolSize(8);

$async = WorkerPool::addAsync(function () {
    return 'processed';
});

$result = $async->wait();
```

## License

MIT