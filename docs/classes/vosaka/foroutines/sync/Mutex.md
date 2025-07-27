***

# Mutex

Class Mutex
Provides mutual exclusion (mutex) functionality for multi-process synchronization.

Supports both file-based locking and System V semaphores.

* Full name: `\vosaka\foroutines\sync\Mutex`


## Constants

| Constant | Visibility | Type | Value |
|:---------|:-----------|:-----|:------|
|`LOCK_FILE`|public| |&#039;file&#039;|
|`LOCK_SEMAPHORE`|public| |&#039;semaphore&#039;|

## Properties


### lockFile



```php
private $lockFile
```






***

### lockHandle



```php
private $lockHandle
```






***

### semaphore



```php
private $semaphore
```






***

### semaphoreKey



```php
private $semaphoreKey
```






***

### lockType



```php
private $lockType
```






***

### isLocked



```php
private $isLocked
```






***

### timeout



```php
private $timeout
```






***

### lockName



```php
private $lockName
```






***

## Methods


### __construct



```php
public __construct(string $name, string $type = self::LOCK_FILE, int $timeout, string|null $lockDir = null): mixed
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$name` | **string** | Unique name for the mutex |
| `$type` | **string** | Lock type: &#039;file&#039; or &#039;semaphore&#039; |
| `$timeout` | **int** | Timeout in seconds (0 = no timeout) |
| `$lockDir` | **string&#124;null** | Directory for lock files (only for file locks) |





***

### initFileLock

Initialize file-based locking

```php
private initFileLock(?string $lockDir): void
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$lockDir` | **?string** |  |





***

### initSemaphoreLock

Initialize semaphore-based locking

```php
private initSemaphoreLock(): void
```












***

### acquire

Acquire the mutex lock

```php
public acquire(bool $blocking = true): bool
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$blocking` | **bool** | Whether to block until lock is acquired |


**Return Value:**

True if lock acquired, false otherwise



**Throws:**

- [`Exception`](../../../Exception.md)



***

### acquireFileLock

Acquire file-based lock

```php
private acquireFileLock(bool $blocking): bool
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$blocking` | **bool** |  |





***

### acquireSemaphoreLock

Acquire semaphore-based lock

```php
private acquireSemaphoreLock(bool $blocking): bool
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$blocking` | **bool** |  |





***

### release

Release the mutex lock

```php
public release(): bool
```









**Return Value:**

True if successfully released




***

### releaseFileLock

Release file-based lock

```php
private releaseFileLock(): bool
```












***

### releaseSemaphoreLock

Release semaphore-based lock

```php
private releaseSemaphoreLock(): bool
```












***

### tryLock

Try to acquire lock without blocking

```php
public tryLock(): bool
```









**Return Value:**

True if lock acquired immediately




***

### isLocked

Check if the mutex is currently locked by this instance

```php
public isLocked(): bool
```












***

### synchronized

Execute a callback with the mutex locked

```php
public synchronized(callable $callback, bool $blocking = true): mixed
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$callback` | **callable** |  |
| `$blocking` | **bool** |  |


**Return Value:**

The return value of the callback



**Throws:**

- [`Exception`](../../../Exception.md)



***

### getInfo

Get mutex information

```php
public getInfo(): array
```












***

### __destruct

Cleanup resources

```php
public __destruct(): mixed
```












***

### create

Static factory method for quick mutex creation

```php
public static create(string $name, string $type = self::LOCK_FILE, int $timeout): static
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$name` | **string** |  |
| `$type` | **string** |  |
| `$timeout` | **int** |  |





***

### protect

Static method to execute code with mutex protection

```php
public static protect(string $mutexName, callable $callback, string $lockType = self::LOCK_FILE, int $timeout = 30): mixed
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$mutexName` | **string** |  |
| `$callback` | **callable** |  |
| `$lockType` | **string** |  |
| `$timeout` | **int** |  |





***


***
> Automatically generated on 2025-07-27
