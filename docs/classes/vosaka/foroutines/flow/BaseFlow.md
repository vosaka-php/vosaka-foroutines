***

# BaseFlow

Abstract base class for Flow implementations



* Full name: `\vosaka\foroutines\flow\BaseFlow`
* This class implements:
[`\vosaka\foroutines\flow\FlowInterface`](./FlowInterface.md)
* This class is an **Abstract class**



## Properties


### operators



```php
protected array $operators
```






***

### isCompleted



```php
protected bool $isCompleted
```






***

### exception



```php
protected ?\Throwable $exception
```






***

## Methods


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


***
> Automatically generated on 2025-07-29
