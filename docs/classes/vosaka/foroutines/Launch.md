***

# Launch

Launches a new asynchronous task that runs concurrently with the main thread.

It manages a queue of child scopes, each containing a fiber that executes the task.

* Full name: `\vosaka\foroutines\Launch`
* This class is marked as **final** and can't be subclassed
* This class is a **Final class**



## Properties


### queue



```php
public static array&lt;int,\vosaka\foroutines\ChildScope&gt; $queue
```



* This property is **static**.


***

### id



```php
private int $id
```






***

## Methods


### __construct



```php
private __construct(int $id): mixed
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
public static runOnce(): void
```

This method should be called periodically to ensure that tasks are executed.

* This method is **static**.








***


***
> Automatically generated on 2025-07-26
