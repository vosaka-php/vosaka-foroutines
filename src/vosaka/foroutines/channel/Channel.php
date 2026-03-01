<?php

declare(strict_types=1);

namespace vosaka\foroutines\channel;

use Exception;
use Fiber;
use ReflectionClass;
use ReflectionProperty;
use ReflectionMethod;
use JsonSerializable;
use Serializable;
use vosaka\foroutines\Pause;
use vosaka\foroutines\sync\Mutex;
use IteratorAggregate;

final class Channel implements IteratorAggregate
{
    private array $buffer = [];
    private int $capacity;
    private array $sendQueue = [];
    private array $receiveQueue = [];
    private bool $closed = false;

    // Inter-process support
    private bool $interProcess;
    private ?string $channelName = null;
    private ?Mutex $bufferMutex = null;
    private ?Mutex $queueMutex = null;
    private ?int $sharedMemoryKey = null;
    private $sharedMemory = null;
    private string $tempDir;
    private ?string $channelFile = null;

    // Serialization options
    private string $serializer;
    private bool $preserveObjectTypes;

    const SERIALIZER_SERIALIZE = "serialize";
    const SERIALIZER_JSON = "json";
    const SERIALIZER_MSGPACK = "msgpack";
    const SERIALIZER_IGBINARY = "igbinary";

    public function __construct(
        int $capacity = 0,
        bool $interProcess = false,
        ?string $channelName = null,
        string $serializer = self::SERIALIZER_SERIALIZE,
        ?string $tempDir = null,
        bool $preserveObjectTypes = true,
    ) {
        if ($capacity < 0) {
            throw new Exception("Channel capacity cannot be negative");
        }

        $this->capacity = $capacity;
        $this->interProcess = $interProcess;
        $this->serializer = $serializer;
        $this->preserveObjectTypes = $preserveObjectTypes;
        $this->tempDir = $tempDir ?: sys_get_temp_dir();

        if ($this->interProcess) {
            $this->channelName = $channelName ?: "channel_" . uniqid();
            $this->initInterProcessSupport();
        }
    }

    /**
     * Initialize inter-process support
     */
    private function initInterProcessSupport(): void
    {
        if (!$this->channelName) {
            throw new Exception(
                "Channel name is required for inter-process channels",
            );
        }

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

        // Initialize shared memory/file-based storage
        $this->channelFile =
            $this->tempDir .
            DIRECTORY_SEPARATOR .
            "channel_" .
            md5($this->channelName) .
            ".dat";

        // Try to use System V shared memory if available
        if ($this->isSharedMemoryAvailable()) {
            $this->sharedMemoryKey = $this->generateSharedMemoryKey();
            $this->initSharedMemory();
        }

        // Initialize channel state
        $this->loadChannelState();

        // Register cleanup
        register_shutdown_function([$this, "cleanup"]);
    }

    /**
     * Connect to existing inter-process channel
     */
    public static function connect(
        string $channelName,
        string $serializer = self::SERIALIZER_SERIALIZE,
        ?string $tempDir = null,
        bool $preserveObjectTypes = true,
    ): Channel {
        $tempDir = $tempDir ?: sys_get_temp_dir();
        $channelFile =
            $tempDir .
            DIRECTORY_SEPARATOR .
            "channel_" .
            md5($channelName) .
            ".dat";

        if (!file_exists($channelFile)) {
            throw new Exception("Channel '{$channelName}' does not exist");
        }

        $channel = new self(
            0,
            true,
            $channelName,
            $serializer,
            $tempDir,
            $preserveObjectTypes,
        );
        return $channel;
    }

    /**
     * Check if System V shared memory is available
     */
    private function isSharedMemoryAvailable(): bool
    {
        return extension_loaded("sysvshm") &&
            function_exists("shm_attach") &&
            !$this->isWindows();
    }

    /**
     * Check if running on Windows
     */
    private function isWindows(): bool
    {
        return strtoupper(substr(PHP_OS, 0, 3)) === "WIN";
    }

    /**
     * Generate shared memory key
     */
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
        return abs(crc32($this->channelName ?? "default")) % 0x7fffffff;
    }

    /**
     * Initialize shared memory segment
     */
    private function initSharedMemory(): void
    {
        if ($this->sharedMemoryKey === null) {
            return;
        }

        $size = 1024 * 1024; // 1MB default
        $this->sharedMemory = @shm_attach($this->sharedMemoryKey, $size, 0666);

        if (!$this->sharedMemory) {
            // Fallback to file-based storage
            $this->sharedMemory = null;
        }
    }

    /**
     * Load channel state from persistent storage
     */
    private function loadChannelState(): void
    {
        if (!$this->interProcess || !$this->bufferMutex) {
            return;
        }

        $this->bufferMutex->synchronized(function () {
            $state = $this->readChannelState();
            if ($state) {
                $this->buffer = $state["buffer"] ?? [];
                $this->closed = $state["closed"] ?? false;
                $this->capacity = $state["capacity"] ?? $this->capacity;
            }
        });
    }

    /**
     * Save channel state to persistent storage
     */
    private function saveChannelState(): void
    {
        if (!$this->interProcess) {
            return;
        }

        $state = [
            "buffer" => $this->buffer,
            "closed" => $this->closed,
            "capacity" => $this->capacity,
            "timestamp" => microtime(true),
            "pid" => getmypid(),
            "name" => $this->channelName,
            "serializer" => $this->serializer,
            "created_by" => getmypid(),
        ];

        $this->writeChannelState($state);
    }

    /**
     * Read channel state from storage
     */
    private function readChannelState(): ?array
    {
        try {
            // Try shared memory first
            if ($this->sharedMemory) {
                $data = @shm_get_var($this->sharedMemory, 1);
                if ($data !== false) {
                    return $this->unserializeData($data);
                }
            }

            // Fallback to file
            if ($this->channelFile && file_exists($this->channelFile)) {
                $data = file_get_contents($this->channelFile);
                if ($data !== false) {
                    return $this->unserializeData($data);
                }
            }
        } catch (Exception $e) {
            error_log("Failed to read channel state: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Write channel state to storage
     */
    private function writeChannelState(array $state): void
    {
        try {
            $serialized = $this->serializeData($state);

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

    /**
     * Serialize data based on configured serializer
     */
    private function serializeData($data): string
    {
        try {
            switch ($this->serializer) {
                case self::SERIALIZER_JSON:
                    if (is_object($data) && $this->preserveObjectTypes) {
                        $serialized = [
                            "__class__" => get_class($data),
                            "__data__" => $this->objectToArray($data),
                        ];
                        return json_encode($serialized, JSON_THROW_ON_ERROR);
                    }
                    return json_encode($data, JSON_THROW_ON_ERROR);

                case self::SERIALIZER_MSGPACK:
                    if (function_exists("msgpack_pack")) {
                        return \msgpack_pack($data);
                    }
                    return serialize($data);

                case self::SERIALIZER_IGBINARY:
                    if (function_exists("igbinary_serialize")) {
                        return \igbinary_serialize($data);
                    }
                    return serialize($data);

                case self::SERIALIZER_SERIALIZE:
                default:
                    return serialize($data);
            }
        } catch (Exception $e) {
            throw new Exception(
                "Failed to serialize data: " . $e->getMessage(),
            );
        }
    }

    /**
     * Unserialize data based on configured serializer
     */
    private function unserializeData(string $data)
    {
        try {
            switch ($this->serializer) {
                case self::SERIALIZER_JSON:
                    $decoded = json_decode(
                        $data,
                        true,
                        512,
                        JSON_THROW_ON_ERROR,
                    );

                    if (
                        $this->preserveObjectTypes &&
                        is_array($decoded) &&
                        isset($decoded["__class__"]) &&
                        isset($decoded["__data__"])
                    ) {
                        return $this->arrayToObject(
                            $decoded["__class__"],
                            $decoded["__data__"],
                        );
                    }

                    return $decoded;

                case self::SERIALIZER_MSGPACK:
                    if (function_exists("msgpack_unpack")) {
                        return \msgpack_unpack($data);
                    }
                    return unserialize($data);

                case self::SERIALIZER_IGBINARY:
                    if (function_exists("igbinary_unserialize")) {
                        return \igbinary_unserialize($data);
                    }
                    return unserialize($data);

                case self::SERIALIZER_SERIALIZE:
                default:
                    return unserialize($data);
            }
        } catch (Exception $e) {
            throw new Exception(
                "Failed to unserialize data: " . $e->getMessage(),
            );
        }
    }

    /**
     * Convert object to array for JSON serialization
     */
    private function objectToArray(object $object): array
    {
        if ($object instanceof JsonSerializable) {
            return $object->jsonSerialize();
        }

        $reflection = new ReflectionClass($object);
        $data = [];

        // Get public properties
        foreach (
            $reflection->getProperties(ReflectionProperty::IS_PUBLIC)
            as $property
        ) {
            $data[$property->getName()] = $property->getValue($object);
        }

        // Get properties via getter methods
        foreach (
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC)
            as $method
        ) {
            $methodName = $method->getName();
            if (
                strpos($methodName, "get") === 0 &&
                $method->getNumberOfParameters() === 0
            ) {
                $propertyName = lcfirst(substr($methodName, 3));
                if (!isset($data[$propertyName])) {
                    try {
                        $data[$propertyName] = $method->invoke($object);
                    } catch (Exception) {
                        // Skip if getter throws exception
                    }
                }
            }
        }

        return $data;
    }

    /**
     * Convert array back to object for JSON deserialization
     */
    private function arrayToObject(string $className, array $data): object
    {
        if (!class_exists($className)) {
            throw new Exception("Class {$className} does not exist");
        }

        $reflection = new ReflectionClass($className);

        try {
            $object = $reflection->newInstanceWithoutConstructor();

            foreach (
                $reflection->getProperties(ReflectionProperty::IS_PUBLIC)
                as $property
            ) {
                $propertyName = $property->getName();
                if (array_key_exists($propertyName, $data)) {
                    $property->setValue($object, $data[$propertyName]);
                }
            }

            foreach ($data as $key => $value) {
                $setterName = "set" . ucfirst($key);
                if ($reflection->hasMethod($setterName)) {
                    $method = $reflection->getMethod($setterName);
                    if (
                        $method->isPublic() &&
                        $method->getNumberOfParameters() >= 1
                    ) {
                        try {
                            $method->invoke($object, $value);
                        } catch (Exception) {
                            // Skip if setter throws exception
                        }
                    }
                }
            }

            return $object;
        } catch (Exception) {
            try {
                $constructor = $reflection->getConstructor();
                if (
                    $constructor &&
                    $constructor->getNumberOfParameters() === 0
                ) {
                    $object = $reflection->newInstance();
                } else {
                    $object = $this->createObjectWithConstructor(
                        $reflection,
                        $data,
                    );
                }

                foreach (
                    $reflection->getProperties(ReflectionProperty::IS_PUBLIC)
                    as $property
                ) {
                    $propertyName = $property->getName();
                    if (array_key_exists($propertyName, $data)) {
                        $property->setValue($object, $data[$propertyName]);
                    }
                }

                return $object;
            } catch (Exception $e2) {
                throw new Exception(
                    "Failed to recreate object of class {$className}: " .
                        $e2->getMessage(),
                );
            }
        }
    }

    /**
     * Create object with constructor parameters
     */
    private function createObjectWithConstructor(
        ReflectionClass $reflection,
        array $data,
    ): object {
        $constructor = $reflection->getConstructor();
        if (!$constructor) {
            return $reflection->newInstance();
        }

        $parameters = $constructor->getParameters();
        $args = [];

        foreach ($parameters as $param) {
            $paramName = $param->getName();

            if (array_key_exists($paramName, $data)) {
                $args[] = $data[$paramName];
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } elseif ($param->allowsNull()) {
                $args[] = null;
            } else {
                $variations = [
                    $paramName,
                    lcfirst($paramName),
                    ucfirst($paramName),
                    strtolower($paramName),
                ];

                $found = false;
                foreach ($variations as $variation) {
                    if (array_key_exists($variation, $data)) {
                        $args[] = $data[$variation];
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    throw new Exception(
                        "Cannot find value for required parameter: {$paramName}",
                    );
                }
            }
        }

        return $reflection->newInstanceArgs($args);
    }

    public function canSerialize($data): bool
    {
        try {
            $this->serializeData($data);
            return true;
        } catch (Exception) {
            return false;
        }
    }

    public function getSerializationInfo($data): array
    {
        $info = [
            "type" => gettype($data),
            "serializable" => $this->canSerialize($data),
            "size_bytes" => 0,
            "serializer" => $this->serializer,
            "preserve_object_types" => $this->preserveObjectTypes,
        ];

        if (is_object($data)) {
            $info["class"] = get_class($data);
            $info["implements_json_serializable"] =
                $data instanceof JsonSerializable;
            $info["implements_serializable"] = $data instanceof Serializable;

            $reflection = new ReflectionClass($data);
            $info["has_sleep_method"] = $reflection->hasMethod("__sleep");
            $info["has_wakeup_method"] = $reflection->hasMethod("__wakeup");
            $info["has_serialize_method"] = $reflection->hasMethod(
                "__serialize",
            );
            $info["has_unserialize_method"] = $reflection->hasMethod(
                "__unserialize",
            );
        }

        if ($info["serializable"]) {
            try {
                $serialized = $this->serializeData($data);
                $info["size_bytes"] = strlen($serialized);
            } catch (Exception) {
                $info["size_bytes"] = 0;
            }
        }

        return $info;
    }

    public function testData($data): array
    {
        $result = [
            "compatible" => false,
            "info" => $this->getSerializationInfo($data),
            "errors" => [],
            "warnings" => [],
        ];

        try {
            $serialized = $this->serializeData($data);
            $unserialized = $this->unserializeData($serialized);

            $result["compatible"] = true;
            $result["serialized_size"] = strlen($serialized);

            if (is_object($data) && is_object($unserialized)) {
                if (get_class($data) !== get_class($unserialized)) {
                    $result["warnings"][] =
                        "Object class changed during serialization";
                }
            }

            if (strlen($serialized) > 1024 * 1024) {
                $result["warnings"][] =
                    "Large data size may impact performance";
            }
        } catch (Exception $e) {
            $result["errors"][] = $e->getMessage();
        }

        return $result;
    }

    public static function getRecommendedSerializer($data): string
    {
        if (is_object($data)) {
            if (function_exists("igbinary_serialize")) {
                return self::SERIALIZER_IGBINARY;
            }
            return self::SERIALIZER_SERIALIZE;
        }

        if (is_array($data) || is_scalar($data)) {
            if (function_exists("msgpack_pack")) {
                return self::SERIALIZER_MSGPACK;
            }
            return self::SERIALIZER_JSON;
        }

        return self::SERIALIZER_SERIALIZE;
    }

    public static function new(int $capacity = 0): Channel
    {
        return new self($capacity);
    }

    public static function newInterProcess(
        string $channelName,
        int $capacity = 0,
        string $serializer = self::SERIALIZER_SERIALIZE,
        ?string $tempDir = null,
        bool $preserveObjectTypes = true,
    ): Channel {
        return new self(
            $capacity,
            true,
            $channelName,
            $serializer,
            $tempDir,
            $preserveObjectTypes,
        );
    }

    public function send(mixed $value): void
    {
        if ($this->interProcess) {
            $this->sendInterProcess($value);
        } else {
            $this->sendInProcess($value);
        }
    }

    private function sendInProcess(mixed $value): void
    {
        if ($this->closed) {
            throw new Exception("Channel is closed");
        }

        if (!empty($this->receiveQueue)) {
            $receiver = array_shift($this->receiveQueue);
            $receiver["fiber"]->resume($value);
            return;
        }

        if (count($this->buffer) < $this->capacity || $this->capacity === 0) {
            $this->buffer[] = $value;
            return;
        }

        $currentFiber = Fiber::getCurrent();
        if ($currentFiber === null) {
            throw new Exception("send() must be called from within a Fiber");
        }

        $this->sendQueue[] = [
            "fiber" => $currentFiber,
            "value" => $value,
        ];

        Fiber::suspend();
    }

    private function sendInterProcess(mixed $value): void
    {
        if (!$this->bufferMutex) {
            throw new Exception("Buffer mutex not initialized");
        }

        $this->bufferMutex->synchronized(function () use ($value) {
            $this->loadChannelState();

            if ($this->closed) {
                throw new Exception("Channel is closed");
            }

            if (
                count($this->buffer) < $this->capacity ||
                $this->capacity === 0
            ) {
                $this->buffer[] = $value;
                $this->saveChannelState();
                $this->notifyReceivers();
                return;
            }

            $this->waitForSpace();
            $this->buffer[] = $value;
            $this->saveChannelState();
            $this->notifyReceivers();
        });
    }

    private function waitForSpace(int $timeoutMs = 5000): void
    {
        $startTime = microtime(true) * 1000;

        while (true) {
            $this->loadChannelState();

            if ($this->closed) {
                throw new Exception("Channel is closed");
            }

            if (
                count($this->buffer) < $this->capacity ||
                $this->capacity === 0
            ) {
                return;
            }

            if (microtime(true) * 1000 - $startTime > $timeoutMs) {
                throw new Exception("Send timeout: buffer is full");
            }

            usleep(1000);
        }
    }

    public function receive(): mixed
    {
        if ($this->interProcess) {
            return $this->receiveInterProcess();
        } else {
            return $this->receiveInProcess();
        }
    }

    private function receiveInProcess(): mixed
    {
        if (!empty($this->buffer)) {
            $value = array_shift($this->buffer);

            if (!empty($this->sendQueue)) {
                $sender = array_shift($this->sendQueue);
                $this->buffer[] = $sender["value"];
                $sender["fiber"]->resume();
            }

            return $value;
        }

        if ($this->closed) {
            throw new Exception("Channel is closed and empty");
        }

        $currentFiber = Fiber::getCurrent();
        if ($currentFiber === null) {
            throw new Exception("receive() must be called from within a Fiber");
        }

        $this->receiveQueue[] = [
            "fiber" => $currentFiber,
        ];

        return Fiber::suspend();
    }

    private function receiveInterProcess(): mixed
    {
        if (!$this->bufferMutex) {
            throw new Exception("Buffer mutex not initialized");
        }

        return $this->bufferMutex->synchronized(function () {
            $this->loadChannelState();

            if (!empty($this->buffer)) {
                $value = array_shift($this->buffer);
                $this->saveChannelState();
                return $value;
            }

            if ($this->closed) {
                throw new Exception("Channel is closed and empty");
            }

            return $this->waitForData();
        });
    }

    private function waitForData(int $timeoutMs = 100000)
    {
        $startTime = microtime(true) * 1000;

        while (true) {
            $this->loadChannelState();

            if (!empty($this->buffer)) {
                $value = array_shift($this->buffer);
                $this->saveChannelState();
                return $value;
            }

            if ($this->closed) {
                throw new Exception("Channel is closed and empty");
            }

            if (microtime(true) * 1000 - $startTime > $timeoutMs) {
                throw new Exception("Receive timeout: no data available");
            }

            usleep(1000);
        }
    }

    private function notifyReceivers(): void
    {
        // Placeholder for IPC notification mechanism
    }

    public function trySend(mixed $value): bool
    {
        if ($this->interProcess) {
            return $this->trySendInterProcess($value);
        }

        if ($this->closed) {
            return false;
        }

        if (!empty($this->receiveQueue)) {
            $receiver = array_shift($this->receiveQueue);
            $receiver["fiber"]->resume($value);
            return true;
        }

        if (count($this->buffer) < $this->capacity || $this->capacity === 0) {
            $this->buffer[] = $value;
            return true;
        }

        return false;
    }

    private function trySendInterProcess(mixed $value): bool
    {
        if (!$this->bufferMutex) {
            return false;
        }

        return $this->bufferMutex->synchronized(function () use ($value) {
            $this->loadChannelState();

            if ($this->closed) {
                return false;
            }

            if (
                count($this->buffer) < $this->capacity ||
                $this->capacity === 0
            ) {
                $this->buffer[] = $value;
                $this->saveChannelState();
                $this->notifyReceivers();
                return true;
            }

            return false;
        });
    }

    public function tryReceive(): mixed
    {
        if ($this->interProcess) {
            return $this->tryReceiveInterProcess();
        }

        if (!empty($this->buffer)) {
            $value = array_shift($this->buffer);

            if (!empty($this->sendQueue)) {
                $sender = array_shift($this->sendQueue);
                $this->buffer[] = $sender["value"];
                $sender["fiber"]->resume();
            }

            return $value;
        }

        return null;
    }

    private function tryReceiveInterProcess(): mixed
    {
        if (!$this->bufferMutex) {
            return null;
        }

        return $this->bufferMutex->synchronized(function () {
            $this->loadChannelState();

            if (!empty($this->buffer)) {
                $value = array_shift($this->buffer);
                $this->saveChannelState();
                return $value;
            }

            return null;
        });
    }

    public function close(): void
    {
        if ($this->interProcess) {
            $this->closeInterProcess();
        } else {
            $this->closeInProcess();
        }
    }

    private function closeInProcess(): void
    {
        $this->closed = true;

        foreach ($this->receiveQueue as $receiver) {
            $receiver["fiber"]->throw(new Exception("Channel is closed"));
        }

        foreach ($this->sendQueue as $sender) {
            $sender["fiber"]->throw(new Exception("Channel is closed"));
        }

        $this->receiveQueue = [];
        $this->sendQueue = [];
    }

    private function closeInterProcess(): void
    {
        if ($this->bufferMutex) {
            $this->bufferMutex->synchronized(function () {
                $this->closed = true;
                $this->saveChannelState();
            });
        }
    }

    public function isClosed(): bool
    {
        if ($this->interProcess && $this->bufferMutex) {
            return $this->bufferMutex->synchronized(function () {
                $this->loadChannelState();
                return $this->closed;
            });
        }

        return $this->closed;
    }

    public function isEmpty(): bool
    {
        if ($this->interProcess && $this->bufferMutex) {
            return $this->bufferMutex->synchronized(function () {
                $this->loadChannelState();
                return empty($this->buffer);
            });
        }

        return empty($this->buffer);
    }

    public function isFull(): bool
    {
        if ($this->interProcess && $this->bufferMutex) {
            return $this->bufferMutex->synchronized(function () {
                $this->loadChannelState();
                return count($this->buffer) >= $this->capacity &&
                    $this->capacity > 0;
            });
        }

        return count($this->buffer) >= $this->capacity && $this->capacity > 0;
    }

    public function size(): int
    {
        if ($this->interProcess && $this->bufferMutex) {
            return $this->bufferMutex->synchronized(function () {
                $this->loadChannelState();
                return count($this->buffer);
            });
        }

        return count($this->buffer);
    }

    public function getIterator(): ChannelIterator
    {
        return new ChannelIterator($this);
    }

    public function getInfo(): array
    {
        return [
            "capacity" => $this->capacity,
            "size" => $this->size(),
            "closed" => $this->isClosed(),
            "inter_process" => $this->interProcess,
            "channel_name" => $this->channelName,
            "serializer" => $this->serializer,
            "shared_memory_available" => $this->isSharedMemoryAvailable(),
            "shared_memory_key" => $this->sharedMemoryKey,
            "channel_file" => $this->channelFile,
            "platform" => PHP_OS,
        ];
    }

    public function cleanup(): void
    {
        if ($this->interProcess) {
            if ($this->sharedMemory) {
                try {
                    @shm_detach($this->sharedMemory);
                } catch (\Error) {
                    // Already destroyed/detached — ignore
                }
                $this->sharedMemory = null;
            }

            if ($this->channelFile && file_exists($this->channelFile)) {
                @unlink($this->channelFile);
            }
            $this->channelFile = null;
        }
    }

    public function __destruct()
    {
        $this->cleanup();
    }
}
