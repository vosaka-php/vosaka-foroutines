***

# Flow

Cold Flow - Creates new stream for each collector



* Full name: `\vosaka\foroutines\flow\Flow`
* Parent class: [`\vosaka\foroutines\flow\BaseFlow`](./BaseFlow.md)
* This class is marked as **final** and can't be subclassed
* This class is a **Final class**



## Properties


### fiber



```php
private ?\Fiber $fiber
```






***

### source



```php
private callable|\Generator|\vosaka\foroutines\flow\Async|\Fiber $source
```






***

## Methods


### __construct



```php
private __construct(callable|\Generator|\vosaka\foroutines\flow\Async|\Fiber $source): mixed
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$source` | **callable&#124;\Generator&#124;\vosaka\foroutines\flow\Async&#124;\Fiber** |  |





***

### new

Create a new Flow from a callable, Generator, Async, or Fiber

```php
public static new(callable|\Generator|\vosaka\foroutines\flow\Async|\Fiber $source): \vosaka\foroutines\flow\Flow
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$source` | **callable&#124;\Generator&#124;\vosaka\foroutines\flow\Async&#124;\Fiber** |  |





***

### of

Create a Flow that emits a sequence of values

```php
public static of(mixed $values): \vosaka\foroutines\flow\Flow
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$values` | **mixed** |  |





***

### empty

Create an empty Flow

```php
public static empty(): \vosaka\foroutines\flow\Flow
```



* This method is **static**.








***

### fromArray

Create a Flow from an array

```php
public static fromArray(array $array): \vosaka\foroutines\flow\Flow
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$array` | **array** |  |





***

### emit

Emit a value in the current Flow context

```php
public static emit(mixed $value): void
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$value` | **mixed** |  |





***

### collect

Collect all emitted values

```php
public collect(callable $collector): void
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$collector` | **callable** |  |





***

### __clone



```php
public __clone(): mixed
```












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


***
> Automatically generated on 2025-07-29
