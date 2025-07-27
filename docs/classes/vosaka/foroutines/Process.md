***

# Process

Process class for running closures in a separate process using shared memory.

This class provides methods to check if a process is running and to run closures asynchronously.

* Full name: `\vosaka\foroutines\Process`
* This class is marked as **final** and can't be subclassed
* This class is a **Final class**


## Constants

| Constant | Visibility | Type | Value |
|:---------|:-----------|:-----|:------|
|`MAX_INT`|private| |2147483647|

## Properties


### shmopKey



```php
private int $shmopKey
```






***

## Methods


### __construct



```php
public __construct(int $shmopKey): mixed
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$shmopKey` | **int** |  |





***

### isProcessRunning

Checks if a process with the given PID is running.

```php
public static isProcessRunning(int $pid): \vosaka\foroutines\Async
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$pid` | **int** | The process ID to check. |


**Return Value:**

An Async instance that resolves to true if the process is running, false otherwise.




***

### run

Runs a closure in a separate process using shared memory.

```php
public run(\Closure $closure): \vosaka\foroutines\Async
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$closure` | **\Closure** | The closure to run asynchronously. |


**Return Value:**

An Async instance that will return the result of the closure when it completes.



**Throws:**
<p>if the shmop extension is not loaded or if there is an error writing to shared memory.</p>

- [`Exception`](../../Exception.md)



***


***
> Automatically generated on 2025-07-27
