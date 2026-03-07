<?php

declare(strict_types=1);

namespace vosaka\foroutines\channel;

use Exception;
use vosaka\foroutines\sync\Mutex;

/**
 * File-based inter-process transport for Channel.
 *
 * Manages all file I/O, shared memory, and mutex synchronization for
 * the original file-based inter-process channel transport.  Extracted
 * from Channel to keep each class focused on a single responsibility.
 *
 * Responsibilities:
 *   - Initialize backing file + optional System V shared memory
 *   - Mutex-synchronized load / save of channel state
 *   - File-based send / receive / trySend / tryReceive / close
 *   - State queries (isClosed, isEmpty, isFull, size)
 *   - Cleanup (detach shm, delete file if owner)
 *
 * @internal Used by Channel when transport === "file".
 *           Not part of the public API — call Channel methods instead.
 */
final class ChannelFileTransport
{
    private array $buffer = [];
    private int $capacity;
    private bool $closed = false;

    private string $channelName;
    private ?Mutex $bufferMutex = null;
    private ?Mutex $queueMutex = null;
    private ?int $sharedMemoryKey = null;
    /** @var \SysvSharedMemory|resource|null */
    private $sharedMemory = null;
    private string $tempDir;
    private ?string $channelFile = null;
    private bool $isOwner;

    private ChannelSerializer $serializer;

    public function __construct(
        string $channelName,
        int $capacity,
        bool $isOwner,
        ChannelSerializer $serializer,
        ?string $tempDir = null,
    ) {
        $this->channelName = $channelName;
        $this->capacity = $capacity;
        $this->isOwner = $isOwner;
        $this->serializer = $serializer;
        $this->tempDir = $tempDir ?: sys_get_temp_dir();

        $this->init();
    }

    // ─── Initialization ──────────────────────────────────────────────

    private function init(): void
    {
        // Create mutexes for synchronization
        $this->bufferMutex = new Mutex(
            $this->channelName . "_buffer",
            Mutex::LOCK_AUTO,
            30,
            $this->tempDir,
        );

        $this->queueMutex = new Mutex(
            $this->channelName . "_queue",
            Mutex::LOCK_AUTO,
            30,
            $this->tempDir,
        );

        // Initialize shared memory / file-based storage
        $this->channelFile =
            $this->tempDir .
            DIRECTORY_SEPARATOR .
            "channel_" .
            md5($this->channelName) .
            ".dat";

        // If this is the owner, initialize the file immediately with a stub state
        // This ensures file_exists() checks (e.g. in connectByName) pass and ftok() works,
        // even if we later bypass the file when using sysv shared memory.
        if ($this->isOwner && $this->channelFile) {
            $stub = [
                "name" => $this->channelName,
                "capacity" => $this->capacity,
                "buffer" => [],
                "closed" => false,
                "created_by" => getmypid(),
                "serializer" => $this->serializer->getSerializer(),
            ];
            file_put_contents($this->channelFile, $this->serializer->serializeData($stub));
        }

        // Try to use System V shared memory if available
        if ($this->isSharedMemoryAvailable()) {
            $this->sharedMemoryKey = $this->generateSharedMemoryKey();
            $this->initSharedMemory();
        }

        if ($this->isOwner && $this->channelFile) {
            $this->saveChannelState();
        }
        $this->loadChannelState();

        // Only the owner registers automatic cleanup on shutdown.
        if ($this->isOwner) {
            register_shutdown_function([$this, "cleanup"]);
        }
    }

    // ─── Platform detection ──────────────────────────────────────────

    private function isSharedMemoryAvailable(): bool
    {
        return extension_loaded("sysvshm") &&
            function_exists("shm_attach") &&
            !$this->isWindows();
    }

    private function isWindows(): bool
    {
        return strtoupper(substr(PHP_OS, 0, 3)) === "WIN";
    }

    // ─── Shared memory helpers ───────────────────────────────────────

    private function generateSharedMemoryKey(): int
    {
        if (
            function_exists("ftok") &&
            $this->channelFile &&
            file_exists($this->channelFile)
        ) {
            $key = ftok($this->channelFile, "c");
            if ($key !== -1) {
                return $key;
            }
        }
        return abs(crc32($this->channelName)) % 0x7fffffff;
    }

    private function initSharedMemory(): void
    {
        if ($this->sharedMemoryKey === null) {
            return;
        }

        $size = 1024 * 1024; // 1 MB default
        $this->sharedMemory = @shm_attach($this->sharedMemoryKey, $size, 0666);

        if (!$this->sharedMemory) {
            $this->sharedMemory = null;
        }
    }

    // ─── State persistence ───────────────────────────────────────────

    /**
     * Load channel state (mutex-protected).
     */
    public function loadChannelState(): void
    {
        if (!$this->bufferMutex) {
            return;
        }

        $this->bufferMutex->synchronized(function () {
            $this->loadChannelStateRaw();
        });
    }

    /**
     * Load channel state WITHOUT acquiring the mutex.
     *
     * Call only from code that is already inside a
     * bufferMutex->synchronized() block.
     */
    private function loadChannelStateRaw(): void
    {
        $state = $this->readChannelState();
        if ($state) {
            $this->buffer = $state["buffer"] ?? [];
            $this->closed = $state["closed"] ?? false;
            $this->capacity = $state["capacity"] ?? $this->capacity;
        }
    }

    private function saveChannelState(): void
    {
        $state = [
            "buffer" => $this->buffer,
            "closed" => $this->closed,
            "capacity" => $this->capacity,
            "timestamp" => microtime(true),
            "pid" => getmypid(),
            "name" => $this->channelName,
            "serializer" => $this->serializer->getSerializer(),
            "created_by" => getmypid(),
        ];

        $this->writeChannelState($state);
    }

    private function readChannelState(): ?array
    {
        try {
            // Try shared memory first
            if ($this->sharedMemory) {
                $data = @shm_get_var($this->sharedMemory, 1);
                if ($data !== false) {
                    return $this->serializer->unserializeData($data);
                }
            }

            // Fallback to file
            if ($this->channelFile && file_exists($this->channelFile)) {
                $data = file_get_contents($this->channelFile);
                if ($data !== false && $data !== '') {
                    return $this->serializer->unserializeData($data);
                }
            }
        } catch (Exception $e) {
            error_log("Failed to read channel state: " . $e->getMessage());
        }

        return null;
    }

    private function writeChannelState(array $state): void
    {
        try {
            $serialized = $this->serializer->serializeData($state);

            // Try shared memory first
            if ($this->sharedMemory) {
                if (@shm_put_var($this->sharedMemory, 1, $serialized)) {
                    return;
                }
            }

            // Fallback to file
            if ($this->channelFile) {
                $tempFile = $this->channelFile . ".tmp." . getmypid();
                if (
                    file_put_contents($tempFile, $serialized, LOCK_EX) !== false
                ) {
                    rename($tempFile, $this->channelFile);
                }
            }
        } catch (Exception $e) {
            error_log("Failed to write channel state: " . $e->getMessage());
        }
    }

    // ─── Send ────────────────────────────────────────────────────────

    /**
     * Blocking send (short-lock + retry-outside-lock pattern).
     */
    public function send(mixed $value): void
    {
        if (!$this->bufferMutex) {
            throw new Exception("Buffer mutex not initialized");
        }

        $timeoutMs = 5000;
        $startTime = microtime(true) * 1000;

        while (true) {
            $sent = $this->bufferMutex->synchronized(function () use ($value) {
                $this->loadChannelStateRaw();

                if ($this->closed) {
                    throw new Exception("Channel is closed");
                }

                if (
                    count($this->buffer) < $this->capacity ||
                    $this->capacity === 0
                ) {
                    $this->buffer[] = $value;
                    $this->saveChannelState();
                    return true;
                }

                return false; // buffer full — will retry
            });

            if ($sent) {
                return;
            }

            if (microtime(true) * 1000 - $startTime > $timeoutMs) {
                throw new Exception("Send timeout: buffer is full");
            }

            usleep(1000);
        }
    }

    // ─── Receive ─────────────────────────────────────────────────────

    /**
     * Blocking receive (short-lock + retry-outside-lock pattern).
     */
    public function receive(): mixed
    {
        if (!$this->bufferMutex) {
            throw new Exception("Buffer mutex not initialized");
        }

        $timeoutMs = 100000;
        $startTime = microtime(true) * 1000;

        while (true) {
            $result = $this->bufferMutex->synchronized(function () {
                $this->loadChannelStateRaw();

                if (!empty($this->buffer)) {
                    $value = array_shift($this->buffer);
                    $this->saveChannelState();
                    return ["__found" => true, "value" => $value];
                }

                if ($this->closed) {
                    throw new Exception("Channel is closed and empty");
                }

                return null; // buffer empty — will retry
            });

            if ($result !== null && ($result["__found"] ?? false)) {
                return $result["value"];
            }

            if (microtime(true) * 1000 - $startTime > $timeoutMs) {
                throw new Exception("Receive timeout: no data available");
            }

            usleep(1000);
        }
    }

    // ─── Non-blocking try variants ───────────────────────────────────

    public function trySend(mixed $value): bool
    {
        if (!$this->bufferMutex) {
            return false;
        }

        return $this->bufferMutex->synchronized(function () use ($value) {
            $this->loadChannelStateRaw();

            if ($this->closed) {
                return false;
            }

            if (
                count($this->buffer) < $this->capacity ||
                $this->capacity === 0
            ) {
                $this->buffer[] = $value;
                $this->saveChannelState();
                return true;
            }

            return false;
        });
    }

    public function tryReceive(): mixed
    {
        if (!$this->bufferMutex) {
            return null;
        }

        return $this->bufferMutex->synchronized(function () {
            $this->loadChannelStateRaw();

            if (!empty($this->buffer)) {
                $value = array_shift($this->buffer);
                $this->saveChannelState();
                return $value;
            }

            return null;
        });
    }

    // ─── Close ───────────────────────────────────────────────────────

    public function close(): void
    {
        if ($this->bufferMutex) {
            $this->bufferMutex->synchronized(function () {
                $this->loadChannelStateRaw();
                $this->closed = true;
                $this->saveChannelState();
            });
        }
    }

    // ─── State queries ───────────────────────────────────────────────

    public function isClosed(): bool
    {
        if ($this->bufferMutex) {
            return $this->bufferMutex->synchronized(function () {
                $this->loadChannelStateRaw();
                return $this->closed;
            });
        }
        return $this->closed;
    }

    public function isEmpty(): bool
    {
        if ($this->bufferMutex) {
            return $this->bufferMutex->synchronized(function () {
                $this->loadChannelStateRaw();
                return empty($this->buffer);
            });
        }
        return empty($this->buffer);
    }

    public function isFull(): bool
    {
        if ($this->bufferMutex) {
            return $this->bufferMutex->synchronized(function () {
                $this->loadChannelStateRaw();
                return count($this->buffer) >= $this->capacity &&
                    $this->capacity > 0;
            });
        }
        return count($this->buffer) >= $this->capacity && $this->capacity > 0;
    }

    public function size(): int
    {
        if ($this->bufferMutex) {
            return $this->bufferMutex->synchronized(function () {
                $this->loadChannelStateRaw();
                return count($this->buffer);
            });
        }
        return count($this->buffer);
    }

    // ─── Info ────────────────────────────────────────────────────────

    public function getInfo(): array
    {
        return [
            "capacity" => $this->capacity,
            "size" => $this->size(),
            "closed" => $this->isClosed(),
            "inter_process" => true,
            "channel_name" => $this->channelName,
            "transport" => "file",
            "serializer" => $this->serializer->getSerializer(),
            "shared_memory_available" => $this->isSharedMemoryAvailable(),
            "shared_memory_key" => $this->sharedMemoryKey,
            "channel_file" => $this->channelFile,
            "platform" => PHP_OS,
        ];
    }

    // ─── Cleanup ─────────────────────────────────────────────────────

    /**
     * Clean up inter-process resources.
     *
     * - Shared memory is always detached (both owner and connector).
     * - The backing file is only deleted by the owner.
     */
    public function cleanup(): void
    {
        if ($this->sharedMemory) {
            try {
                @shm_detach($this->sharedMemory);
            } catch (\Error) {
                // Already destroyed/detached — ignore
            }
            $this->sharedMemory = null;
        }

        if (
            $this->isOwner &&
            $this->channelFile &&
            file_exists($this->channelFile)
        ) {
            @unlink($this->channelFile);
        }
        $this->channelFile = null;
    }

    // ─── Accessors ───────────────────────────────────────────────────

    public function isOwner(): bool
    {
        return $this->isOwner;
    }

    public function setOwner(bool $isOwner): void
    {
        $this->isOwner = $isOwner;
    }

    public function getChannelName(): string
    {
        return $this->channelName;
    }

    public function getChannelFile(): ?string
    {
        return $this->channelFile;
    }

    public function getSharedMemoryKey(): ?int
    {
        return $this->sharedMemoryKey;
    }

    public function getCapacity(): int
    {
        return $this->capacity;
    }
}
