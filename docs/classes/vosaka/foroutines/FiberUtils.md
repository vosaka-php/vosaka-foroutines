***

# FiberUtils





* Full name: `\vosaka\foroutines\FiberUtils`
* This class is marked as **final** and can't be subclassed
* This class is a **Final class**




## Methods


### fiberStillRunning



```php
public static fiberStillRunning(\Fiber $fiber): bool
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$fiber` | **\Fiber** |  |





***

### makeFiber

Creates a new Fiber instance from the provided callable, generator, Async, Result, or existing Fiber.

```php
public static makeFiber(callable|\Generator|\vosaka\foroutines\Async|\venndev\vosaka\core\Result|\Fiber $callable): \Fiber
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$callable` | **callable&#124;\Generator&#124;\vosaka\foroutines\Async&#124;\venndev\vosaka\core\Result&#124;\Fiber** | The function or generator to run in the fiber. |





***

### isFiberCallbackGenerator



```php
private static isFiberCallbackGenerator(callable $callback): bool
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$callback` | **callable** |  |





***


***
> Automatically generated on 2025-07-26
