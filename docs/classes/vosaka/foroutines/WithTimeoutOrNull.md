***

# WithTimeoutOrNull





* Full name: `\vosaka\foroutines\WithTimeoutOrNull`
* This class is marked as **final** and can't be subclassed
* This class is a **Final class**




## Methods


### new

Runs a Foroutine with specified timeout in milliseconds.

```php
public static new(int $timeoutMs, callable $callable): mixed|null
```

Returns null if the timeout is exceeded.

* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$timeoutMs` | **int** | Timeout in milliseconds |
| `$callable` | **callable** | The function to execute in Foroutine |


**Return Value:**

Result of the block execution or null if timed out




***


***
> Automatically generated on 2025-07-28
