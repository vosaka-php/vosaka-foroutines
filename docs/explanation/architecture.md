# Explanation: Architecture

VOsaka Foroutines is designed to bring structured concurrency to PHP, inspired by the Kotlin Coroutines model.

## The Core Loop
At the heart of the library is a **Cooperative Scheduler Loop**. Unlike pre-emptive multi-threading, coroutines in Foroutines explicitly yield control back to the scheduler.

### Components:
1.  **FiberPool**: Instead of creating a new Fiber for every task (which is expensive), Foroutines maintains a pool of reusable Fiber instances.
2.  **AsyncIO**: Uses `stream_select()` to monitor multiple IO streams (sockets, files, timers) in a non-blocking way. When data is ready, the scheduler resumes the corresponding Fiber.
3.  **Dispatchers**: Dispatchers act as the "engine" selection for your tasks:
    -   **`DEFAULT`**: The fiber-based engine. It multiplexes thousands of tasks onto a single PHP thread using Fibers. It is lightweight but cooperative (requires `Delay` or `AsyncIO` to yield).
    -   **`IO`**: The process-based engine. It routes tasks to the `WorkerPool`. This is the only way to achieve true parallelism for CPU-bound tasks in PHP, as it utilizes multiple CPU cores.
    -   **`MAIN`**: A specialized dispatcher for scheduling work to be performed once the current execution stack is clear, without starting a new concurrency unit.

## Structured Concurrency
By using `RunBlocking`, you create a scope. This scope ensures that no task is "leaked". The parent scope will wait for all its children to finish (or cancel them if the parent fails), preventing orphaned processes or background fibers from running indefinitely.
