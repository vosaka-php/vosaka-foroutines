***

# Job





* Full name: `\vosaka\foroutines\Job`



## Properties


### fiber



```php
public ?\Fiber $fiber
```






***

### job



```php
public ?\vosaka\foroutines\Job $job
```






***

### startTime



```php
private float $startTime
```






***

### endTime



```php
private float $endTime
```






***

### status



```php
private \vosaka\foroutines\JobState $status
```






***

### joins



```php
private array $joins
```






***

### invokers



```php
private array $invokers
```






***

### timeout



```php
private ?float $timeout
```






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

### triggerJoins



```php
private triggerJoins(): void
```












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


***
> Automatically generated on 2025-07-28
