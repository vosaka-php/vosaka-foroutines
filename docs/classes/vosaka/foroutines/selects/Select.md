***

# Select





* Full name: `\vosaka\foroutines\selects\Select`
* This class is marked as **final** and can't be subclassed
* This class is a **Final class**



## Properties


### cases



```php
private array $cases
```






***

### hasDefault



```php
private bool $hasDefault
```






***

### defaultValue



```php
private mixed $defaultValue
```






***

## Methods


### onSend



```php
public onSend(\vosaka\foroutines\channel\Channel $channel, mixed $value, callable $action): \vosaka\foroutines\selects\Select
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$channel` | **\vosaka\foroutines\channel\Channel** |  |
| `$value` | **mixed** |  |
| `$action` | **callable** |  |





***

### onReceive



```php
public onReceive(\vosaka\foroutines\channel\Channel $channel, callable $action): \vosaka\foroutines\selects\Select
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$channel` | **\vosaka\foroutines\channel\Channel** |  |
| `$action` | **callable** |  |





***

### default



```php
public default(mixed $value = null): \vosaka\foroutines\selects\Select
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$value` | **mixed** |  |





***

### execute



```php
public execute(): mixed
```












***


***
> Automatically generated on 2025-07-27
