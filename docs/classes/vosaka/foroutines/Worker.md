***

# Worker

Worker class for running closures asynchronously.

This class is used to encapsulate a closure and run it in a separate process.
It generates a unique ID for each worker instance.

* Full name: `\vosaka\foroutines\Worker`
* This class is marked as **final** and can't be subclassed
* This class is a **Final class**



## Properties


### id



```php
public int $id
```






***

### closure



```php
public \Closure $closure
```






***

## Methods


### __construct



```php
public __construct(\Closure $closure): mixed
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$closure` | **\Closure** |  |





***

### run

Runs the closure in a separate process and returns an Async instance.

```php
public run(): \vosaka\foroutines\Async
```












***


***
> Automatically generated on 2025-07-28
