***

# FlowInterface

Base interface for all Flow types



* Full name: `\vosaka\foroutines\flow\FlowInterface`



## Methods


### collect



```php
public collect(callable $collector): void
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$collector` | **callable** |  |





***

### map



```php
public map(callable $transform): \vosaka\foroutines\flow\FlowInterface
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$transform` | **callable** |  |





***

### filter



```php
public filter(callable $predicate): \vosaka\foroutines\flow\FlowInterface
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$predicate` | **callable** |  |





***

### onEach



```php
public onEach(callable $action): \vosaka\foroutines\flow\FlowInterface
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$action` | **callable** |  |





***

### catch



```php
public catch(callable $handler): \vosaka\foroutines\flow\FlowInterface
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$handler` | **callable** |  |





***


***
> Automatically generated on 2025-07-27
