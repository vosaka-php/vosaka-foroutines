***

# Sleep

Sleep for a specified number of seconds.

This function is designed to be used within a Foroutine context.

* Full name: `\vosaka\foroutines\Sleep`
* This class is marked as **final** and can't be subclassed
* This class is a **Final class**




## Methods


### new

Sleeps for the specified number of seconds.

```php
public static new(float $seconds): void
```

This method will yield control back to the event loop while sleeping,
allowing other Foroutines to run concurrently.

* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$seconds` | **float** | The number of seconds to sleep. Must be a non-negative integer. |




**Throws:**
<p>if the sleep duration is negative.</p>

- [`InvalidArgumentException`](../../InvalidArgumentException.md)



***


***
> Automatically generated on 2025-07-26
