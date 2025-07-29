***

# PhpFile

Class PhpFile
This class is used to run a PHP file asynchronously.

It checks if the file is readable and is a valid PHP file before executing it.

* Full name: `\vosaka\foroutines\PhpFile`



## Properties


### file



```php
private string $file
```






***

### args



```php
private array $args
```






***

## Methods


### __construct



```php
public __construct(string $file, array $args = []): mixed
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$file` | **string** |  |
| `$args` | **array** |  |





***

### echoOutputNoResult



```php
private echoOutputNoResult(string $output): void
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$output` | **string** |  |





***

### getResultFromOutput



```php
private getResultFromOutput(string $output): mixed
```








**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$output` | **string** |  |





***

### run

Runs the PHP file asynchronously.

```php
public run(): \vosaka\foroutines\Async
```

It detects the operating system and runs the file accordingly.







**Return Value:**

An Async instance that resolves to the output of the PHP file.



**Throws:**
<p>if the process fails or if there is an error reading the output.</p>

- [`Exception`](../../Exception.md)



***

### runProcess



```php
private runProcess(): mixed
```












***

### runOnUnixWithProcOpen

Alternative Unix implementation using proc_open for more control over the process.

```php
private runOnUnixWithProcOpen(): mixed
```

This method provides real-time output streaming similar to Windows implementation.









**Throws:**
<p>if the process fails or if there is an error reading the output.</p>

- [`Exception`](../../Exception.md)



***

### runWithProcOpen

Runs the PHP file using proc_open for more control over the process.

```php
public runWithProcOpen(): \vosaka\foroutines\Async
```









**Return Value:**

An Async instance that resolves to the output of the PHP file.



**Throws:**
<p>if the process fails or if there is an error reading the output.</p>

- [`Exception`](../../Exception.md)



***


***
> Automatically generated on 2025-07-29
