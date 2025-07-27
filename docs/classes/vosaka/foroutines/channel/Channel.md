***

# Channel





* Full name: `\vosaka\foroutines\channel\Channel`
* This class is marked as **final** and can't be subclassed
* This class is a **Final class**



## Properties


### buffer



```php
private array $buffer
```






***

### capacity



```php
private int $capacity
```






***

### sendQueue



```php
private array $sendQueue
```






***

### receiveQueue



```php
private array $receiveQueue
```






***

### closed



```php
private bool $closed
```






***

## Methods


### __construct



```php
public __construct(int $capacity): mixed
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$capacity` | **int** |  |





***

### new



```php
public static new(int $capacity): \vosaka\foroutines\channel\Channel
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$capacity` | **int** |  |





***

### send



```php
public send(mixed $value): void
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$value` | **mixed** |  |





***

### receive



```php
public receive(): mixed
```












***

### trySend



```php
public trySend(mixed $value): bool
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$value` | **mixed** |  |





***

### tryReceive



```php
public tryReceive(): mixed
```












***

### close



```php
public close(): void
```












***

### isClosed



```php
public isClosed(): bool
```












***

### isEmpty



```php
public isEmpty(): bool
```












***

### isFull



```php
public isFull(): bool
```












***

### size



```php
public size(): int
```












***

### getIterator



```php
public getIterator(): \vosaka\foroutines\channel\ChannelIterator
```












***


***
> Automatically generated on 2025-07-27
