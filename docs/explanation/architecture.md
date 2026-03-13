# Explanation: Architecture

VOsaka Foroutines is designed to bring structured concurrency to PHP, inspired by the Kotlin Coroutines model.

## The Core Loop
At the heart of the library is a **Cooperative Scheduler Loop**. Unlike pre-emptive multi-threading, coroutines in Foroutines explicitly yield control back to the scheduler.

### Components:
1.  **FiberPool**: Instead of creating a new Fiber for every task (which is expensive), Foroutines maintains a pool of reusable Fiber instances.
2.  **AsyncIO**: Uses `stream_select()` to monitor multiple IO streams (sockets, files, timers) in a non-blocking way. When data is ready, the scheduler resumes the corresponding Fiber.
3.  **Dispatchers**:
    -   `DEFAULT`: Executes tasks in the main process using Fibers.
    -   `IO`: Spawns or uses a `WorkerPool` process to handle blocking code without freezing the main loop.
    -   `MAIN`: Schedules tasks on the next tick of the event loop.

## Structured Concurrency
By using `RunBlocking`, you create a scope. This scope ensures that no task is "leaked". The parent scope will wait for all its children to finish (or cancel them if the parent fails), preventing orphaned processes or background fibers from running indefinitely.
