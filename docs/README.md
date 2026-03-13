# VOsaka Foroutines Documentation

Welcome to the VOsaka Foroutines documentation. This documentation is organized following the [Diátaxis framework](https://diataxis.fr/), designed to help you find the right information based on your current needs.

## 🎓 [Tutorials](./tutorials/)
*Learning-oriented lessons for beginners.*

- **[Getting Started](./tutorials/01_getting_started.md)**: Install the library and run your first async code.
- **[Concurrency Basics](./tutorials/02_concurrency_basics.md)**: Learn about `Launch` vs `Async`.

## 🛠️ [How-to Guides](./how-to/)
*Task-oriented guides to solve specific problems.*

- **[Handling Timeouts](./how-to/handle_timeouts.md)**: Prevent tasks from running too long.
- **[IPC Communication](./how-to/ipc_communication.md)**: Communicate between processes using Channels.
- **[Non-blocking IO](./how-to/non_blocking_io.md)**: Fetch URLs and read files without blocking.
- **[Background Tasks](./how-to/background_tasks.md)**: Scale heavy CPU tasks using the WorkerPool.

## 📚 [Reference](./reference/)
*Information-oriented technical descriptions.*

- **[Core API](./reference/core_api.md)**: Detailed reference for core classes.
- **[Channel Transports](./reference/channel_transports.md)**: Details on socket, file, and in-process channels.
- **[Flow Operators](./reference/flow_operators.md)**: Full list of reactive stream operators.

## 💡 [Explanation](./explanation/)
*Understanding-oriented conceptual overviews.*

- **[Architecture](./explanation/architecture.md)**: How the scheduler and fiber pool work together.
- **[Supervisor Strategies](./explanation/supervisor_strategies.md)**: Fault-tolerance and restart patterns.
- **[Actor Model](./explanation/actor_model.md)**: Design philosophy of message-passing in Foroutines.
