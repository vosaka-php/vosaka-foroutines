***

# Delay





* Full name: `\vosaka\foroutines\Delay`
* This class is marked as **final** and can't be subclassed
* This class is a **Final class**




## Methods


### new

Delays the execution of the current fiber for a specified number of seconds.

```php
public static new(float $seconds): void
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$seconds` | **float** | The number of seconds to delay. Must be a non-negative float. |




**Throws:**
<p>If the provided duration is negative.</p>

- [`InvalidArgumentException`](../../InvalidArgumentException.md)



***


***
> Automatically generated on 2025-07-28
