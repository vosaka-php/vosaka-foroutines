***

# Launch

Launches a new asynchronous task that runs concurrently with the main thread.

It manages a queue of child scopes, each containing a fiber that executes the task.

* Full name: `\vosaka\foroutines\Launch`
* Parent class: [`\vosaka\foroutines\Job`](./Job.md)
* This class is marked as **final** and can't be subclassed
* This class is a **Final class**



## Properties


### queue



```php
public static array&lt;int,\vosaka\foroutines\Job&gt; $queue
```



* This property is **static**.


***

### id



```php
public int $id
```






***

## Methods


### __construct



```php
public __construct(int $id): mixed
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$id` | **int** |  |





***

### new

Creates a new asynchronous task. But it run concurrently with the main thread.

```php
public static new(callable|\Generator|\vosaka\foroutines\Async|\venndev\vosaka\core\Result|\Fiber $callable, \vosaka\foroutines\Dispatchers $dispatcher = Dispatchers::DEFAULT): \vosaka\foroutines\Launch
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$callable` | **callable&#124;\Generator&#124;\vosaka\foroutines\Async&#124;\venndev\vosaka\core\Result&#124;\Fiber** | The function or generator to run asynchronously. |
| `$dispatcher` | **\vosaka\foroutines\Dispatchers** | The dispatcher to use for the async task. |





***

### makeLaunch



```php
private static makeLaunch(callable|\Generator|\vosaka\foroutines\Async|\venndev\vosaka\core\Result|\Fiber $callable): \vosaka\foroutines\Launch
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$callable` | **callable&#124;\Generator&#124;\vosaka\foroutines\Async&#124;\venndev\vosaka\core\Result&#124;\Fiber** |  |





***

### cancel

Cancels the task associated with this Launch instance.

```php
public cancel(): void
```

If the task is still running, it will be removed from the queue.










***

### runOnce

Runs the next task in the queue if available.

```php
public runOnce(): void
```

This method should be called periodically to ensure that tasks are executed.










***


## Inherited methods


### __construct



```php
public __construct(int $id): mixed
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$id` | **int** |  |





***

### getStartTime



```php
public getStartTime(): float
```












***

### getEndTime



```php
public getEndTime(): ?float
```












***

### getStatus



```php
public getStatus(): \vosaka\foroutines\JobState
```












***

### start



```php
public start(): bool
```












***

### complete



```php
public complete(): void
```












***

### fail



```php
public fail(): void
```












***

### cancel



```php
public cancel(): void
```












***

### isFinal



```php
public isFinal(): bool
```












***

### isCompleted



```php
public isCompleted(): bool
```












***

### isRunning



```php
public isRunning(): bool
```












***

### isFailed



```php
public isFailed(): bool
```












***

### isCancelled



```php
public isCancelled(): bool
```












***

### onJoin



```php
public onJoin(callable $callback): void
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$callback` | **callable** |  |





***

### join



```php
public join(): mixed
```












***

### invokeOnCompletion



```php
public invokeOnCompletion(callable $callback): void
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$callback` | **callable** |  |





***

### triggerInvokers



```php
public triggerInvokers(): void
```












***

### cancelAfter



```php
public cancelAfter(float $seconds): void
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$seconds` | **float** |  |





***

### isTimedOut



```php
public isTimedOut(): bool
```












***

### getInstance



```php
public static getInstance(): static
```



* This method is **static**.








***


***
> Automatically generated on 2025-07-27
