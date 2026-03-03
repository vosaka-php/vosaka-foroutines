<?php

declare(strict_types=1);

namespace vosaka\foroutines\channel;

use Exception;
use Fiber;
use IteratorAggregate;

/**
 * Channel — a typed, bounded (or unbounded) communication pipe.
 *
 * Works in three modes:
 *
 *   1. **In-process** (default) — fibers in the same process exchange
 *      values via an in-memory buffer.  Created with `Channel::new()`.
 *
 *   2. **Inter-process / file transport** — multiple OS processes share
 *      a channel through a temp file + mutex.  Created with
 *      `Channel::newInterProcess()` or `Channels::createInterProcess()`.
 *
 *   3. **Inter-process / socket transport** — a background TCP broker
 *      manages an in-memory buffer; clients connect over loopback.
 *      Created with `Channel::create()` (preferred) or
 *      `Channel::newSocketInterProcess()`.
 *
 * ## Quick start
 *
 *     // Parent process — create a socket channel
 *     $ch = Channel::create(5);          // buffered, capacity 5
 *     $ch = Channel::create();           // unbounded
 *
 *     // Child process (pcntl_fork / Dispatchers::IO)
 *     $ch->connect();                    // reconnect — no args needed
 *     $ch->send("hello");
 *
 *     // The Channel is also serializable (works with SerializableClosure)
 */
final class Channel implements IteratorAggregate
{
    // ─── In-process state ────────────────────────────────────────────
    private array $buffer = [];
    private int $capacity;
    private array $sendQueue = [];
    private array $receiveQueue = [];
    private bool $closed = false;

    // ─── Inter-process metadata ──────────────────────────────────────
    private bool $interProcess;
    private ?string $channelName = null;
    private string $tempDir;
    private bool $isOwner = false;

    // ─── Serialization ───────────────────────────────────────────────
    private string $serializerName;
    private bool $preserveObjectTypes;

    // ─── Transport layer ─────────────────────────────────────────────
    /**
     * "file" | "socket" | null (in-process only)
     */
    private ?string $transport = null;

    /**
     * Socket client — set only when transport === "socket".
     */
    private ?ChannelSocketClient $socketClient = null;

    /**
     * File transport — set only when transport === "file".
     */
    private ?ChannelFileTransport $fileTransport = null;

    /**
     * Cached broker port so it survives fork / serialization.
     */
    private ?int $_socketPort = null;

    // ─── Constants ───────────────────────────────────────────────────

    const SERIALIZER_SERIALIZE = ChannelSerializer::SERIALIZER_SERIALIZE;
    const SERIALIZER_JSON = ChannelSerializer::SERIALIZER_JSON;
    const SERIALIZER_MSGPACK = ChannelSerializer::SERIALIZER_MSGPACK;
    const SERIALIZER_IGBINARY = ChannelSerializer::SERIALIZER_IGBINARY;

    const TRANSPORT_FILE = "file";
    const TRANSPORT_SOCKET = "socket";

    // ═════════════════════════════════════════════════════════════════
    //  Constructor
    // ═════════════════════════════════════════════════════════════════

    public function __construct(
        int $capacity = 0,
        bool $interProcess = false,
        ?string $channelName = null,
        string $serializer = self::SERIALIZER_SERIALIZE,
        ?string $tempDir = null,
        bool $preserveObjectTypes = true,
        bool $isOwner = false,
        ?string $transport = null,
    ) {
        if ($capacity < 0) {
            throw new Exception("Channel capacity cannot be negative");
        }

        $this->capacity = $capacity;
        $this->interProcess = $interProcess;
        $this->serializerName = $serializer;
        $this->preserveObjectTypes = $preserveObjectTypes;
        $this->tempDir = $tempDir ?: sys_get_temp_dir();
        $this->isOwner = $isOwner;
        $this->transport = $transport;

        if (
            $this->interProcess &&
            $this->transport !== self::TRANSPORT_SOCKET
        ) {
            $this->transport = self::TRANSPORT_FILE;
            $this->channelName = $channelName ?: "channel_" . uniqid();
            $this->initFileTransport();
        } elseif (
            $this->interProcess &&
            $this->transport === self::TRANSPORT_SOCKET
        ) {
            $this->channelName = $channelName ?: "channel_" . uniqid();
            // Socket transport is set up by factory methods that assign $socketClient.
        }
    }

    // ═════════════════════════════════════════════════════════════════
    //  Factory methods
    // ═════════════════════════════════════════════════════════════════

    /**
     * In-process channel (fibers only, no IPC).
     */
    public static function new(int $capacity = 0): Channel
    {
        return new self($capacity);
    }

    /**
     * Create a socket-based inter-process channel.
     *
     * This is the **primary, simplified** factory.  It spawns a
     * ChannelBroker background process and returns an owner Channel.
     *
     *     $ch = Channel::create(5);   // buffered, capacity 5
     *     $ch = Channel::create();    // unbounded (no limit)
     *
     * In a child process reconnect with:
     *
     *     $ch->connect();
     *
     * @param int   $capacity     Buffer size (0 = unbounded).
     * @param float $readTimeout  Read timeout in seconds.
     * @param float $idleTimeout  Broker idle timeout in seconds.
     */
    public static function create(
        int $capacity = 0,
        float $readTimeout = 30.0,
        float $idleTimeout = 300.0,
    ): Channel {
        $channelName = "channel_" . bin2hex(random_bytes(8)) . "_" . getmypid();

        return self::newSocketInterProcess(
            $channelName,
            $capacity,
            $readTimeout,
            $idleTimeout,
        );
    }

    /**
     * File-based inter-process channel (original transport).
     */
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
            true,
            self::TRANSPORT_FILE,
        );
    }

    /**
     * Socket-based inter-process channel (with explicit name).
     */
    public static function newSocketInterProcess(
        string $channelName,
        int $capacity = 0,
        float $readTimeout = 30.0,
        float $idleTimeout = 300.0,
    ): Channel {
        $client = ChannelSocketClient::createBroker(
            $channelName,
            $capacity,
            $readTimeout,
            $idleTimeout,
        );

        $channel = new self(
            $capacity,
            true,
            $channelName,
            self::SERIALIZER_SERIALIZE,
            null,
            true,
            true,
            self::TRANSPORT_SOCKET,
        );
        $channel->socketClient = $client;
        $channel->cacheSocketPort();

        return $channel;
    }

    // ─── Static connect helpers (file / socket) ──────────────────────

    /**
     * Connect to an existing file-based channel by name.
     */
    public static function connectByName(
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

        return new self(
            0,
            true,
            $channelName,
            $serializer,
            $tempDir,
            $preserveObjectTypes,
            false,
            self::TRANSPORT_FILE,
        );
    }

    /**
     * @deprecated Alias of connectByName().
     */
    public static function connectFile(
        string $channelName,
        string $serializer = self::SERIALIZER_SERIALIZE,
        ?string $tempDir = null,
        bool $preserveObjectTypes = true,
    ): Channel {
        return self::connectByName(
            $channelName,
            $serializer,
            $tempDir,
            $preserveObjectTypes,
        );
    }

    /**
     * Connect to a socket-based channel via port file discovery.
     *
     * @deprecated Use Channel::create() + $chan->connect() instead.
     */
    public static function connectSocket(
        string $channelName,
        float $readTimeout = 30.0,
        ?string $tempDir = null,
        float $connectTimeout = 10.0,
    ): Channel {
        $client = ChannelSocketClient::connect(
            $channelName,
            $readTimeout,
            $tempDir,
            $connectTimeout,
        );

        $channel = new self(
            0,
            true,
            $channelName,
            self::SERIALIZER_SERIALIZE,
            $tempDir,
            true,
            false,
            self::TRANSPORT_SOCKET,
        );
        $channel->socketClient = $client;
        $channel->cacheSocketPort();

        return $channel;
    }

    /**
     * Connect to a socket-based channel by port number.
     */
    public static function connectSocketByPort(
        string $channelName,
        int $port,
        float $readTimeout = 30.0,
    ): Channel {
        $client = ChannelSocketClient::connectByPort(
            $channelName,
            $port,
            $readTimeout,
        );

        $channel = new self(
            0,
            true,
            $channelName,
            self::SERIALIZER_SERIALIZE,
            null,
            true,
            false,
            self::TRANSPORT_SOCKET,
        );
        $channel->socketClient = $client;
        $channel->cacheSocketPort();

        return $channel;
    }

    // ═════════════════════════════════════════════════════════════════
    //  Instance connect — for child processes
    // ═════════════════════════════════════════════════════════════════

    /**
     * Reconnect this Channel instance in a child process.
     *
     * No arguments needed — the channel name, port and transport type
     * are already stored in the object (they survive pcntl_fork and
     * serialization).
     *
     *     // In Dispatchers::IO closure:
     *     $ch->connect();          // done — ready to send/receive
     *
     * @return $this
     */
    public function connect(): self
    {
        if ($this->transport === self::TRANSPORT_SOCKET) {
            // Disconnect stale connection (forked socket is invalid)
            if ($this->socketClient !== null) {
                try {
                    $this->socketClient->disconnect();
                } catch (\Throwable) {
                }
                $this->socketClient = null;
            }

            $port = $this->_socketPort;
            if ($port === null || $port <= 0) {
                throw new Exception(
                    "Channel '{$this->channelName}' has no stored port. " .
                        "Was this channel created with Channel::create()?",
                );
            }

            $this->socketClient = ChannelSocketClient::connectByPort(
                $this->channelName,
                $port,
            );
            $this->isOwner = false;

            return $this;
        }

        if ($this->transport === self::TRANSPORT_FILE) {
            $this->isOwner = false;
            if ($this->fileTransport !== null) {
                $this->fileTransport->setOwner(false);
                $this->fileTransport->loadChannelState();
            } else {
                $this->initFileTransport();
            }
            return $this;
        }

        throw new Exception(
            "connect() is only supported for inter-process channels.",
        );
    }

    // ═════════════════════════════════════════════════════════════════
    //  Public API — send / receive / trySend / tryReceive
    // ═════════════════════════════════════════════════════════════════

    public function send(mixed $value): void
    {
        if ($this->isSocketTransport()) {
            $this->socketClient->send($value);
            return;
        }
        if ($this->fileTransport !== null) {
            $this->fileTransport->send($value);
            return;
        }
        $this->sendInProcess($value);
    }

    public function receive(): mixed
    {
        if ($this->isSocketTransport()) {
            return $this->socketClient->receive();
        }
        if ($this->fileTransport !== null) {
            return $this->fileTransport->receive();
        }
        return $this->receiveInProcess();
    }

    public function trySend(mixed $value): bool
    {
        if ($this->isSocketTransport()) {
            return $this->socketClient->trySend($value);
        }
        if ($this->fileTransport !== null) {
            return $this->fileTransport->trySend($value);
        }
        return $this->trySendInProcess($value);
    }

    public function tryReceive(): mixed
    {
        if ($this->isSocketTransport()) {
            return $this->socketClient->tryReceive();
        }
        if ($this->fileTransport !== null) {
            return $this->fileTransport->tryReceive();
        }
        return $this->tryReceiveInProcess();
    }

    public function close(): void
    {
        if ($this->isSocketTransport()) {
            $this->socketClient->close();
            return;
        }
        if ($this->fileTransport !== null) {
            $this->fileTransport->close();
            return;
        }
        $this->closeInProcess();
    }

    // ═════════════════════════════════════════════════════════════════
    //  State queries
    // ═════════════════════════════════════════════════════════════════

    public function isClosed(): bool
    {
        if ($this->isSocketTransport()) {
            return $this->socketClient->isClosed();
        }
        if ($this->fileTransport !== null) {
            return $this->fileTransport->isClosed();
        }
        return $this->closed;
    }

    public function isEmpty(): bool
    {
        if ($this->isSocketTransport()) {
            return $this->socketClient->isEmpty();
        }
        if ($this->fileTransport !== null) {
            return $this->fileTransport->isEmpty();
        }
        return empty($this->buffer);
    }

    public function isFull(): bool
    {
        if ($this->isSocketTransport()) {
            return $this->socketClient->isFull();
        }
        if ($this->fileTransport !== null) {
            return $this->fileTransport->isFull();
        }
        return count($this->buffer) >= $this->capacity && $this->capacity > 0;
    }

    public function size(): int
    {
        if ($this->isSocketTransport()) {
            return $this->socketClient->size();
        }
        if ($this->fileTransport !== null) {
            return $this->fileTransport->size();
        }
        return count($this->buffer);
    }

    public function getInfo(): array
    {
        if ($this->isSocketTransport()) {
            return $this->socketClient->getInfo();
        }
        if ($this->fileTransport !== null) {
            return $this->fileTransport->getInfo();
        }
        return [
            "capacity" => $this->capacity,
            "size" => count($this->buffer),
            "closed" => $this->closed,
            "inter_process" => false,
            "channel_name" => $this->channelName,
            "transport" => "in-process",
            "platform" => PHP_OS,
        ];
    }

    // ═════════════════════════════════════════════════════════════════
    //  Accessors
    // ═════════════════════════════════════════════════════════════════

    public function getName(): ?string
    {
        return $this->channelName;
    }

    public function getSocketPort(): ?int
    {
        return $this->_socketPort ?? $this->socketClient?->getPort();
    }

    public function getTransport(): ?string
    {
        return $this->transport;
    }

    public function isOwner(): bool
    {
        return $this->isOwner;
    }

    public function isSocketTransport(): bool
    {
        return $this->transport === self::TRANSPORT_SOCKET &&
            $this->socketClient !== null;
    }

    public function getIterator(): ChannelIterator
    {
        return new ChannelIterator($this);
    }

    // ═════════════════════════════════════════════════════════════════
    //  Cleanup / destruct
    // ═════════════════════════════════════════════════════════════════

    public function cleanup(): void
    {
        if ($this->isSocketTransport() && $this->socketClient !== null) {
            if ($this->isOwner) {
                $this->socketClient->shutdown();
            } else {
                $this->socketClient->disconnect();
            }
            $this->socketClient = null;
            return;
        }

        if ($this->fileTransport !== null) {
            $this->fileTransport->cleanup();
            $this->fileTransport = null;
        }
    }

    public function __destruct()
    {
        $this->cleanup();
    }

    // ═════════════════════════════════════════════════════════════════
    //  Serialization — Channel survives serialize / unserialize
    //  (for SerializableClosure on Windows / proc_open path)
    // ═════════════════════════════════════════════════════════════════

    public function __serialize(): array
    {
        return [
            "channelName" => $this->channelName,
            "capacity" => $this->capacity,
            "interProcess" => $this->interProcess,
            "transport" => $this->transport,
            "socketPort" => $this->_socketPort,
            "serializer" => $this->serializerName,
            "preserveObjectTypes" => $this->preserveObjectTypes,
            "tempDir" => $this->tempDir,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->channelName = $data["channelName"] ?? null;
        $this->capacity = $data["capacity"] ?? 0;
        $this->interProcess = $data["interProcess"] ?? true;
        $this->transport = $data["transport"] ?? null;
        $this->_socketPort = $data["socketPort"] ?? null;
        $this->serializerName =
            $data["serializer"] ?? self::SERIALIZER_SERIALIZE;
        $this->preserveObjectTypes = $data["preserveObjectTypes"] ?? true;
        $this->tempDir = $data["tempDir"] ?? sys_get_temp_dir();
        $this->isOwner = false;
        $this->closed = false;
        $this->buffer = [];
        $this->sendQueue = [];
        $this->receiveQueue = [];
        $this->socketClient = null;
        $this->fileTransport = null;

        // Auto-reconnect
        try {
            $this->connect();
        } catch (\Throwable) {
            // Will be retried on first use or manual connect()
        }
    }

    // ═════════════════════════════════════════════════════════════════
    //  Serializer delegation (for callers that still need these)
    // ═════════════════════════════════════════════════════════════════

    public function canSerialize(mixed $data): bool
    {
        return $this->makeSerializer()->canSerialize($data);
    }

    public function getSerializationInfo(mixed $data): array
    {
        return $this->makeSerializer()->getSerializationInfo($data);
    }

    public function testData(mixed $data): array
    {
        return $this->makeSerializer()->testData($data);
    }

    public static function getRecommendedSerializer(mixed $data): string
    {
        return ChannelSerializer::getRecommendedSerializer($data);
    }

    // ═════════════════════════════════════════════════════════════════
    //  Private — in-process operations
    // ═════════════════════════════════════════════════════════════════

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

    private function trySendInProcess(mixed $value): bool
    {
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

    private function tryReceiveInProcess(): mixed
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

        return null;
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

    // ═════════════════════════════════════════════════════════════════
    //  Private — helpers
    // ═════════════════════════════════════════════════════════════════

    private function cacheSocketPort(): void
    {
        if ($this->socketClient !== null) {
            $this->_socketPort = $this->socketClient->getPort();
        }
    }

    private function initFileTransport(): void
    {
        $this->fileTransport = new ChannelFileTransport(
            $this->channelName,
            $this->capacity,
            $this->isOwner,
            $this->makeSerializer(),
            $this->tempDir,
        );
    }

    private function makeSerializer(): ChannelSerializer
    {
        return new ChannelSerializer(
            $this->serializerName,
            $this->preserveObjectTypes,
        );
    }
}
