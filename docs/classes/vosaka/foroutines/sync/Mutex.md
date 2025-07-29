***

# Mutex

Class Mutex
Provides mutual exclusion (mutex) functionality for multi-process synchronization.

Automatically detects platform capabilities and falls back to compatible methods.
Supports file-based locking, System V semaphores, and APCu-based locking.

* Full name: `\vosaka\foroutines\sync\Mutex`


## Constants

| Constant | Visibility | Type | Value |
|:---------|:-----------|:-----|:------|
|`LOCK_FILE`|public| |&#039;file&#039;|
|`LOCK_SEMAPHORE`|public| |&#039;semaphore&#039;|
|`LOCK_APCU`|public| |&#039;apcu&#039;|
|`LOCK_AUTO`|public| |&#039;auto&#039;|

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

### actualLockType



```php
private $actualLockType
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

### apcu_key



```php
private $apcu_key
```






***

## Methods


### __construct



```php
public __construct(string $name, string $type = self::LOCK_AUTO, int $timeout, string|null $lockDir = null): mixed
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$name` | **string** | Unique name for the mutex |
| `$type` | **string** | Lock type: &#039;file&#039;, &#039;semaphore&#039;, &#039;apcu&#039;, or &#039;auto&#039; |
| `$timeout` | **int** | Timeout in seconds (0 = no timeout) |
| `$lockDir` | **string&#124;null** | Directory for lock files (only for file locks) |





***

### detectBestLockType

Detect the best available lock type for current platform

```php
private detectBestLockType(): string
```












***

### validateAndGetLockType

Validate requested lock type and return actual usable type

```php
private validateAndGetLockType(string $requestedType): string
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$requestedType` | **string** |  |





***

### isSemaphoreAvailable

Check if System V semaphore is available

```php
private isSemaphoreAvailable(): bool
```












***

### isApcuAvailable

Check if APCu is available

```php
private isApcuAvailable(): bool
```












***

### isWindows

Check if we're running on Windows

```php
private isWindows(): bool
```












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

### initApcuLock

Initialize APCu-based locking

```php
private initApcuLock(): void
```












***

### generateSemaphoreKey

Generate a semaphore key from mutex name

```php
private generateSemaphoreKey(string $name): int
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$name` | **string** |  |





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

### acquireApcuLock

Acquire APCu-based lock

```php
private acquireApcuLock(bool $blocking): bool
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

### releaseApcuLock

Release APCu-based lock

```php
private releaseApcuLock(): bool
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

### getPlatformInfo

Get platform compatibility report

```php
public static getPlatformInfo(): array
```



* This method is **static**.








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
public static create(string $name, string $type = self::LOCK_AUTO, int $timeout): static
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
public static protect(string $mutexName, callable $callback, string $lockType = self::LOCK_AUTO, int $timeout = 30): mixed
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
> Automatically generated on 2025-07-29
