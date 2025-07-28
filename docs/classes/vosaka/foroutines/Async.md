***

# Async

Class Async

Represents an asynchronous task that can be executed in a separate Foroutine.
This class allows you to run a function or generator asynchronously and wait for its result.

* Full name: `\vosaka\foroutines\Async`
* This class is marked as **final** and can't be subclassed
* This class is a **Final class**



## Properties


### fiber



```php
public \Fiber $fiber
```






***

## Methods


### __construct



```php
public __construct(\Fiber $fiber): mixed
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$fiber` | **\Fiber** |  |





***

### new

Creates a new asynchronous task.

```php
public static new(callable|\Generator|\venndev\vosaka\core\Result $callable, \vosaka\foroutines\Dispatchers $dispatcher = Dispatchers::DEFAULT): \vosaka\foroutines\Async
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$callable` | **callable&#124;\Generator&#124;\venndev\vosaka\core\Result** | The function or generator to run asynchronously. |
| `$dispatcher` | **\vosaka\foroutines\Dispatchers** | The dispatcher to use for the async task. |





***

### wait

Waits for the asynchronous task to complete and returns its result.

```php
public wait(): mixed
```









**Return Value:**

The result of the asynchronous task.




***


***
> Automatically generated on 2025-07-28
