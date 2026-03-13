# Reference: Channel Transports

VOsaka Foroutines provides four distinct transport mechanisms for Channels, each suited for different use cases.

| Transport | Type | Scope | Best For |
| :--- | :--- | :--- | :--- |
| **In-process** | Memory | Same process (Fibers) | Communication between fibers in the same script. |
| **Socket Pool** | Socket | Inter-process (IPC) | **Default.** High-performance communication via shared broker. |
| **Socket Per-channel**| Socket | Inter-process (IPC) | Legacy/Isolated communication. |
| **File-based** | File | Inter-process (IPC) | Environments where sockets are restricted. |

## Usage Patterns

### In-process (Fastest)
```php
use vosaka\foroutines\channel\Channel;
$ch = Channel::new(10); // Standard 'new' defaults to in-process
```

### IPC via Sockets (Recommended for Workers)
```php
use vosaka\foroutines\channel\Channel;
$ch = Channel::create(10); // 'create' uses the shared broker pool
```

### File-based IPC
```php
use vosaka\foroutines\channel\Channel;
$ch = Channel::newInterProcess("my_ch", 10);
```
