***

# EventLoop





* Full name: `\vosaka\foroutines\EventLoop`
* This class is marked as **final** and can't be subclassed
* This class is a **Final class**



## Properties


### queue



```php
private static ?\SplPriorityQueue $queue
```



* This property is **static**.


***

## Methods


### __construct



```php
public __construct(): mixed
```












***

### init



```php
public static init(): void
```



* This method is **static**.








***

### add



```php
public static add(\Fiber $fiber): void
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$fiber` | **\Fiber** |  |





***

### runNext



```php
public static runNext(): ?\Fiber
```



* This method is **static**.








***

### runAll



```php
private static runAll(): void
```



* This method is **static**.








***


***
> Automatically generated on 2025-07-28
