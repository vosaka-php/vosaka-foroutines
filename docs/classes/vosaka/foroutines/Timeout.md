***

# Timeout

Fiber Timeout Manager
Provides timeout functionality for PHP Fibers similar to Kotlin coroutines



* Full name: `\vosaka\foroutines\Timeout`
* This class is marked as **final** and can't be subclassed
* This class is a **Final class**



## Properties


### timeouts



```php
private static $timeouts
```



* This property is **static**.


***

### timerId



```php
private static $timerId
```



* This property is **static**.


***

## Methods


### withTimeout

Runs a fiber with specified timeout in milliseconds
Throws RuntimeException if timeout is exceeded

```php
public static withTimeout(int $timeoutMs, callable $block): mixed
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$timeoutMs` | **int** | Timeout in milliseconds |
| `$block` | **callable** | The function to execute in fiber |


**Return Value:**

Result of the block execution



**Throws:**

- [`RuntimeException`](../../RuntimeException.md)



***

### withTimeoutOrNull

Runs a fiber with specified timeout, returns null if timeout is exceeded

```php
public static withTimeoutOrNull(int $timeoutMs, callable $block): mixed|null
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$timeoutMs` | **int** | Timeout in milliseconds |
| `$block` | **callable** | The function to execute in fiber |


**Return Value:**

Result of the block execution or null on timeout




***

### withTimeoutSeconds

Runs a fiber with timeout specified in seconds (float)

```php
public static withTimeoutSeconds(float $timeoutSeconds, callable $block): mixed
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$timeoutSeconds` | **float** | Timeout in seconds |
| `$block` | **callable** | The function to execute |





***

### withTimeoutOrNullSeconds

Runs a fiber with timeout specified in seconds, returns null on timeout

```php
public static withTimeoutOrNullSeconds(float $timeoutSeconds, callable $block): mixed|null
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$timeoutSeconds` | **float** | Timeout in seconds |
| `$block` | **callable** | The function to execute |





***

### checkTimeout

Check if a specific timeout has expired

```php
private static checkTimeout(int $timerId): bool
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$timerId` | **int** |  |





***

### getActiveTimeouts

Get current running timeouts (for debugging)

```php
public static getActiveTimeouts(): array
```



* This method is **static**.








***

### cancelAllTimeouts

Cancel all active timeouts (cleanup)

```php
public static cancelAllTimeouts(): void
```



* This method is **static**.








***


***
> Automatically generated on 2025-07-28
