***

# Channels





* Full name: `\vosaka\foroutines\channel\Channels`
* This class is marked as **final** and can't be subclassed
* This class is a **Final class**




## Methods


### new



```php
public static new(): \vosaka\foroutines\channel\Channel
```



* This method is **static**.








***

### createBuffered



```php
public static createBuffered(int $capacity): \vosaka\foroutines\channel\Channel
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$capacity` | **int** |  |





***

### from



```php
public static from(array $items): \vosaka\foroutines\channel\Channel
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$items` | **array** |  |





***

### merge



```php
public static merge(\vosaka\foroutines\channel\Channel $channels): \vosaka\foroutines\channel\Channel
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$channels` | **\vosaka\foroutines\channel\Channel** |  |





***

### map



```php
public static map(\vosaka\foroutines\channel\Channel $input, callable $transform): \vosaka\foroutines\channel\Channel
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$input` | **\vosaka\foroutines\channel\Channel** |  |
| `$transform` | **callable** |  |





***

### filter



```php
public static filter(\vosaka\foroutines\channel\Channel $input, callable $predicate): \vosaka\foroutines\channel\Channel
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$input` | **\vosaka\foroutines\channel\Channel** |  |
| `$predicate` | **callable** |  |





***

### take



```php
public static take(\vosaka\foroutines\channel\Channel $input, int $count): \vosaka\foroutines\channel\Channel
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$input` | **\vosaka\foroutines\channel\Channel** |  |
| `$count` | **int** |  |





***


***
> Automatically generated on 2025-07-29
