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
     * "file" | "socket" | "socket_pool" | null (in-process only)
     */
    private ?string $transport = null;

    /**
     * Socket client — set only when transport === "socket" or "socket_pool".
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

    /**
     * Whether this channel uses pool mode (socket_pool transport).
     */
    private bool $poolMode = false;

    // ─── Static pool state ───────────────────────────────────────────

    /**
     * Shared pool-owner client. One process spawns a single pool process
     * that hosts many channels, instead of one process per channel.
     */
    private static ?ChannelSocketClient $poolOwnerClient = null;

    /**
     * The port of the running pool process (cached for child processes).
     */
    private static ?int $poolPort = null;

    /**
     * Whether pool mode is enabled globally.
     *
     * Defaults to true — Channel::create() automatically uses a shared
     * ChannelBrokerPool process instead of spawning one process per channel.
     * Call Channel::disablePool() to revert to per-channel broker behavior.
     */
    private static bool $poolEnabled = true;

    /**
     * Whether the pool shutdown function has been registered.
     */
    private static bool $poolShutdownRegistered = false;

    // ─── Constants ───────────────────────────────────────────────────

    const SERIALIZER_SERIALIZE = ChannelSerializer::SERIALIZER_SERIALIZE;
    const SERIALIZER_JSON = ChannelSerializer::SERIALIZER_JSON;
    const SERIALIZER_MSGPACK = ChannelSerializer::SERIALIZER_MSGPACK;
    const SERIALIZER_IGBINARY = ChannelSerializer::SERIALIZER_IGBINARY;

    const TRANSPORT_FILE = "file";
    const TRANSPORT_SOCKET = "socket";
    const TRANSPORT_SOCKET_POOL = "socket_pool";

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
            $this->transport !== self::TRANSPORT_SOCKET &&
            $this->transport !== self::TRANSPORT_SOCKET_POOL
        ) {
            $this->transport = self::TRANSPORT_FILE;
            $this->channelName = $channelName ?: "channel_" . uniqid();
            $this->initFileTransport();
        } elseif (
            $this->interProcess &&
            ($this->transport === self::TRANSPORT_SOCKET ||
                $this->transport === self::TRANSPORT_SOCKET_POOL)
        ) {
            $this->channelName = $channelName ?: "channel_" . uniqid();
            $this->poolMode = $this->transport === self::TRANSPORT_SOCKET_POOL;
            // Socket/pool transport is set up by factory methods that assign $socketClient.
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
     * Create an inter-process channel.
     *
     * This is the **primary, simplified** factory.  By default it uses
     * a shared ChannelBrokerPool process — all channels created via
     * this method share a single background process instead of spawning
     * one per channel.  The pool is lazily booted on the first call.
     *
     * If pool mode has been disabled via Channel::disablePool(), a
     * dedicated per-channel broker process is spawned instead.
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
     * @param float $idleTimeout  Broker idle timeout in seconds (used for pool auto-shutdown or per-channel broker).
     */
    public static function create(
        int $capacity = 0,
        float $readTimeout = 30.0,
        float $idleTimeout = 300.0,
    ): Channel {
        $channelName = "channel_" . bin2hex(random_bytes(8)) . "_" . getmypid();

        // Pool mode is the default — one shared process for all channels.
        // The pool is lazily booted on the first create() call.
        // Call Channel::disablePool() to revert to per-channel brokers.
        if (self::$poolEnabled) {
            return self::createPooled($channelName, $capacity, $readTimeout);
        }

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

    // ═════════════════════════════════════════════════════════════════
    //  Pool mode — one background process for many channels
    // ═════════════════════════════════════════════════════════════════

    /**
     * Enable pool mode globally (idempotent).
     *
     * Pool mode is **enabled by default** — calling this is only needed
     * after a previous disablePool() call to re-enable it.
     *
     * The pool process is lazily spawned on the first Channel::create()
     * or Channel::createPooled() call. No process is spawned by
     * enablePool() itself.
     *
     *     // Pool is already enabled by default, so this is optional:
     *     Channel::enablePool();
     *
     *     $ch1 = Channel::create(5);   // auto-boots pool, uses it
     *     $ch2 = Channel::create(10);  // same pool process
     *     $ch3 = Channel::create();    // same pool process
     *
     * @param float $readTimeout  Read timeout for pool operations (seconds).
     * @param float $idleTimeout  Pool idle timeout (seconds, 0 = no timeout).
     */
    public static function enablePool(
        float $readTimeout = 30.0,
        float $idleTimeout = 300.0,
    ): void {
        self::$poolEnabled = true;

        // If caller explicitly enables and a pool is not yet running,
        // eagerly boot it so the port is available immediately.
        if (self::$poolOwnerClient === null) {
            self::bootPool($readTimeout, $idleTimeout);
        }
    }

    /**
     * Disable pool mode globally.
     *
     * After calling this, new Channel::create() calls will spawn a
     * dedicated broker process per channel (the original behavior).
     * Already-created pool channels continue to work until the pool
     * is shut down via shutdownPool().
     */
    public static function disablePool(): void
    {
        self::$poolEnabled = false;
    }

    /**
     * Check if pool mode is enabled globally.
     */
    public static function isPoolEnabled(): bool
    {
        return self::$poolEnabled;
    }

    /**
     * Get the pool port (if a pool is running).
     */
    public static function getPoolPort(): ?int
    {
        return self::$poolPort;
    }

    /**
     * Shut down the pool process and clean up.
     *
     * All channels hosted in the pool will be closed. After this call
     * pool mode remains enabled but the pool process is stopped.
     * The next Channel::create() will lazily boot a fresh pool.
     *
     * To also disable pool mode, call disablePool() afterwards.
     */
    public static function shutdownPool(): void
    {
        if (self::$poolOwnerClient !== null) {
            try {
                self::$poolOwnerClient->shutdownPool();
            } catch (\Throwable) {
                // Best-effort cleanup
            }
            self::$poolOwnerClient = null;
        }

        self::$poolPort = null;
    }

    /**
     * Create a socket-pool-based inter-process channel.
     *
     * If the pool process is not yet running, it is lazily booted.
     *
     *     $ch = Channel::createPooled("my_channel", 5);
     *
     * @param string $channelName  Unique channel identifier.
     * @param int    $capacity     Buffer size (0 = unbounded).
     * @param float  $readTimeout  Read timeout for blocking operations.
     * @return Channel A channel backed by the shared pool process.
     */
    public static function createPooled(
        string $channelName = "",
        int $capacity = 0,
        float $readTimeout = 30.0,
    ): Channel {
        if ($channelName === "") {
            $channelName =
                "channel_" . bin2hex(random_bytes(8)) . "_" . getmypid();
        }

        // Boot the pool if needed
        if (self::$poolOwnerClient === null) {
            self::bootPool($readTimeout);
        }

        // Create the channel inside the pool
        $client = self::$poolOwnerClient->createChannelInPool(
            $channelName,
            $capacity,
        );

        $channel = new self(
            $capacity,
            true,
            $channelName,
            self::SERIALIZER_SERIALIZE,
            null,
            true,
            false, // not the pool owner — the static $poolOwnerClient owns it
            self::TRANSPORT_SOCKET_POOL,
        );
        $channel->socketClient = $client;
        $channel->poolMode = true;
        $channel->cacheSocketPort();

        return $channel;
    }

    /**
     * Boot the shared pool process.
     *
     * @param float $readTimeout Read timeout.
     * @param float $idleTimeout Idle timeout (default: 300s).
     */
    private static function bootPool(
        float $readTimeout = 30.0,
        float $idleTimeout = 300.0,
    ): void {
        self::$poolOwnerClient = ChannelSocketClient::createPool(
            $readTimeout,
            $idleTimeout,
        );
        self::$poolPort = self::$poolOwnerClient->getPort();

        // Register shutdown function once
        if (!self::$poolShutdownRegistered) {
            register_shutdown_function([self::class, "shutdownPool"]);
            self::$poolShutdownRegistered = true;
        }
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
     *
     * Automatically detects whether the port belongs to a
     * ChannelBrokerPool (pool mode) or a dedicated ChannelBroker.
     *
     * Detection logic (in order):
     *   1. If pool is enabled AND the given port matches the known
     *      pool port → pool-mode client (fast path, no probe).
     *   2. If pool is disabled → legacy single-broker client.
     *   3. If pool is enabled but the pool port is unknown (e.g.
     *      inside a worker process where static state was reset),
     *      perform a protocol probe: connect, send PING, and check
     *      the response. ChannelBrokerPool responds with POOL_PONG;
     *      a single ChannelBroker responds with ERROR:Unknown command.
     */
    public static function connectSocketByPort(
        string $channelName,
        int $port,
        float $readTimeout = 30.0,
    ): Channel {
        // Fast path: we know the pool port — exact match.
        if (
            self::$poolEnabled &&
            self::$poolPort !== null &&
            self::$poolPort === $port
        ) {
            return self::connectSocketByPortAsPool(
                $channelName,
                $port,
                $readTimeout,
            );
        }

        // If pool is disabled, always use legacy single-broker client.
        if (!self::$poolEnabled) {
            return self::connectSocketByPortAsLegacy(
                $channelName,
                $port,
                $readTimeout,
            );
        }

        // Pool is enabled but we don't know the pool port (common in
        // worker / child processes where static state is not inherited).
        // Use a protocol probe to detect the server type.
        if (self::probeIsPool($port)) {
            // Cache the discovered pool port for subsequent calls
            // within this process.
            self::$poolPort = $port;
            return self::connectSocketByPortAsPool(
                $channelName,
                $port,
                $readTimeout,
            );
        }

        return self::connectSocketByPortAsLegacy(
            $channelName,
            $port,
            $readTimeout,
        );
    }

    /**
     * Connect to a pool-based channel by port (internal helper).
     */
    private static function connectSocketByPortAsPool(
        string $channelName,
        int $port,
        float $readTimeout,
    ): Channel {
        $client = ChannelSocketClient::connectToPool(
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
            self::TRANSPORT_SOCKET_POOL,
        );
        $channel->socketClient = $client;
        $channel->poolMode = true;
        $channel->cacheSocketPort();

        return $channel;
    }

    /**
     * Connect to a single-broker channel by port (internal helper).
     */
    private static function connectSocketByPortAsLegacy(
        string $channelName,
        int $port,
        float $readTimeout,
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

    /**
     * Probe a TCP port to detect whether it belongs to a ChannelBrokerPool.
     *
     * Sends a PING command on a short-lived connection. A pool responds
     * with POOL_PONG; a single ChannelBroker responds with
     * ERROR:Unknown command (or similar).
     *
     * @param int $port TCP port to probe.
     * @return bool True if the server is a ChannelBrokerPool.
     */
    private static function probeIsPool(int $port): bool
    {
        $conn = @stream_socket_client(
            "tcp://127.0.0.1:{$port}",
            $errno,
            $errstr,
            2.0,
            STREAM_CLIENT_CONNECT,
        );

        if ($conn === false) {
            return false;
        }

        // Send PING and read response
        @fwrite($conn, "PING\n");
        @fflush($conn);

        // Wait up to 2 seconds for response
        $read = [$conn];
        $write = null;
        $except = null;
        $ready = @stream_select($read, $write, $except, 2, 0);

        $isPool = false;
        if ($ready > 0) {
            $response = @fgets($conn, 1024);
            if ($response !== false) {
                $isPool = trim($response) === "POOL_PONG";
            }
        }

        @fclose($conn);
        return $isPool;
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
        if (
            $this->transport === self::TRANSPORT_SOCKET ||
            $this->transport === self::TRANSPORT_SOCKET_POOL
        ) {
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

            if (
                $this->poolMode ||
                $this->transport === self::TRANSPORT_SOCKET_POOL
            ) {
                $this->socketClient = ChannelSocketClient::connectToPool(
                    $this->channelName,
                    $port,
                );
            } else {
                $this->socketClient = ChannelSocketClient::connectByPort(
                    $this->channelName,
                    $port,
                );
            }
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
        return ($this->transport === self::TRANSPORT_SOCKET ||
            $this->transport === self::TRANSPORT_SOCKET_POOL) &&
            $this->socketClient !== null;
    }

    /**
     * Check if this channel uses pool mode.
     */
    public function isPoolMode(): bool
    {
        return $this->poolMode;
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
            if ($this->poolMode) {
                // In pool mode, individual channels don't own the pool process.
                // Just disconnect. The pool process is managed by the static
                // $poolOwnerClient and shut down via Channel::shutdownPool().
                $this->socketClient->disconnect();
            } elseif ($this->isOwner) {
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
            "poolMode" => $this->poolMode,
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
        $this->poolMode = $data["poolMode"] ?? false;
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
            // receiveQueue stores bare Fiber objects — no wrapper array needed
            $fiber = array_shift($this->receiveQueue);
            $fiber->resume($value);
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

        // Indexed array [fiber, value] instead of associative
        // ["fiber" => ..., "value" => ...] — avoids hash table overhead
        $this->sendQueue[] = [$currentFiber, $value];

        Fiber::suspend();
    }

    private function receiveInProcess(): mixed
    {
        if (!empty($this->buffer)) {
            $value = array_shift($this->buffer);

            if (!empty($this->sendQueue)) {
                // Indexed access: [0] = fiber, [1] = value
                $sender = array_shift($this->sendQueue);
                $this->buffer[] = $sender[1];
                $sender[0]->resume();
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

        // Store bare Fiber — no wrapper array needed for receiveQueue
        $this->receiveQueue[] = $currentFiber;

        return Fiber::suspend();
    }

    private function trySendInProcess(mixed $value): bool
    {
        if ($this->closed) {
            return false;
        }

        if (!empty($this->receiveQueue)) {
            // receiveQueue stores bare Fiber objects
            $fiber = array_shift($this->receiveQueue);
            $fiber->resume($value);
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
                // Indexed access: [0] = fiber, [1] = value
                $sender = array_shift($this->sendQueue);
                $this->buffer[] = $sender[1];
                $sender[0]->resume();
            }

            return $value;
        }

        return null;
    }

    private function closeInProcess(): void
    {
        $this->closed = true;

        // receiveQueue stores bare Fiber objects
        foreach ($this->receiveQueue as $fiber) {
            $fiber->throw(new Exception("Channel is closed"));
        }

        // sendQueue stores indexed arrays: [0] = fiber, [1] = value
        foreach ($this->sendQueue as $sender) {
            $sender[0]->throw(new Exception("Channel is closed"));
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
