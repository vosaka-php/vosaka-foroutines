# VOsaka Foroutines

A PHP library for structured asynchronous programming using foroutines (fiber + coroutines), inspired by Kotlin coroutines. Built as an improvement over [async-php](https://github.com/terremoth/php-async).

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                        main() entry point                       │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌──────────────┐   ┌──────────────┐   ┌──────────────────┐    │
│  │ RunBlocking   │   │   Launch      │   │     Async        │    │
│  │ (drive loop)  │   │ (fire & wait) │   │ (await result)   │    │
│  └──────┬───────┘   └──────┬───────┘   └──────┬───────────┘    │
│         │                  │                   │                │
│         ▼                  ▼                   ▼                │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │               Cooperative Scheduler Loop                 │    │
│  │  ┌───────────────┬─────────────────┬────────────────┐   │    │
│  │  │ AsyncIO       │  WorkerPool     │  Launch Queue  │   │    │
│  │  │ pollOnce()    │  run()          │  runOnce()     │   │    │
│  │  │ stream_select │  child procs    │  fiber resume  │   │    │
│  │  └───────────────┴─────────────────┴────────────────┘   │    │
│  │                                                         │    │
│  │  Idle detection → usleep(500µs) to prevent CPU spin     │    │
│  └─────────────────────────────────────────────────────────┘    │
│                                                                 │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │                    Dispatchers                            │   │
│  │  DEFAULT: fibers in current process (+ AsyncIO streams)  │   │
│  │  IO:      child process (ForkProcess or symfony/process)  │   │
│  │  MAIN:    EventLoop (deferred scheduling)                 │   │
│  └──────────────────────────────────────────────────────────┘   │
│                                                                 │
│  ┌─────────────┐  ┌─────────────┐  ┌──────────────────────┐    │
│  │  Channel     │  │  Flow (cold) │  │  SharedFlow/StateFlow│    │
│  │  (buffered   │  │  + buffer()  │  │  (hot, backpressure) │    │
│  │   send/recv) │  │  operator    │  │  replay + extraBuf   │    │
│  └─────────────┘  └─────────────┘  └──────────────────────┘    │
│                                                                 │
│  ┌─────────────┐  ┌─────────────┐  ┌──────────────────────┐    │
│  │  Mutex       │  │  Select      │  │  Job lifecycle       │    │
│  │  (multi-proc │  │  (channel    │  │  (cancel, join,      │    │
│  │   file/sem)  │  │   multiplex) │  │   invokeOnComplete)  │    │
│  └─────────────┘  └─────────────┘  └──────────────────────┘    │
└─────────────────────────────────────────────────────────────────┘
```

## Features

### Core
- Structured concurrency with `RunBlocking`, `Launch`, and `Async`
- Dispatchers: `DEFAULT`, `IO` (separate process), `MAIN` (event loop)
- `Delay`, `Repeat`, `WithTimeout`, `WithTimeoutOrNull`
- Job lifecycle management (cancel, join, invokeOnCompletion)

### Communication & Streams
- `Channel` for communication between foroutines (including inter-process)
- `Flow` API: cold Flow, `SharedFlow`, `StateFlow`, `MutableStateFlow`
- `Select` expression for channel multiplexing

### Synchronization
- `Mutex` for multi-process synchronization (file, semaphore, APCu)

### I/O & Concurrency
- **`AsyncIO`** — Non-blocking stream I/O via `stream_select()` (TCP, TLS, files, HTTP, DNS)
- **`ForkProcess`** — Low-overhead child process via `pcntl_fork()` on Linux/macOS, with automatic fallback to `symfony/process` on Windows
- `WorkerPool` for parallel execution with configurable pool size

### Backpressure
- **`BackpressureStrategy`** — `SUSPEND`, `DROP_OLDEST`, `DROP_LATEST`, `ERROR`
- `SharedFlow` / `StateFlow` / `MutableStateFlow` with configurable `extraBufferCapacity` and overflow strategy
- Cold `Flow` with `buffer()` operator for producer/consumer decoupling

## Rules

<img src="https://github.com/vosaka-php/vosaka-foroutines/blob/main/rules.png" alt="Rules" width="800">

## Requirements

- PHP 8.2+
- ext-shmop (required — shared memory for inter-process communication)
- ext-fileinfo
- ext-zlib

### Optional Extensions

| Extension | Purpose |
|---|---|
| ext-pcntl | Enables `ForkProcess` for low-overhead IO dispatch (~1-5ms vs ~50-200ms) |
| ext-sysvsem | Enables semaphore-based `Mutex` |
| ext-apcu | Enables APCu-based `Mutex` |

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

// Run in a separate process (IO dispatcher)
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
| `Dispatchers::DEFAULT` | Runs in the current fiber context (+ AsyncIO for non-blocking streams) |
| `Dispatchers::IO` | Spawns a separate process via ForkProcess/WorkerPool |
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

---

## New Features (v2)

The following features were added to improve real-world async performance, reduce CPU waste, and provide production-grade flow control.

### AsyncIO — Non-blocking Stream I/O

`AsyncIO` provides true non-blocking I/O for sockets, files, and HTTP within the `Dispatchers::DEFAULT` context — no child process needed.

Under the hood it uses `stream_select()` to multiplex across all registered read/write watchers. When a stream becomes ready, the corresponding fiber is resumed automatically by the scheduler.

```php
use vosaka\foroutines\{RunBlocking, Launch, Thread, AsyncIO};
use function vosaka\foroutines\main;

main(function () {
    RunBlocking::new(function () {
        // Non-blocking TCP connection
        Launch::new(function () {
            $socket = AsyncIO::tcpConnect('example.com', 80, timeoutMs: 5000);
            AsyncIO::streamWrite($socket, "GET / HTTP/1.0\r\nHost: example.com\r\n\r\n");
            $response = AsyncIO::streamReadAll($socket);
            fclose($socket);
            var_dump(strlen($response) . ' bytes received');
        });

        // Non-blocking file read (runs concurrently with TCP above)
        Launch::new(function () {
            $content = AsyncIO::fileGetContents('/path/to/file.txt');
            var_dump('File: ' . strlen($content) . ' bytes');
        });

        // Non-blocking DNS resolution
        Launch::new(function () {
            $ip = AsyncIO::dnsResolve('example.com');
            var_dump("Resolved: $ip");
        });

        Thread::wait();
    });
});
```

#### AsyncIO API Reference

| Method | Description |
|---|---|
| `tcpConnect(host, port, timeoutMs)` | Non-blocking TCP connection, returns stream resource |
| `tlsConnect(host, port, timeoutMs)` | Non-blocking TLS/SSL connection |
| `streamRead(stream, length)` | Read up to N bytes, suspends fiber until data ready |
| `streamReadAll(stream, chunkSize)` | Read until EOF, suspends between chunks |
| `streamWrite(stream, data)` | Write data, suspends fiber until stream writable |
| `httpGet(url, headers, timeoutMs)` | Full HTTP GET via non-blocking sockets |
| `httpPost(url, body, headers, timeoutMs)` | Full HTTP POST via non-blocking sockets |
| `fileGetContents(path)` | Read entire file non-blockingly |
| `filePutContents(path, data, flags)` | Write file non-blockingly |
| `dnsResolve(hostname)` | Resolve hostname to IP |
| `createSocketPair()` | Create a connected socket pair (IPC) |
| `pollOnce(timeoutUs)` | Single event-loop tick (called automatically by scheduler) |
| `hasPending()` | Check if any watchers are registered |
| `cancelAll()` | Cancel all pending watchers |

### ForkProcess — Low-overhead Child Processes

On Linux/macOS where `pcntl_fork()` is available, `ForkProcess` creates child processes by forking the current PHP process instead of spawning a new interpreter. This dramatically reduces per-task overhead:

| Strategy | Overhead | Closure Serialization | Autoload Cost |
|---|---|---|---|
| `ForkProcess` (pcntl_fork) | ~1-5ms | Not needed (memory copied) | None (inherited) |
| `Process` (symfony/process) | ~50-200ms | Required (SerializableClosure) | Full bootstrap |

The selection is automatic — `Worker` checks `ForkProcess::isForkAvailable()` and uses fork when possible, falling back to symfony/process on Windows or when pcntl is not loaded.

```php
use vosaka\foroutines\ForkProcess;

// Check platform support
if (ForkProcess::isForkAvailable()) {
    echo "Using pcntl_fork() for IO dispatch\n";
} else {
    echo "Falling back to symfony/process\n";
}

// Direct usage (automatic strategy selection)
$fork = new ForkProcess();
$async = $fork->run(function () {
    // This runs in a child process
    return expensive_computation();
});

$result = $async->wait();
```

#### ForkProcess Architecture

```
Parent Process                        Child Process (forked)
──────────────                        ──────────────────────
pcntl_fork()  ──────────────────────► Inherits full memory
  │                                     │
  │   ┌─── shmop segment ─────┐        │
  │   │   (10MB pre-alloc)    │        │
  │   └────────────────────────┘        │
  │                                   Execute $closure()
  │                                   Serialize result
  │                                   Write to shmop
  │                                   _exit(0)
  │
  ├── waitpid(WNOHANG) in fiber loop
  ├── Read result from shmop
  └── Cleanup shmop segment
```

**Result passing**: Results are serialized and written to a pre-allocated shmop segment. If the result exceeds 10MB, it falls back to a temp file. Errors in the child are captured with full stack traces and re-thrown in the parent.

### Backpressure — Flow Control for Hot Streams

When a producer emits values faster than collectors can consume them, backpressure prevents unbounded memory growth and provides predictable behavior.

#### BackpressureStrategy

```php
use vosaka\foroutines\flow\BackpressureStrategy;
```

| Strategy | Emitter Blocks? | Data Loss? | Use Case |
|---|---|---|---|
| `SUSPEND` | Yes (cooperatively) | No | Default; safe for all scenarios |
| `DROP_OLDEST` | No | Yes (oldest) | Real-time data (sensors, tickers) |
| `DROP_LATEST` | No | Yes (newest) | When "first seen" matters most |
| `ERROR` | Throws | No silent loss | Debug; overflow = programming error |

#### SharedFlow with Backpressure

```php
use vosaka\foroutines\flow\{SharedFlow, BackpressureStrategy};

// SharedFlow with bounded buffer and DROP_OLDEST strategy
$flow = SharedFlow::new(
    replay: 3,                                        // replay last 3 to new collectors
    extraBufferCapacity: 10,                          // 10 extra slots for bursts
    onBufferOverflow: BackpressureStrategy::DROP_OLDEST,
);

// Total buffer = replay (3) + extraBuffer (10) = 13 slots
// When 14th value arrives, the oldest is evicted

$flow->collect(fn($v) => process($v));

for ($i = 0; $i < 100; $i++) {
    $flow->emit($i);  // Never blocks — drops oldest when full
}

// tryEmit — non-blocking, non-throwing alternative
$success = $flow->tryEmit('value');  // returns false if SUSPEND/ERROR would trigger
```

#### Buffer Semantics

```
┌─────────────────────────────┬───────────────────────────────────┐
│     replay (N slots)        │    extraBuffer (M slots)          │
│  replayed to new collectors │  absorbs bursts before            │
│                             │  backpressure activates           │
└─────────────────────────────┴───────────────────────────────────┘
         Total capacity = replay + extraBufferCapacity

When total buffer is full → BackpressureStrategy kicks in
```

#### MutableStateFlow with Backpressure

```php
use vosaka\foroutines\flow\{MutableStateFlow, BackpressureStrategy};

// Simple usage (no backpressure — original behavior)
$state = MutableStateFlow::new(0);
$state->emit(1);
$state->emit(2);

// With backpressure for slow collectors
$state = MutableStateFlow::new(
    initialValue: 0,
    extraBufferCapacity: 16,
    onBufferOverflow: BackpressureStrategy::DROP_OLDEST,
);

// Atomic compare-and-set
do {
    $current = $state->getValue();
    $next = $current + 1;
} while (!$state->compareAndSet($current, $next));

// Non-blocking tryEmit
$ok = $state->tryEmit(42);  // returns false if would block/throw

// Read-only snapshot
$readOnly = $state->asStateFlow();
```

#### Cold Flow with buffer() Operator

The `buffer()` operator inserts an intermediate buffer between a fast producer and a slow collector in cold Flow pipelines:

```php
use vosaka\foroutines\flow\{Flow, BackpressureStrategy};

Flow::of(1, 2, 3, 4, 5, 6, 7, 8, 9, 10)
    ->map(fn($v) => $v * 10)
    ->buffer(
        capacity: 3,
        onOverflow: BackpressureStrategy::DROP_OLDEST,
    )
    ->collect(function ($v) {
        usleep(10000); // slow consumer
        echo "$v\n";
    });

// With filter + buffer pipeline
Flow::fromArray(range(1, 1000))
    ->filter(fn($v) => $v % 2 === 0)
    ->map(fn($v) => $v * $v)
    ->buffer(capacity: 64, onOverflow: BackpressureStrategy::SUSPEND)
    ->collect(fn($v) => process($v));
```

### Scheduler Improvements — Anti CPU-Spin

All scheduler loops (`Thread::wait()`, `RunBlocking::new()`, `Delay::new()`, `Async::wait()`) now include idle detection. When no subsystem has actionable work on a given tick, a `usleep(500)` (500 microseconds) is inserted to prevent 100% CPU usage.

Each scheduler tick drives three subsystems:

1. **`AsyncIO::pollOnce()`** — `stream_select()` across all registered read/write watchers
2. **`WorkerPool::run()`** — spawns/checks child processes for `Dispatchers::IO` tasks
3. **`Launch::runOnce()`** — resumes one queued fiber from the cooperative scheduler

When all three report no work, the scheduler sleeps briefly — similar to how Node.js's libuv uses epoll/kqueue to sleep until an event arrives.

---

## Platform Support

| Feature | Linux/macOS | Windows |
|---|---|---|
| Fibers (core) | ✅ | ✅ |
| AsyncIO (stream_select) | ✅ | ✅ |
| ForkProcess (pcntl_fork) | ✅ | ❌ (fallback to symfony/process) |
| Process (symfony/process) | ✅ | ✅ |
| Mutex (file lock) | ✅ | ✅ |
| Mutex (semaphore) | ✅ (ext-sysvsem) | ❌ |
| Mutex (APCu) | ✅ (ext-apcu) | ✅ (ext-apcu) |
| shmop (shared memory) | ✅ | ✅ |

## Comparison with JavaScript Async

| Aspect | JS (Node.js) | VOsaka Foroutines |
|---|---|---|
| Runtime | libuv event loop (C) | PHP Fibers + stream_select |
| I/O model | All I/O is non-blocking by default | AsyncIO for streams; `Dispatchers::IO` for blocking APIs |
| Concurrency | Single-threaded + worker threads | Single process + child processes (fork/spawn) |
| Scheduler efficiency | epoll/kqueue (OS-level) | stream_select + usleep idle detection |
| Syntax | async/await (language-level) | Fiber-based cooperative (library-level) |
| Flow control | Streams (backpressure built-in) | BackpressureStrategy (SUSPEND/DROP/ERROR) |

**Key insight**: PHP's standard library I/O functions are blocking. VOsaka Foroutines works around this by:
1. Using `stream_select()` for non-blocking socket/stream I/O in `Dispatchers::DEFAULT`
2. Offloading blocking I/O to child processes via `Dispatchers::IO` (with `pcntl_fork()` or `symfony/process`)
3. Cooperative multitasking between fibers via `Pause::new()` / `Fiber::suspend()`

## License

MIT
