# Reference: Core API

Detailed technical description of the main components in VOsaka Foroutines.

## `main(callable $entry)`
The mandatory entry point for any foroutine-based application. It initializes the internal environment.

## `#[AsyncMain]`
Attribute alternative to `main()`. Annotate your main function with this to enable the foroutine environment.

## `RunBlocking::new(callable $block)`
Creates a scope that blocks the current thread until all launched jobs inside it are completed. It is the primary way to "enter" the async world from a synchronous script.

## `Launch::new(callable $task, ?string $dispatcher = null): Job`
Starts a new coroutine in a fire-and-forget fashion. Returns a `Job` object that can be used for lifecycle management.

## `Async::new(callable $task, ?string $dispatcher = null): Deferred`
Starts a new coroutine that is expected to return a value. Returns a `Deferred` object. You must call `->await()` on the returned object to retrieve the result.

## `Job`
Represents the lifecycle of a coroutine.
- `cancel()`: Request cancellation.
- `join()`: Wait for the job to complete.
- `invokeOnCompletion(callable $handler)`: Register a callback for when the job finishes.
- `getStatus()`: Returns `JobState` (NEW, ACTIVE, COMPLETING, COMPLETED, CANCELLING, CANCELLED).

## `Delay::new(int $ms)`
Suspends the current coroutine for the specified number of milliseconds without blocking the underlying thread/process.

## Dispatchers
Dispatchers control the execution context of a coroutine.

| Constant | Behavior | Use Case |
| :--- | :--- | :--- |
| `Dispatchers::DEFAULT` | Runs in a **Fiber** in the current process. | Fast, non-blocking logic, AsyncIO. |
| `Dispatchers::IO` | Runs in a **Child Process** via WorkerPool. | Heavy CPU, blocking legacy APIs. |
| `Dispatchers::MAIN` | Schedules on the next tick of the event loop. | Deferred execution in the same process. |
