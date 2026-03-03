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
│  ┌──────────────────────────────────────────────────────────┐   │
│  │                    Channel (4 transports)                 │   │
│  │  IN-PROCESS:  fiber ←→ fiber (in-memory array buffer)    │   │
│  │  SOCKET POOL: Channel::create() → ChannelBrokerPool      │   │
│  │               N channels share 1 background process       │   │
│  │               (default — lazily booted on first create)   │   │
│  │  SOCKET IPC:  newSocketInterProcess() → ChannelBroker     │   │
│  │               1 process per channel (legacy, opt-in)      │   │
│  │  FILE IPC:    newInterProcess() → temp file + Mutex       │   │
│  │  + Channels utils: merge, map, filter, zip, range, timer │   │
│  └──────────────────────────────────────────────────────────┘   │
│                                                                 │
│  ┌─────────────┐  ┌─────────────┐  ┌──────────────────────┐    │
│  │  Flow (cold) │  │ SharedFlow / │  │  ChannelBrokerPool   │    │
│  │  + buffer()  │  │ StateFlow    │  │  (single TCP server  │    │
│  │  operator    │  │ (hot, back-  │  │   hosting N channels │    │
│  │              │  │  pressure)   │  │   in one process)    │    │
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
- **`Channel`** — four transport modes:
  - **In-process** (`Channel::new()`) — fiber-to-fiber via in-memory buffer
  - **Inter-process / socket pool** (`Channel::create()`) — shared `ChannelBrokerPool` process hosting N channels (default, recommended)
  - **Inter-process / socket** (`Channel::newSocketInterProcess()`) — dedicated per-channel `ChannelBroker` process (legacy, opt-in via `Channel::disablePool()`)
  - **Inter-process / file** (`Channel::newInterProcess()`) — temp file + mutex
- **Pool mode enabled by default** — `Channel::create()` lazily boots a single `ChannelBrokerPool` process; all channels share it (N channels → 1 process)
- `Channel` is serializable — works with `SerializableClosure` on Windows
- `Channel::create()` + `$ch->connect()` for seamless child-process reconnection (pool-aware with `PING`/`POOL_PONG` probe)
- **`Channels`** utility: `merge`, `map`, `filter`, `take`, `zip`, `range`, `timer`, `from`
- **`ChannelIterator`** — `foreach` support on any Channel
- `Flow` API: cold Flow, `SharedFlow`, `StateFlow`, `MutableStateFlow`
- `Select` expression for channel multiplexing

### Synchronization
- `Mutex` for multi-process synchronization (file, semaphore, APCu)

### I/O & Concurrency
- **`AsyncIO`** — Non-blocking stream I/O via `stream_select()` (TCP, TLS, files, HTTP, DNS) with explicit `->await()` pattern
- **`AsyncIOOperation`** — Lazy deferred wrapper returned by all `AsyncIO` I/O methods; executes on `->await()`
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

        Thread::await();
    });
});
```

### Async / Await

```php
use vosaka\foroutines\{Async, Dispatchers};

$result = Async::new(function () {
    Delay::new(100);
    return 42;
})->await(); // blocks until result is ready

// Run in a separate process (IO dispatcher)
$io = Async::new(function () {
    return file_get_contents('data.txt');
}, Dispatchers::IO)->await();
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

Channel supports four transport modes:

| Mode | Factory | Use Case |
|---|---|---|
| **In-process** | `Channel::new(capacity)` | Fibers in the same process |
| **Inter-process (socket pool)** | `Channel::create(capacity)` | Multiple OS processes via shared `ChannelBrokerPool` (default, recommended) |
| **Inter-process (socket)** | `Channel::newSocketInterProcess(name, capacity)` | Dedicated per-channel broker (legacy — opt-in via `Channel::disablePool()`) |
| **Inter-process (file)** | `Channel::newInterProcess(name, capacity)` | Multiple OS processes via temp file + mutex |

> **Pool mode is enabled by default.** `Channel::create()` lazily boots a single `ChannelBrokerPool` background process and creates all channels inside it. N channels = 1 process. Call `Channel::disablePool()` to revert to the legacy per-channel broker behavior.

#### In-process Channel (fibers only)

```php
use vosaka\foroutines\channel\Channel;

$ch = Channel::new(capacity: 2);
$ch->send('hello');
$ch->send('world');

var_dump($ch->receive()); // "hello"
var_dump($ch->receive()); // "world"

$ch->close();
```

#### Inter-process Channel (socket pool — default, recommended)

`Channel::create()` uses a shared `ChannelBrokerPool` process that hosts multiple channels in a single background process over TCP loopback. The pool is lazily booted on the first `create()` call. Child processes reconnect with `$ch->connect()` — no arguments needed.

```php
use vosaka\foroutines\channel\Channel;
use vosaka\foroutines\{RunBlocking, Launch, Async, Dispatchers, Thread};
use function vosaka\foroutines\main;

main(function () {
    // Pool is enabled by default — all channels share one process
    $ch1 = Channel::create(5);         // buffered, capacity 5 (boots pool)
    $ch2 = Channel::create(10);        // same pool process
    $ch3 = Channel::create();          // unbounded, same pool process

    RunBlocking::new(function () use ($ch1) {
        // Send from IO dispatcher (child process)
        Launch::new(function () use ($ch1) {
            $ch1->connect();           // reconnect in child — pool-aware
            $ch1->send('from child 1');
            $ch1->send('from child 2');
        }, Dispatchers::IO);

        // Receive in parent
        Launch::new(function () use ($ch1) {
            var_dump($ch1->receive()); // "from child 1"
            var_dump($ch1->receive()); // "from child 2"
        });

        Thread::await();
        $ch1->close();
        $ch1->cleanup();
    });

    // Pool is automatically shut down at script exit
    // Or manually: Channel::shutdownPool();
});
```

#### Pool Management API

```php
use vosaka\foroutines\channel\Channel;
use vosaka\foroutines\channel\Channels;

// Pool is enabled by default — these are optional:
Channel::enablePool();              // idempotent; eagerly boot pool
Channel::isPoolEnabled();           // true
Channel::getPoolPort();             // pool TCP port (or null if not booted)

// Create channels — all share the same pool process
$ch1 = Channel::create(5);
$ch2 = Channel::create(10);
$ch3 = Channel::createPooled('my_channel', 5); // explicit name

// Disable pool mode — future create() calls spawn per-channel brokers
Channel::disablePool();
$legacy = Channel::create(5);       // uses dedicated ChannelBroker process

// Re-enable pool mode
Channel::enablePool();

// Shut down the pool process (all pool-hosted channels are closed)
Channel::shutdownPool();            // pool remains enabled; next create() reboots it

// Facade via Channels utility class
Channels::enablePool();
Channels::createPooled(5, 'named_ch');
Channels::shutdownPool();
```

#### Legacy per-channel broker (opt-in)

If you need the original one-process-per-channel behavior, disable pool mode:

```php
Channel::disablePool();

$ch = Channel::create(5);             // spawns a dedicated ChannelBroker process
// Or use the explicit factory:
$ch = Channel::newSocketInterProcess('my_channel', 5);
```

#### trySend / tryReceive (non-blocking)

```php
$ch = Channel::create(1);

$ok = $ch->trySend(42);         // true — buffer had space
$ok = $ch->trySend(99);         // false — buffer full (capacity 1)

$val = $ch->tryReceive();       // 42
$val = $ch->tryReceive();       // null — buffer empty
```

#### Channel Serialization

Channels created with `Channel::create()` are serializable — they work with `SerializableClosure` on Windows where `pcntl_fork()` is unavailable:

```php
$ch = Channel::create(5);

// Serialize → unserialize round-trip (auto-reconnects)
$serialized = serialize($ch);
$ch2 = unserialize($serialized);
$ch2->send('hello from unserialized');

$val = $ch->receive(); // "hello from unserialized"
```

#### Channel Iterator (foreach)

Channels implement `IteratorAggregate` — you can iterate with `foreach`:

```php
$ch = Channel::new(3);
$ch->send('a');
$ch->send('b');
$ch->send('c');
$ch->close();

foreach ($ch as $value) {
    var_dump($value); // "a", "b", "c"
}
```

#### Channels Utility Class

The `Channels` class provides functional operators and factory helpers:

```php
use vosaka\foroutines\channel\Channels;

// Create channels
$ch = Channels::create(5);                  // socket-based inter-process
$ch = Channels::createBuffered(10);         // in-process buffered
$ch = Channels::from([1, 2, 3, 4, 5]);     // pre-filled channel

// Functional operators (return new Channel)
$doubled = Channels::map($ch, fn($v) => $v * 2);
$evens   = Channels::filter($ch, fn($v) => $v % 2 === 0);
$first3  = Channels::take($ch, 3);
$merged  = Channels::merge($ch1, $ch2, $ch3);
$zipped  = Channels::zip($ch1, $ch2);

// Generator channels
$nums  = Channels::range(1, 100);           // sends 1..100
$ticks = Channels::timer(500, maxTicks: 10); // sends microtime every 500ms
```

#### Socket Transport Architecture

**Pool mode (default)** — N channels share 1 process:

```
Parent Process                      ChannelBrokerPool (background)
──────────────                      ──────────────────────────────
Channel::create(5)                  Single TCP server on 127.0.0.1
  └─ boots pool (lazy) ─────────►  listen(0) → ephemeral port
  ← READY:<port>                    │
  └─ CREATE_CHANNEL:ch1:5 ──────►  ┌──────────────────────────┐
  ← CREATED:<port>                  │  ch1: in-memory buffer   │
                                    │  ch2: in-memory buffer   │
Channel::create(10)                 │  ch3: in-memory buffer   │
  └─ CREATE_CHANNEL:ch2:10 ─────►  │  ...N channels           │
  ← CREATED:<port>                  └──────────────────────────┘
                                           ▲
  CH:ch1:SEND:data ─── TCP ──────►         │
  CH:ch1:RECV       ◄── TCP ──────         │
                                           │
Child Process (fork / IO)                  │
──────────────────────────                 │
$ch->connect()  ─── TCP ──────────────────┘
  └─ PING → POOL_PONG (auto-detect pool)
  └─ reconnects by cached port + pool mode
  CH:ch1:SEND:data ─── TCP ──────►
```

**Legacy mode** (per-channel broker — opt-in via `Channel::disablePool()`):

```
Parent Process                      ChannelBroker (background)
──────────────                      ──────────────────────────
Channel::create(5)
  └─ spawns broker ──────────────► listen(0) on 127.0.0.1
  ← READY:<port>  ────────────────  ephemeral TCP port
  └─ ChannelSocketClient           │
     connects to port              ▼
                              ┌──────────────────┐
  send('hello') ─── TCP ───► │  in-memory buffer │
  receive()     ◄── TCP ──── │  (capacity = 5)   │
                              └──────────────────┘
                                    ▲
Child Process (fork / IO)           │
──────────────────────────          │
$ch->connect()  ─── TCP ───────────┘
  └─ reconnects by cached port
  send('world') ─── TCP ──────────►
```

| Transport | Overhead | Processes | Serialization | Blocking I/O | Windows |
|---|---|---|---|---|---|
| Socket pool (default) | Low (~TCP loopback) | 1 process for N channels | Not needed (in-memory) | Event-driven (no spin-wait) | ✅ |
| Socket (legacy broker) | Low (~TCP loopback) | 1 process per channel | Not needed (in-memory) | Event-driven (no spin-wait) | ✅ |
| File (mutex) | Higher (file I/O + mutex) | N/A | Full buffer on every op | Spin-wait polling | ✅ |
| In-process | Lowest (array) | N/A | N/A | Fiber suspend/resume | ✅ |

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

    Thread::await();
});
```

### WorkerPool

```php
use vosaka\foroutines\WorkerPool;

WorkerPool::setPoolSize(8);

$async = WorkerPool::addAsync(function () {
    return 'processed';
});

$result = $async->await();
```

---

## New Features (v2)

The following features were added to improve real-world async performance, reduce CPU waste, provide production-grade flow control, and enable robust inter-process communication.

### Channel Rewrite — Four Transport Modes

The Channel system was completely rewritten for v2 with a clean separation of concerns, and further improved with **pool mode** — a single background process hosting multiple channels:

| Class | Responsibility |
|---|---|
| `Channel` | Unified public API — delegates to the correct transport |
| `ChannelBrokerPool` | **Single TCP server managing N channels in-memory (default)** |
| `ChannelBroker` | Per-channel TCP server (legacy — used when pool is disabled) |
| `ChannelSocketClient` | TCP client connecting to a `ChannelBrokerPool` or `ChannelBroker` |
| `ChannelFileTransport` | File-based transport with Mutex synchronization |
| `ChannelSerializer` | Multi-backend serialization (serialize, JSON, msgpack, igbinary) |
| `ChannelIterator` | `foreach` support via `IteratorAggregate` |
| `Channels` | Utility class with functional operators and factory methods |

**Key improvements over v1:**
- **Pool mode by default** — `Channel::create()` lazily boots a shared `ChannelBrokerPool`; N channels share 1 background process instead of N processes
- **`Channel::create()`** — one-line factory that creates a pool-backed channel (or per-channel broker if pool is disabled)
- **`Channel::createPooled()`** — explicit pool factory with optional channel name
- **`Channel::enablePool()` / `disablePool()` / `shutdownPool()`** — global pool lifecycle control
- **`$ch->connect()`** — zero-argument reconnection in child processes (pool-aware with `PING`/`POOL_PONG` auto-detection)
- **Serializable channels** — `Channel` implements `__serialize` / `__unserialize` for `SerializableClosure` compatibility; pool mode is preserved across serialization
- **No port file** — the broker communicates the port via STDOUT (`READY:<port>`), eliminating file-based port discovery
- **Parent-death detection** — broker monitors STDIN for EOF and shuts down automatically when the parent dies
- **Idle timeout** — broker auto-shuts down after configurable inactivity (default: 300s)
- **Multiple serializers** — `serialize` (default), `json`, `msgpack`, `igbinary`
- **~4–6x faster channel creation** — creating channels inside a pool avoids process spawn overhead

#### Channel API Reference

**Factory methods:**

| Method | Returns | Description |
|---|---|---|
| `Channel::new(capacity)` | `Channel` | In-process channel (fibers only) |
| `Channel::create(capacity, readTimeout, idleTimeout)` | `Channel` | Pool-backed IPC channel (default) or per-channel broker (if pool disabled) |
| `Channel::createPooled(name, capacity, readTimeout)` | `Channel` | Explicitly create a pool-backed channel (always uses pool) |
| `Channel::newInterProcess(name, capacity, serializer)` | `Channel` | File-based IPC channel |
| `Channel::newSocketInterProcess(name, capacity)` | `Channel` | Per-channel broker socket IPC (legacy) |
| `Channel::connectByName(name)` | `Channel` | Connect to existing file-based channel |
| `Channel::connectSocketByPort(name, port)` | `Channel` | Connect to existing socket channel by port (auto-detects pool vs broker) |
| `Channels::create(capacity)` | `Channel` | Facade for `Channel::create()` |
| `Channels::createPooled(capacity, name)` | `Channel` | Facade for `Channel::createPooled()` |
| `Channels::createBuffered(capacity)` | `Channel` | Facade for `Channel::new()` with capacity > 0 |
| `Channels::from(array)` | `Channel` | Pre-filled in-process channel |

**Pool management (static):**

| Method | Returns | Description |
|---|---|---|
| `Channel::enablePool(readTimeout, idleTimeout)` | `void` | Enable pool mode globally (default; eagerly boots pool if not running) |
| `Channel::disablePool()` | `void` | Disable pool mode — future `create()` calls spawn per-channel brokers |
| `Channel::isPoolEnabled()` | `bool` | Check if pool mode is enabled |
| `Channel::getPoolPort()` | `?int` | Get the pool TCP port (null if not booted) |
| `Channel::shutdownPool()` | `void` | Shut down the pool process; pool mode stays enabled for lazy reboot |
| `Channels::enablePool()` | `void` | Facade for `Channel::enablePool()` |
| `Channels::disablePool()` | `void` | Facade for `Channel::disablePool()` |
| `Channels::isPoolEnabled()` | `bool` | Facade for `Channel::isPoolEnabled()` |
| `Channels::getPoolPort()` | `?int` | Facade for `Channel::getPoolPort()` |
| `Channels::shutdownPool()` | `void` | Facade for `Channel::shutdownPool()` |

**Instance methods:**

| Method | Returns | Description |
|---|---|---|
| `send(value)` | `void` | Blocking send (suspends fiber if buffer full) |
| `receive()` | `mixed` | Blocking receive (suspends fiber if buffer empty) |
| `trySend(value)` | `bool` | Non-blocking send — returns `false` if full |
| `tryReceive()` | `mixed` | Non-blocking receive — returns `null` if empty |
| `close()` | `void` | Close the channel |
| `connect()` | `self` | Reconnect in child process (socket/file transport) |
| `cleanup()` | `void` | Release all resources (broker shutdown if owner) |
| `isClosed()` | `bool` | Check if channel is closed |
| `isEmpty()` | `bool` | Check if buffer is empty |
| `isFull()` | `bool` | Check if buffer is full |
| `size()` | `int` | Current buffer size |
| `getInfo()` | `array` | Detailed channel state info |
| `getName()` | `?string` | Channel name (IPC channels only) |
| `getSocketPort()` | `?int` | Broker/pool TCP port (socket transport only) |
| `getTransport()` | `?string` | `"socket_pool"`, `"socket"`, `"file"`, or `null` (in-process) |
| `isPoolMode()` | `bool` | Whether this channel uses the shared pool |

**Channels utility operators:**

| Method | Description |
|---|---|
| `Channels::merge(...$channels)` | Merge multiple channels into one |
| `Channels::map($ch, $fn)` | Transform each value |
| `Channels::filter($ch, $fn)` | Filter values by predicate |
| `Channels::take($ch, $n)` | Take first N values |
| `Channels::zip(...$channels)` | Zip values from multiple channels |
| `Channels::range($start, $end, $step)` | Generate a range of numbers |
| `Channels::timer($intervalMs, $maxTicks)` | Emit timestamps at intervals |

### AsyncIO — Non-blocking Stream I/O

`AsyncIO` provides true non-blocking I/O for sockets, files, and HTTP within the `Dispatchers::DEFAULT` context — no child process needed.

Under the hood it uses `stream_select()` to multiplex across all registered read/write watchers. When a stream becomes ready, the corresponding fiber is resumed automatically by the scheduler.

All public I/O methods return an `AsyncIOOperation` instance — a lightweight lazy wrapper. The actual work is **deferred** until you call `->await()`, making every async call explicit and consistent:

```php
$body   = AsyncIO::httpGet('https://example.com')->await();
$data   = AsyncIO::fileGetContents('/path/to/file')->await();
$socket = AsyncIO::tcpConnect('example.com', 80)->await();
```

#### AsyncIOOperation — Deferred Await Pattern

`AsyncIOOperation` is a thin wrapper returned by every `AsyncIO` I/O method. It holds the operation callable but does **not** execute it until `->await()` is called:

```php
// This does NOT execute yet — it only creates the deferred operation
$op = AsyncIO::fileGetContents('/path/to/file');

// The actual I/O happens here
$content = $op->await();
```

**How `->await()` works in different contexts:**

| Context | Behavior |
|---|---|
| Inside a Fiber (`Launch::new`, `Async::new`) | Executes directly in the current Fiber — `waitForRead`/`waitForWrite` suspensions integrate with the scheduler's `pollOnce()` |
| Outside a Fiber (top-level code) | Wraps in `Async::new()` internally, creating a dedicated Fiber with a full scheduler loop |

This design ensures you always see `->await()` at the call site, making it immediately clear that the code is performing an async operation — similar to `await` in JavaScript/Kotlin.

#### AsyncIO Usage

```php
use vosaka\foroutines\{RunBlocking, Launch, Thread, AsyncIO};
use function vosaka\foroutines\main;

main(function () {
    RunBlocking::new(function () {
        // Non-blocking TCP connection
        Launch::new(function () {
            $socket = AsyncIO::tcpConnect('example.com', 80)->await();
            AsyncIO::streamWrite($socket, "GET / HTTP/1.0\r\nHost: example.com\r\n\r\n")->await();
            $response = AsyncIO::streamReadAll($socket)->await();
            fclose($socket);
            var_dump(strlen($response) . ' bytes received');
        });

        // Non-blocking file read (runs concurrently with TCP above)
        Launch::new(function () {
            $content = AsyncIO::fileGetContents('/path/to/file.txt')->await();
            var_dump('File: ' . strlen($content) . ' bytes');
        });

        // Non-blocking DNS resolution
        Launch::new(function () {
            $ip = AsyncIO::dnsResolve('example.com')->await();
            var_dump("Resolved: $ip");
        });

        // Write then read back
        Launch::new(function () {
            AsyncIO::filePutContents('/tmp/output.txt', 'hello world')->await();
            $data = AsyncIO::fileGetContents('/tmp/output.txt')->await();
            var_dump($data); // "hello world"
        });

        Thread::await();
    });
});
```

#### AsyncIO API Reference

All I/O methods return `AsyncIOOperation`. Call `->await()` to execute and get the result.

| Method | Returns (via `->await()`) | Description |
|---|---|---|
| `tcpConnect(host, port, timeout)->await()` | `resource` | Non-blocking TCP connection |
| `tlsConnect(host, port, timeout)->await()` | `resource` | Non-blocking TLS/SSL connection |
| `streamRead(stream, maxBytes, timeout)->await()` | `string` | Read up to N bytes, suspends until data ready |
| `streamReadAll(stream, timeout)->await()` | `string` | Read until EOF, suspends between chunks |
| `streamWrite(stream, data, timeout)->await()` | `int` | Write data, suspends until stream writable |
| `httpGet(url, headers, timeout)->await()` | `string` | Full HTTP GET via non-blocking sockets |
| `httpPost(url, body, headers, contentType, timeout)->await()` | `string` | Full HTTP POST via non-blocking sockets |
| `fileGetContents(path)->await()` | `string` | Read entire file non-blockingly |
| `filePutContents(path, data, flags)->await()` | `int` | Write file non-blockingly |
| `dnsResolve(hostname)->await()` | `string` | Resolve hostname to IP |
| `createSocketPair()->await()` | `array{resource, resource}` | Create a connected socket pair (IPC) |

**Scheduler methods** (called automatically, no `->await()` needed):

| Method | Description |
|---|---|
| `pollOnce()` | Single event-loop tick via `stream_select()` |
| `hasPending()` | Check if any watchers are registered |
| `pendingCount()` | Number of pending watchers |
| `cancelAll()` | Cancel all pending watchers |
| `resetState()` | Clear all watchers (used after fork) |

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

$result = $async->await();
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

All scheduler loops (`Thread::await()`, `RunBlocking::new()`, `Delay::new()`, `Async::await()`) now include idle detection. When no subsystem has actionable work on a given tick, a `usleep(500)` (500 microseconds) is inserted to prevent 100% CPU usage.

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
| Channel (in-process) | ✅ | ✅ |
| Channel (socket pool — default) | ✅ | ✅ |
| Channel (socket per-channel broker) | ✅ | ✅ |
| Channel (file transport) | ✅ | ✅ |
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
| I/O model | All I/O is non-blocking by default | `AsyncIO` for streams; `Dispatchers::IO` for blocking APIs |
| Concurrency | Single-threaded + worker threads | Single process + child processes (fork/spawn) |
| Scheduler efficiency | epoll/kqueue (OS-level) | stream_select + usleep idle detection |
| Syntax | `async/await` (language-level) | `AsyncIO::method()->await()` / `Async::new()->await()` (library-level) |
| Deferred execution | Promises are eager | `AsyncIOOperation` is lazy (deferred until `->await()`) |
| Flow control | Streams (backpressure built-in) | BackpressureStrategy (SUSPEND/DROP/ERROR) |
| IPC channels | Worker threads + MessagePort | `Channel::create()` + shared TCP pool / per-channel broker / file transport |

**Key insight**: PHP's standard library I/O functions are blocking. VOsaka Foroutines works around this by:
1. Using `stream_select()` for non-blocking socket/stream I/O in `Dispatchers::DEFAULT` via `AsyncIO::method()->await()`
2. Offloading blocking I/O to child processes via `Dispatchers::IO` (with `pcntl_fork()` or `symfony/process`)
3. Cooperative multitasking between fibers via `Pause::new()` / `Fiber::suspend()`
4. `Channel::create()` for zero-config inter-process communication via a shared `ChannelBrokerPool` (N channels → 1 process)

## License

GNU Lesser General Public License v2.1
