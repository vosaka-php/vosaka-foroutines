
***

# Documentation



This is an automatically generated documentation for **Documentation**.


## Namespaces


### \vosaka\foroutines

#### Classes

| Class | Description |
|-------|-------------|
| [`Async`](./classes/vosaka/foroutines/Async.md) | Class Async|
| [`CallableUtils`](./classes/vosaka/foroutines/CallableUtils.md) | |
| [`Delay`](./classes/vosaka/foroutines/Delay.md) | |
| [`EventLoop`](./classes/vosaka/foroutines/EventLoop.md) | |
| [`FiberUtils`](./classes/vosaka/foroutines/FiberUtils.md) | |
| [`Job`](./classes/vosaka/foroutines/Job.md) | |
| [`Launch`](./classes/vosaka/foroutines/Launch.md) | Launches a new asynchronous task that runs concurrently with the main thread.|
| [`Pause`](./classes/vosaka/foroutines/Pause.md) | Pause the current Foroutine execution and yield control back to the event loop.|
| [`PhpFile`](./classes/vosaka/foroutines/PhpFile.md) | Class PhpFile<br />This class is used to run a PHP file asynchronously.|
| [`Process`](./classes/vosaka/foroutines/Process.md) | Process class for running closures in a separate process using shared memory.|
| [`Repeat`](./classes/vosaka/foroutines/Repeat.md) | |
| [`RequireUtils`](./classes/vosaka/foroutines/RequireUtils.md) | |
| [`RunBlocking`](./classes/vosaka/foroutines/RunBlocking.md) | RunBlocking is a utility class that allows you to run multiple fibers synchronously<br />until all of them complete. It is useful for testing or when you need to block the<br />current thread until all asynchronous tasks are finished.|
| [`TimeUtils`](./classes/vosaka/foroutines/TimeUtils.md) | |
| [`WithTimeout`](./classes/vosaka/foroutines/WithTimeout.md) | |
| [`WithTimeoutOrNull`](./classes/vosaka/foroutines/WithTimeoutOrNull.md) | |
| [`Worker`](./classes/vosaka/foroutines/Worker.md) | Worker class for running closures asynchronously.|
| [`WorkerPool`](./classes/vosaka/foroutines/WorkerPool.md) | WorkerPool class for managing a pool of workers that can run closures asynchronously.|


#### Traits

| Trait | Description |
|-------|-------------|
| [`Instance`](./classes/vosaka/foroutines/Instance.md) | |




### \vosaka\foroutines\channel

#### Classes

| Class | Description |
|-------|-------------|
| [`Channel`](./classes/vosaka/foroutines/channel/Channel.md) | |
| [`ChannelIterator`](./classes/vosaka/foroutines/channel/ChannelIterator.md) | |
| [`Channels`](./classes/vosaka/foroutines/channel/Channels.md) | |




### \vosaka\foroutines\flow

#### Classes

| Class | Description |
|-------|-------------|
| [`BaseFlow`](./classes/vosaka/foroutines/flow/BaseFlow.md) | Abstract base class for Flow implementations|
| [`Flow`](./classes/vosaka/foroutines/flow/Flow.md) | Cold Flow - Creates new stream for each collector|
| [`MutableStateFlow`](./classes/vosaka/foroutines/flow/MutableStateFlow.md) | Utility class for creating MutableStateFlow|
| [`SharedFlow`](./classes/vosaka/foroutines/flow/SharedFlow.md) | Hot Flow that shares emissions among multiple collectors|
| [`StateFlow`](./classes/vosaka/foroutines/flow/StateFlow.md) | StateFlow - A SharedFlow that always has a current value|



#### Interfaces

| Interface | Description |
|-----------|-------------|
| [`FlowInterface`](./classes/vosaka/foroutines/flow/FlowInterface.md) | Base interface for all Flow types|



### \vosaka\foroutines\selects

#### Classes

| Class | Description |
|-------|-------------|
| [`Select`](./classes/vosaka/foroutines/selects/Select.md) | |




### \vosaka\foroutines\sync

#### Classes

| Class | Description |
|-------|-------------|
| [`Mutex`](./classes/vosaka/foroutines/sync/Mutex.md) | Class Mutex<br />Provides mutual exclusion (mutex) functionality for multi-process synchronization.|




***
> Automatically generated on 2025-07-29
