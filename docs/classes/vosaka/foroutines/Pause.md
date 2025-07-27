***

# Pause

Pause the current Foroutine execution and yield control back to the event loop.

This allows other Foroutines to run while the current foroutine is paused.

* Full name: `\vosaka\foroutines\Pause`
* This class is marked as **final** and can't be subclassed
* This class is a **Final class**




## Methods


### new

Pauses the current Foroutine execution.

```php
public static new(): void
```

This method should be called within a Foroutine context to yield control
back to the event loop, allowing other Foroutines to run concurrently.

* This method is **static**.







**Throws:**
<p>if called outside of a Foroutine scope.</p>

- [`RuntimeException`](../../RuntimeException.md)



***


***
> Automatically generated on 2025-07-27
