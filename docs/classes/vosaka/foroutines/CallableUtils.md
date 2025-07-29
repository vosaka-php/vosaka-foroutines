***

# CallableUtils





* Full name: `\vosaka\foroutines\CallableUtils`
* This class is marked as **final** and can't be subclassed
* This class is a **Final class**




## Methods


### makeCallable



```php
public static makeCallable(callable|\Generator|\vosaka\foroutines\Async|\venndev\vosaka\core\Result|\Fiber $callable): callable
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$callable` | **callable&#124;\Generator&#124;\vosaka\foroutines\Async&#124;\venndev\vosaka\core\Result&#124;\Fiber** |  |





***

### fiberToCallable



```php
public static fiberToCallable(\Fiber $fiber): callable
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$fiber` | **\Fiber** |  |





***

### generatorToCallable



```php
public static generatorToCallable(\Generator $generator): callable
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$generator` | **\Generator** |  |





***

### makeCallableForThread



```php
public static makeCallableForThread(callable $callable, array $includedFiles): \Closure
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$callable` | **callable** |  |
| `$includedFiles` | **array** |  |





***


***
> Automatically generated on 2025-07-29
