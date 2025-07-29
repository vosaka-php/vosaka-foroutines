***

# Delay





* Full name: `\vosaka\foroutines\Delay`
* This class is marked as **final** and can't be subclassed
* This class is a **Final class**




## Methods


### new

Delays the execution of the current Foroutine for a specified number of milliseconds.

```php
public static new(int $ms): void
```

If called outside of a Foroutine, it will run the event loop until the delay is complete.

* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$ms` | **int** | The delay duration in milliseconds. |




**Throws:**
<p>if $ms is less than or equal to 0.</p>

- [`InvalidArgumentException`](../../InvalidArgumentException.md)



***


***
> Automatically generated on 2025-07-29
