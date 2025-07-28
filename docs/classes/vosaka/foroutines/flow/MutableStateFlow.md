***

# MutableStateFlow

Utility class for creating MutableStateFlow



* Full name: `\vosaka\foroutines\flow\MutableStateFlow`
* Parent class: [`\vosaka\foroutines\flow\StateFlow`](./StateFlow.md)
* This class is marked as **final** and can't be subclassed
* This class is a **Final class**




## Methods


### new

Create a new StateFlow with initial value

```php
public static new(mixed $initialValue): \vosaka\foroutines\flow\MutableStateFlow
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$initialValue` | **mixed** |  |





***

### emit

Alias for setValue for better API consistency

```php
public emit(mixed $value): void
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$value` | **mixed** |  |





***

### compareAndSet

Compare and set - only set if current value matches expected

```php
public compareAndSet(mixed $expected, mixed $newValue): bool
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$expected` | **mixed** |  |
| `$newValue` | **mixed** |  |





***

### tryEmit

Try to emit - returns false if StateFlow is completed/closed

```php
public tryEmit(mixed $value): bool
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$value` | **mixed** |  |





***


## Inherited methods


### map

Transform each emitted value

```php
public map(callable $transform): \vosaka\foroutines\flow\FlowInterface
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$transform` | **callable** |  |





***

### filter

Filter emitted values

```php
public filter(callable $predicate): \vosaka\foroutines\flow\FlowInterface
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$predicate` | **callable** |  |





***

### take

Take only the first n values

```php
public take(int $count): \vosaka\foroutines\flow\FlowInterface
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$count` | **int** |  |





***

### skip

Skip the first n values

```php
public skip(int $count): \vosaka\foroutines\flow\FlowInterface
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$count` | **int** |  |





***

### flatMap

Transform each value to a Flow and flatten the result

```php
public flatMap(callable $transform): \vosaka\foroutines\flow\FlowInterface
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$transform` | **callable** |  |





***

### onEach

Perform an action for each emitted value without transforming it

```php
public onEach(callable $action): \vosaka\foroutines\flow\FlowInterface
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$action` | **callable** |  |





***

### catch

Catch exceptions and handle them

```php
public catch(callable $handler): \vosaka\foroutines\flow\FlowInterface
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$handler` | **callable** |  |





***

### onCompletion

Execute when the flow completes (successfully or with error)

```php
public onCompletion(callable $action): \vosaka\foroutines\flow\FlowInterface
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$action` | **callable** |  |





***

### first

Collect and return the first emitted value

```php
public first(): mixed
```












***

### firstOrNull

Collect and return the first emitted value or null if empty

```php
public firstOrNull(): mixed
```












***

### toArray

Collect all values into an array

```php
public toArray(): array
```












***

### count

Count the number of emitted values

```php
public count(): int
```












***

### reduce

Reduce the flow to a single value

```php
public reduce(mixed $initial, callable $operation): mixed
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$initial` | **mixed** |  |
| `$operation` | **callable** |  |





***

### applyOperators

Apply operators to a value

```php
protected applyOperators(mixed $value, int& $emittedCount, int& $skippedCount): mixed
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$value` | **mixed** |  |
| `$emittedCount` | **int** |  |
| `$skippedCount` | **int** |  |





***

### executeOnCompletion

Execute completion callbacks

```php
protected executeOnCompletion(?\Throwable $exception): void
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$exception` | **?\Throwable** |  |





***

### wasExceptionHandled

Check if exception was handled by catch operator

```php
protected wasExceptionHandled(\Throwable $exception): bool
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$exception` | **\Throwable** |  |





***

### __clone



```php
public __clone(): mixed
```












***

### new

Create a new StateFlow with initial value

```php
public static new(mixed $initialValue): \vosaka\foroutines\flow\StateFlow
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$initialValue` | **mixed** |  |





***

### getValue

Get current value

```php
public getValue(): mixed
```












***

### setValue

Set new value and emit to collectors

```php
public setValue(mixed $value): void
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$value` | **mixed** |  |





***

### update

Update value using a function

```php
public update(callable $updater): void
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$updater` | **callable** |  |





***

### collect

Collect state changes

```php
public collect(callable $collector): void
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$collector` | **callable** |  |





***

### distinctUntilChanged

Collect only distinct values (skip if same as previous)

```php
public distinctUntilChanged(?callable $compareFunction = null): \vosaka\foroutines\flow\StateFlow
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$compareFunction` | **?callable** |  |





***

### getCollectorCount

Get current number of collectors

```php
public getCollectorCount(): int
```












***

### removeCollector

Remove a collector

```php
public removeCollector(string $collectorKey): void
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$collectorKey` | **string** |  |





***

### hasCollectors

Check if StateFlow has any collectors

```php
public hasCollectors(): bool
```












***


***
> Automatically generated on 2025-07-28
