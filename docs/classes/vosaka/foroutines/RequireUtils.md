***

# RequireUtils





* Full name: `\vosaka\foroutines\RequireUtils`
* This class is marked as **final** and can't be subclassed
* This class is a **Final class**




## Methods


### safeRequireOnce

Safe require_once function - only requires files that haven't been included yet
Skips already included files and current file

```php
public static safeRequireOnce(string|array $files, bool $silent = false): array
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$files` | **string&#124;array** | File path or array of file paths |
| `$silent` | **bool** | Whether to suppress output messages (default: false) |


**Return Value:**

Array of results with information about each file




***

### getFilesToRequire

Get only files that would be required (without actually requiring them)
Useful for debugging or validation

```php
public static getFilesToRequire(string|array $files): array
```



* This method is **static**.




**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$files` | **string&#124;array** | File path or array of file paths |


**Return Value:**

Array of files that would be required




***


***
> Automatically generated on 2025-07-29
