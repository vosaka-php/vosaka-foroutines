***

# Repeat





* Full name: `\vosaka\foroutines\Repeat`
* This class is marked as **final** and can't be subclassed
* This class is a **Final class**




## Methods


### new

Repeats a callable a specified number of times.

```php
public static new(int $count, callable $callable): void
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$count` | **int** | The number of times to repeat the callable. |
| `$callable` | **callable** | The callable to repeat. |




**Throws:**
<p>If the count is less than or equal to zero.</p>

- [`InvalidArgumentException`](../../InvalidArgumentException.md)



***


***
> Automatically generated on 2025-07-27
