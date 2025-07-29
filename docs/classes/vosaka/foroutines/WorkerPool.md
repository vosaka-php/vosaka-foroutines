***

# WorkerPool

WorkerPool class for managing a pool of workers that can run closures asynchronously.

This class allows you to add closures to a pool and run them concurrently with a limit on the number of concurrent workers.

* Full name: `\vosaka\foroutines\WorkerPool`
* This class is marked as **final** and can't be subclassed
* This class is a **Final class**



## Properties


### workers



```php
private static \vosaka\foroutines\Worker[] $workers
```



* This property is **static**.


***

### returns



```php
private static array $returns
```



* This property is **static**.


***

### running



```php
private static int $running
```



* This property is **static**.


***

### poolSize



```php
private static int $poolSize
```



* This property is **static**.


***

## Methods


### __construct



```php
public __construct(): mixed
```












***

### setPoolSize

Sets the maximum number of workers that can run concurrently.

```php
public static setPoolSize(int $size): void
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$size` | **int** | The maximum number of workers. |




**Throws:**
<p>if the size is less than or equal to 0.</p>

- [`Exception`](../../Exception.md)



***

### add

Gets the current pool size.

```php
public static add(\Closure $closure): int
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$closure` | **\Closure** |  |


**Return Value:**

The current pool size.




***

### addAsync

Adds a closure to the worker pool and returns an Async instance that can be used to wait for the result.

```php
public static addAsync(\Closure $closure): \vosaka\foroutines\Async
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$closure` | **\Closure** | The closure to run asynchronously. |


**Return Value:**

An Async instance that will return the result of the closure when it completes.




***

### waitForResult



```php
private static waitForResult(int $id): \vosaka\foroutines\Async
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$id` | **int** |  |





***

### run

Runs the workers in the pool concurrently, respecting the pool size limit.

```php
public static run(): void
```

This method will start workers until the pool size limit is reached or there are no more workers to run.

* This method is **static**.








***


***
> Automatically generated on 2025-07-29
