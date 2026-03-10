# VOsaka Foroutines

A PHP library for structured asynchronous programming using foroutines (fiber + coroutines), inspired by Kotlin coroutines.
This is project with the contribution of a project from [php-async](https://github.com/terremoth/php-async)

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
│  │  FiberPool: reusable Fiber instances (default: 10)      │    │
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
│  │  SOCKET IPC:  newSocketInterProcess() → ChannelBroker     │   │
│  │  FILE IPC:    newInterProcess() → temp file + Mutex       │   │
│  └──────────────────────────────────────────────────────────┘   │
│                                                                 │
│  ┌─────────────┐  ┌─────────────┐  ┌──────────────────────┐    │
│  │  Flow (cold) │  │ SharedFlow / │  │  WorkerPool          │    │
│  │  + buffer()  │  │ StateFlow    │  │  (task batching +    │    │
│  │  operator    │  │ (hot, back-  │  │   dynamic scaling +  │    │
│  │              │  │  pressure)   │  │   respawn backoff)   │    │
│  └─────────────┘  └─────────────┘  └──────────────────────┘    │
│                                                                 │
│  ┌─────────────┐  ┌─────────────┐  ┌──────────────────────┐    │
│  │  Mutex       │  │  Select      │  │  Job lifecycle       │    │
│  │  (multi-proc │  │  (channel    │  │  (cancel, join,      │    │
│  │   file/sem)  │  │   multiplex) │  │   invokeOnComplete)  │    │
│  └─────────────┘  └─────────────┘  └──────────────────────┘    │
│                                                                 │
│  ┌─────────────┐  ┌──────────────────────────────────────┐     │
│  │  Actor Model │  │  Supervisor Tree (OTP-style)          │     │
│  │  (mailbox +  │  │  ONE_FOR_ONE / ONE_FOR_ALL /          │     │
│  │   message)   │  │  REST_FOR_ONE + restart budget         │     │
│  └─────────────┘  └──────────────────────────────────────┘     │
└─────────────────────────────────────────────────────────────────┘
```

## Features

**Core** — `RunBlocking`, `Launch`, `Async`, `Async::awaitAll()`, `Delay`, `Repeat`, `WithTimeout`, Job lifecycle

**Dispatchers** — `DEFAULT` (fibers + AsyncIO), `IO` (child process via WorkerPool), `MAIN` (event loop)

**WorkerPool** — Pre-spawned long-lived worker processes with task batching, dynamic pool sizing, and respawn backoff

**FiberPool** — Reusable Fiber instances for scheduler optimization (default: 10, dynamic sizing)

**Channel** — Four transports: in-process, socket pool (default), socket per-channel, file-based

**AsyncIO** — Non-blocking stream I/O via `stream_select()` (TCP, TLS, HTTP, files, DNS)

**Flow** — Cold `Flow`, `SharedFlow`, `StateFlow` with backpressure (`SUSPEND`, `DROP_OLDEST`, `DROP_LATEST`, `ERROR`)

**Actor Model** — Message-passing concurrency with Channel-based mailboxes and `ActorSystem` registry

**Supervisor Tree** — OTP-style supervision with `ONE_FOR_ONE`, `ONE_FOR_ALL`, `REST_FOR_ONE` strategies

**Sync** — `Mutex` (file, semaphore, APCu), `Select` for channel multiplexing

## Rules

<img src="https://github.com/vosaka-php/vosaka-foroutines/blob/main/rules.png" alt="Rules" width="800">

## Requirements

- PHP 8.2+
- ext-shmop, ext-fileinfo, ext-zlib

| Optional Extension | Purpose                                                           |
| ------------------ | ----------------------------------------------------------------- |
| ext-pcntl          | Low-overhead IO dispatch via `pcntl_fork()` (~1-5ms vs ~50-200ms) |
| ext-sysvsem        | Semaphore-based `Mutex`                                           |
| ext-apcu           | APCu-based `Mutex`                                                |

## Installation

```
composer require venndev/vosaka-fourotines
```

## Usage

All entry points must be wrapped in `main()` or use the `#[AsyncMain]` attribute:

```php
use function vosaka\foroutines\main;

main(function () {
    // Your async code here
});
```

### RunBlocking + Launch

```php
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
use vosaka\foroutines\{Async, Delay, Dispatchers};

// Create and await a single async task
$result = Async::new(function () {
    Delay::new(100);
    return 42;
})->await();

// Run in a separate worker process (IO dispatcher)
$io = Async::new(function () {
    return file_get_contents('data.txt');
}, Dispatchers::IO)->await();
```

### Async::awaitAll — Concurrent Awaiting

`awaitAll()` drives multiple async tasks forward simultaneously, returning all results in order. This is significantly more efficient than awaiting sequentially.

```php
use vosaka\foroutines\{Async, Delay};

$asyncA = Async::new(function () {
    Delay::new(500);
    return 42;
});

$asyncB = Async::new(function () {
    Delay::new(800);
    return 'hello';
});

$asyncC = Async::new(function () {
    Delay::new(300);
    return 100;
});

// All three run concurrently — total time ≈ 800ms, not 1600ms
[$a, $b, $c] = Async::awaitAll($asyncA, $asyncB, $asyncC);

// Also works with spread operator
$results = Async::awaitAll(...$arrayOfAsyncs);
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

$job->cancelAfter(2.0);
```

### Channel

| Mode                  | Factory                                          | Use Case                           |
| --------------------- | ------------------------------------------------ | ---------------------------------- |
| In-process            | `Channel::new(capacity)`                         | Fibers in the same process         |
| Socket pool (default) | `Channel::create(capacity)`                      | IPC via shared `ChannelBrokerPool` |
| Socket per-channel    | `Channel::newSocketInterProcess(name, capacity)` | Legacy — 1 process per channel     |
| File-based            | `Channel::newInterProcess(name, capacity)`       | IPC via temp file + mutex          |

```php
use vosaka\foroutines\channel\Channel;
use vosaka\foroutines\{RunBlocking, Launch, Dispatchers, Thread};
use function vosaka\foroutines\main;

main(function () {
    $ch = Channel::create(5);   // pool-backed IPC channel

    RunBlocking::new(function () use ($ch) {
        Launch::new(function () use ($ch) {
            $ch->connect();     // reconnect in child process
            $ch->send('from child 1');
            $ch->send('from child 2');
        }, Dispatchers::IO);

        Launch::new(function () use ($ch) {
            var_dump($ch->receive()); // "from child 1"
            var_dump($ch->receive()); // "from child 2"
        });

        Thread::await();
        $ch->close();
    });
});
```

**Non-blocking operations:**

```php
$ok  = $ch->trySend(42);     // false if buffer full
$val = $ch->tryReceive();    // null if buffer empty
```

**Channels utility class:**

```php
use vosaka\foroutines\channel\Channels;

$merged  = Channels::merge($ch1, $ch2, $ch3);
$doubled = Channels::map($ch, fn($v) => $v * 2);
$evens   = Channels::filter($ch, fn($v) => $v % 2 === 0);
$first3  = Channels::take($ch, 3);
$zipped  = Channels::zip($ch1, $ch2);
$nums    = Channels::range(1, 100);
$ticks   = Channels::timer(500, maxTicks: 10);
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
use vosaka\foroutines\flow\{Flow, SharedFlow, MutableStateFlow, BackpressureStrategy};

// Cold Flow
Flow::of(1, 2, 3, 4, 5)
    ->filter(fn($v) => $v % 2 === 0)
    ->map(fn($v) => $v * 10)
    ->collect(fn($v) => var_dump($v)); // 20, 40

// SharedFlow with backpressure
$flow = SharedFlow::new(
    replay: 3,
    extraBufferCapacity: 10,
    onBufferOverflow: BackpressureStrategy::DROP_OLDEST,
);

// StateFlow
$state = MutableStateFlow::new(0);
$state->collect(fn($v) => var_dump("State: $v"));
$state->emit(1);

// Cold Flow with buffer operator
Flow::fromArray(range(1, 1000))
    ->filter(fn($v) => $v % 2 === 0)
    ->buffer(capacity: 64, onOverflow: BackpressureStrategy::SUSPEND)
    ->collect(fn($v) => process($v));
```

### AsyncIO — Non-blocking Stream I/O

All methods return `Deferred` — a lazy wrapper that executes on `->await()`:

```php
use vosaka\foroutines\AsyncIO;

$body   = AsyncIO::httpGet('https://example.com')->await();
$data   = AsyncIO::fileGetContents('/path/to/file')->await();
$socket = AsyncIO::tcpConnect('example.com', 80)->await();
$ip     = AsyncIO::dnsResolve('example.com')->await();
```

| Method                                  | Returns    | Description                     |
| --------------------------------------- | ---------- | ------------------------------- |
| `tcpConnect(host, port)->await()`       | `resource` | Non-blocking TCP connection     |
| `tlsConnect(host, port)->await()`       | `resource` | Non-blocking TLS/SSL connection |
| `streamRead(stream, maxBytes)->await()` | `string`   | Read up to N bytes              |
| `streamReadAll(stream)->await()`        | `string`   | Read until EOF                  |
| `streamWrite(stream, data)->await()`    | `int`      | Write data                      |
| `httpGet(url)->await()`                 | `string`   | HTTP GET                        |
| `httpPost(url, body)->await()`          | `string`   | HTTP POST                       |
| `fileGetContents(path)->await()`        | `string`   | Read entire file                |
| `filePutContents(path, data)->await()`  | `int`      | Write file                      |
| `dnsResolve(hostname)->await()`         | `string`   | Resolve hostname to IP          |

### Mutex

```php
use vosaka\foroutines\sync\Mutex;

Mutex::protect('my-resource', function () {
    file_put_contents('shared.txt', 'safe write');
});
```

### Dispatchers

| Dispatcher | Description                                                            |
| ---------- | ---------------------------------------------------------------------- |
| `DEFAULT`  | Runs in the current fiber context (+ AsyncIO for non-blocking streams) |
| `IO`       | Offloads to a worker process via WorkerPool                            |
| `MAIN`     | Schedules on the main event loop                                       |

```php
use vosaka\foroutines\{RunBlocking, Launch, Dispatchers, Thread};

RunBlocking::new(function () {
    Launch::new(fn() => heavy_io_work(), Dispatchers::IO);
    Thread::await();
});
```

### WorkerPool

A pool of pre-spawned long-lived child processes. On Linux/macOS uses `pcntl_fork()` + Unix socket pairs; on Windows uses `proc_open()` + TCP loopback sockets.

```php
use vosaka\foroutines\WorkerPool;

WorkerPool::setPoolSize(8);

$result = WorkerPool::addAsync(function () {
    return 'processed';
})->await();
```

#### Task Batching

When many small tasks are submitted, IPC round-trip overhead dominates. Task batching groups multiple tasks into a single message sent to each worker, dramatically reducing round-trips.

```
batchSize=1 (default):  Parent ──TASK:A──▶ Worker ──RESULT:A──▶ Parent  (1000 round-trips for 1000 tasks)
batchSize=5:            Parent ──BATCH:[A,B,C,D,E]──▶ Worker ──BATCH_RESULTS:[A,B,C,D,E]──▶ Parent  (200 round-trips)
```

```php
use vosaka\foroutines\WorkerPool;

// Group up to 5 tasks per worker message
WorkerPool::setBatchSize(5);
```

| Batch Size  | Behavior                                                |
| ----------- | ------------------------------------------------------- |
| 1 (default) | Original single-task protocol — lowest latency per task |
| 5–10        | Good balance for many small/fast tasks                  |
| 20–50       | Maximum throughput for trivial tasks                    |

Batching is fully backward compatible — when `batchSize=1`, the pool uses the original `TASK:`/`RESULT:` protocol.

#### Dynamic Pool Sizing

The pool can automatically scale between a minimum and maximum number of workers based on workload pressure.

```php
use vosaka\foroutines\WorkerPool;

WorkerPool::setPoolSize(4);    // initial workers at boot

WorkerPool::setDynamicScaling(
    enabled: true,
    minPoolSize: 2,            // always keep at least 2 workers alive
    maxPoolSize: 8,            // never exceed 8 workers
    idleTimeout: 10.0,         // shut down a worker after 10s idle
    scaleUpCooldown: 0.5,      // wait 0.5s between scale-ups
    scaleDownCooldown: 5.0,    // wait 5s between scale-downs
);
```

**Scale-up**: When all workers are busy and tasks are queued, a new worker is spawned (up to `maxPoolSize`).

**Scale-down**: When a worker has been idle longer than `idleTimeout` and the pool exceeds `minPoolSize`, it is shut down.

```
Workload spike:    2 workers → 4 → 6 → 8 (max)
Workload drops:    8 workers → 6 → 4 → 2 (min, after idle timeout)
```

When dynamic scaling is disabled (default), the pool behaves exactly as before — a fixed number of workers.

#### Worker Respawn Backoff

When a worker crashes repetitively, respawning uses exponential backoff (100ms → 200ms → … max 30s) to prevent CPU spin. After 10 consecutive failures, the worker slot is removed (circuit-breaker).

```php
// Customizable
WorkerPoolState::$maxRespawnAttempts = 10;
WorkerPoolState::$respawnBaseDelayMs = 100;
```

### FiberPool

Reusable Fiber instances to reduce allocation overhead. Integrated into `Launch`, `Async`, `RunBlocking`.

```php
use vosaka\foroutines\FiberPool;

// Adjust global pool size
FiberPool::setDefaultSize(20);

// Direct usage (zero-alloc reuse after first run)
$pool = new FiberPool(maxSize: 10);
$result = $pool->run(fn() => heavyComputation());
```

### Actor Model

```php
use vosaka\foroutines\actor\{Actor, Message, ActorSystem};

class GreeterActor extends Actor {
    protected function receive(Message $msg): void {
        echo "Hello, {$msg->payload}!\n";
    }
}

main(function () {
    RunBlocking::new(function () {
        $system = ActorSystem::new()
            ->register(new GreeterActor('greeter'));

        $system->startAll();
        $system->send('greeter', Message::of('greet', 'World'));

        Delay::new(100);
        $system->stopAll();
        Thread::await();
    });
});
```

### Supervisor Tree

OTP-style supervision with automatic restart on child failure.

```php
use vosaka\foroutines\supervisor\{Supervisor, RestartStrategy};

main(function () {
    RunBlocking::new(function () {
        Supervisor::new(RestartStrategy::ONE_FOR_ONE)
            ->child(fn() => workerA(), 'worker-a')
            ->child(fn() => workerB(), 'worker-b', maxRestarts: 5)
            ->start();

        Thread::await();
    });
});
```

| Strategy       | Behavior                                     |
| -------------- | -------------------------------------------- |
| `ONE_FOR_ONE`  | Restart only the crashed child               |
| `ONE_FOR_ALL`  | Restart all children                         |
| `REST_FOR_ONE` | Restart crashed child + all started after it |

### ForkProcess

On Linux/macOS, `ForkProcess` creates child processes by forking the current process instead of spawning a new interpreter:

| Strategy                    | Overhead  | Closure Serialization      |
| --------------------------- | --------- | -------------------------- |
| `ForkProcess` (pcntl_fork)  | ~1-5ms    | Not needed (memory copied) |
| `Process` (symfony/process) | ~50-200ms | Required                   |

Selection is automatic — `Worker` uses fork when available, falls back to `symfony/process` on Windows.

## Platform Support

| Feature                  | Linux/macOS      | Windows                          |
| ------------------------ | ---------------- | -------------------------------- |
| Fibers (core)            | ✅               | ✅                               |
| FiberPool                | ✅               | ✅                               |
| AsyncIO (stream_select)  | ✅               | ✅                               |
| Channel (all transports) | ✅               | ✅                               |
| Actor Model              | ✅               | ✅                               |
| Supervisor Tree          | ✅               | ✅                               |
| WorkerPool (fork mode)   | ✅               | ❌ (uses socket mode)            |
| WorkerPool (socket mode) | ✅               | ✅                               |
| ForkProcess (pcntl_fork) | ✅               | ❌ (fallback to symfony/process) |
| Mutex (file lock)        | ✅               | ✅                               |
| Mutex (semaphore)        | ✅ (ext-sysvsem) | ❌                               |
| Mutex (APCu)             | ✅ (ext-apcu)    | ✅ (ext-apcu)                    |

## Comparison with JavaScript Async

| Aspect       | Node.js                          | VOsaka Foroutines                                             |
| ------------ | -------------------------------- | ------------------------------------------------------------- |
| Runtime      | libuv event loop (C)             | PHP Fibers + stream_select                                    |
| I/O model    | Non-blocking by default          | `AsyncIO` for streams; `Dispatchers::IO` for blocking APIs    |
| Concurrency  | Single-threaded + worker threads | Single process + child processes (fork/spawn)                 |
| Syntax       | `async/await` (language-level)   | `Async::new()->await()` / `Async::awaitAll()` (library-level) |
| Worker pool  | `worker_threads`                 | `WorkerPool` with task batching + dynamic scaling             |
| IPC channels | `MessagePort`                    | `Channel::create()` (shared TCP pool)                         |
| Flow control | Node.js Streams                  | `BackpressureStrategy` (SUSPEND/DROP/ERROR)                   |

## License

GNU Lesser General Public License v2.1
