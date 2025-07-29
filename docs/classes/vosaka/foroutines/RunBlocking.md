***

# RunBlocking

RunBlocking is a utility class that allows you to run multiple fibers synchronously
until all of them complete. It is useful for testing or when you need to block the
current thread until all asynchronous tasks are finished.



* Full name: `\vosaka\foroutines\RunBlocking`
* This class is marked as **final** and can't be subclassed
* This class is a **Final class**




## Methods


### new

Runs multiple fibers synchronously and returns their results.

```php
public static new(callable|\Generator|\vosaka\foroutines\Async|\venndev\vosaka\core\Result|\Fiber $callable, \vosaka\foroutines\Dispatchers $dispatchers = Dispatchers::DEFAULT, callable|\Generator|\vosaka\foroutines\Async|\venndev\vosaka\core\Result|\Fiber $fiber): array
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$callable` | **callable&#124;\Generator&#124;\vosaka\foroutines\Async&#124;\venndev\vosaka\core\Result&#124;\Fiber** |  |
| `$dispatchers` | **\vosaka\foroutines\Dispatchers** |  |
| `$fiber` | **callable&#124;\Generator&#124;\vosaka\foroutines\Async&#124;\venndev\vosaka\core\Result&#124;\Fiber** | The fibers to run. |


**Return Value:**

The results of the completed fibers.




***


***
> Automatically generated on 2025-07-29
