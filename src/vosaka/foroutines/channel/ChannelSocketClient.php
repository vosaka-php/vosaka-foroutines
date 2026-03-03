<?php

declare(strict_types=1);

namespace vosaka\foroutines\channel;

use Exception;

/**
 * ChannelSocketClient — TCP client for connecting to a ChannelBroker.
 *
 * This class provides the client-side API for socket-based inter-process
 * channel communication. It connects to a ChannelBroker over TCP loopback
 * and translates high-level operations (send, receive, close, etc.) into
 * the broker's line-based protocol.
 *
 * Architecture:
 *   Each ChannelSocketClient instance maintains a single persistent TCP
 *   connection to the broker. All operations are synchronous from the
 *   caller's perspective — the client sends a command and waits for the
 *   broker's response. However, unlike the file-based approach:
 *
 *     - No file I/O overhead (no file_get_contents / file_put_contents)
 *     - No full-buffer serialization on every operation
 *     - No spin-wait polling — the broker pushes responses immediately
 *     - Blocking receive is truly event-driven (broker queues the request
 *       and responds when data arrives, no usleep loop)
 *
 * Protocol (line-based, each message terminated by \n):
 *
 *   Client → Broker:
 *     SEND:<base64(serialize(value))>\n       — blocking send
 *     RECV\n                                   — blocking receive
 *     TRY_SEND:<base64(serialize(value))>\n   — non-blocking send
 *     TRY_RECV\n                               — non-blocking receive
 *     CLOSE\n                                  — close the channel
 *     IS_CLOSED\n                              — query closed state
 *     IS_EMPTY\n                               — query empty state
 *     IS_FULL\n                                — query full state
 *     SIZE\n                                   — query buffer size
 *     INFO\n                                   — query channel info
 *     SHUTDOWN\n                               — shutdown the broker
 *
 *   Broker → Client:
 *     OK\n                                     — send succeeded
 *     VALUE:<base64(serialize(value))>\n       — received value
 *     FULL\n                                   — buffer full (try_send)
 *     EMPTY\n                                  — buffer empty (try_receive)
 *     CLOSED\n                                 — channel is closed
 *     CLOSED_EMPTY\n                           — channel is closed and empty
 *     TRUE\n / FALSE\n                         — boolean query response
 *     SIZE:<n>\n                               — size response
 *     INFO:<base64(serialize(info))>\n         — info response
 *     ERROR:<message>\n                        — error response
 *
 * Lifecycle:
 *   1. The owner process spawns a ChannelBroker background process via
 *      ChannelSocketClient::createBroker().
 *   2. The broker writes its port to a well-known port file and sends
 *      "READY:<port>\n" to STDOUT.
 *   3. The owner (or any other process) creates a ChannelSocketClient
 *      via connect() or connectByPort(), which opens a TCP connection
 *      to the broker.
 *   4. All channel operations (send/receive/close/query) go through the
 *      TCP connection.
 *   5. When the channel is no longer needed, the owner calls shutdown()
 *      to stop the broker and clean up.
 *
 * Thread-safety / Process-safety:
 *   Each process should have its own ChannelSocketClient instance. The
 *   broker handles concurrency internally via stream_select() multiplexing.
 *
 * @internal This class is not part of the public API. Use Channel or Channels
 *           factory methods to create socket-based channels.
 */
final class ChannelSocketClient
{
    /** @var resource|null TCP connection to the broker */
    private $conn = null;

    /** @var string Read buffer for accumulating partial responses */
    private string $readBuffer = "";

    /** @var string Channel name for identification */
    private string $channelName;

    /** @var int Broker TCP port */
    private int $port;

    /** @var bool Whether this client instance is the owner (spawned the broker) */
    private bool $isOwner;

    /** @var resource|null The broker background process handle (owner only) */
    private $brokerProcess = null;

    /** @var array|null Broker process pipes [stdin, stdout, stderr] (owner only) */
    private ?array $brokerPipes = null;

    /**
     * @var string|null Path to the port file (legacy, for connectByName discovery).
     * @deprecated Port file is no longer written by the broker. Port is
     *             communicated via STDOUT and stored in Channel::$_socketPort.
     */
    private ?string $portFile = null;

    /** @var float Read timeout in seconds for blocking operations */
    private float $readTimeout;

    /** @var bool Whether this client is connected to the broker */
    private bool $connected = false;

    /**
     * Whether this client is operating in pool mode.
     * In pool mode, all channel commands are prefixed with CH:<channelName>:
     * and sent to a shared ChannelBrokerPool process instead of a dedicated
     * per-channel broker.
     */
    private bool $poolMode = false;

    /**
     * @param string $channelName Channel identifier.
     * @param int    $port        Broker TCP port.
     * @param bool   $isOwner     Whether this instance owns the broker process.
     * @param float  $readTimeout Read timeout for blocking operations (seconds).
     * @param bool   $poolMode    Whether this client talks to a ChannelBrokerPool.
     */
    public function __construct(
        string $channelName,
        int $port,
        bool $isOwner = false,
        float $readTimeout = 30.0,
        bool $poolMode = false,
    ) {
        $this->channelName = $channelName;
        $this->port = $port;
        $this->isOwner = $isOwner;
        $this->readTimeout = $readTimeout;
        $this->poolMode = $poolMode;
    }

    // ─── Factory Methods ─────────────────────────────────────────────

    /**
     * Create a new socket-based channel by spawning a broker background
     * process and connecting to it.
     *
     * This is the primary factory method for creating socket-based channels.
     * It spawns the broker, waits for it to be ready, then returns a connected
     * client that is the "owner" of the broker.
     *
     * @param string $channelName  Unique channel identifier.
     * @param int    $capacity     Maximum buffer size (0 = unbounded).
     * @param float  $readTimeout  Read timeout for blocking operations (seconds).
     * @param float  $idleTimeout  Broker idle timeout (seconds, 0 = no timeout).
     * @param float  $startTimeout Maximum time to wait for broker startup (seconds).
     * @return self A connected client that owns the broker.
     * @throws Exception If the broker cannot be started.
     */
    public static function createBroker(
        string $channelName,
        int $capacity = 0,
        float $readTimeout = 30.0,
        float $idleTimeout = 300.0,
        float $startTimeout = 10.0,
    ): self {
        $brokerScript =
            __DIR__ . DIRECTORY_SEPARATOR . "channel_broker_process.php";

        if (!is_file($brokerScript)) {
            throw new Exception(
                "ChannelSocketClient: channel_broker_process.php not found at {$brokerScript}",
            );
        }

        $command = [
            PHP_BINARY,
            $brokerScript,
            $channelName,
            (string) $capacity,
            (string) $idleTimeout,
        ];

        $descriptors = [
            0 => ["pipe", "r"], // STDIN — kept open as heartbeat
            1 => ["pipe", "w"], // STDOUT — for READY signal
            2 => ["pipe", "w"], // STDERR — debugging
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new Exception(
                "ChannelSocketClient: Failed to spawn broker process for channel '{$channelName}'",
            );
        }

        // Set stdout to non-blocking for timeout-based reading
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        // Wait for the READY:<port> line from the broker
        $port = self::waitForBrokerReady($pipes[1], $startTimeout);

        if ($port === null) {
            // Broker failed to start — collect stderr for diagnostics
            $stderr = @stream_get_contents($pipes[2]);
            @fclose($pipes[0]);
            @fclose($pipes[1]);
            @fclose($pipes[2]);
            proc_terminate($process, 9);
            @proc_close($process);

            $errMsg =
                $stderr !== false && $stderr !== ""
                    ? " Stderr: " . trim($stderr)
                    : "";
            throw new Exception(
                "ChannelSocketClient: Broker for channel '{$channelName}' failed to start within {$startTimeout}s.{$errMsg}",
            );
        }

        // Create the client instance
        $client = new self($channelName, $port, true, $readTimeout);
        $client->brokerProcess = $process;
        $client->brokerPipes = $pipes;

        // Connect to the broker
        $client->connectToBroker();

        return $client;
    }

    // ─── Pool Mode Factory Methods ───────────────────────────────────

    /**
     * Spawn a new ChannelBrokerPool background process.
     *
     * Unlike createBroker() which spawns one process per channel, this
     * spawns a single pool process that can host many channels. After
     * spawning, use createChannelInPool() to register channels.
     *
     * @param float $readTimeout  Read timeout for blocking operations (seconds).
     * @param float $idleTimeout  Pool idle timeout (seconds, 0 = no timeout).
     * @param float $startTimeout Maximum time to wait for pool startup (seconds).
     * @return self A connected client that owns the pool process.
     * @throws Exception If the pool cannot be started.
     */
    public static function createPool(
        float $readTimeout = 30.0,
        float $idleTimeout = 300.0,
        float $startTimeout = 10.0,
    ): self {
        $poolScript =
            __DIR__ . DIRECTORY_SEPARATOR . "channel_broker_pool_process.php";

        if (!is_file($poolScript)) {
            throw new Exception(
                "ChannelSocketClient: channel_broker_pool_process.php not found at {$poolScript}",
            );
        }

        $command = [PHP_BINARY, $poolScript, (string) $idleTimeout];

        $descriptors = [
            0 => ["pipe", "r"], // STDIN — kept open as heartbeat
            1 => ["pipe", "w"], // STDOUT — for READY signal
            2 => ["pipe", "w"], // STDERR — debugging
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new Exception(
                "ChannelSocketClient: Failed to spawn broker pool process",
            );
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $port = self::waitForBrokerReady($pipes[1], $startTimeout);

        if ($port === null) {
            $stderr = @stream_get_contents($pipes[2]);
            @fclose($pipes[0]);
            @fclose($pipes[1]);
            @fclose($pipes[2]);
            proc_terminate($process, 9);
            @proc_close($process);

            $errMsg =
                $stderr !== false && $stderr !== ""
                    ? " Stderr: " . trim($stderr)
                    : "";
            throw new Exception(
                "ChannelSocketClient: Broker pool failed to start within {$startTimeout}s.{$errMsg}",
            );
        }

        // Use a placeholder channel name for the pool-owner client.
        // The real channel names are set per-operation via pool protocol.
        $client = new self("__pool_owner__", $port, true, $readTimeout, true);
        $client->brokerProcess = $process;
        $client->brokerPipes = $pipes;

        $client->connectToBroker();

        return $client;
    }

    /**
     * Create a new channel inside an already-running pool and return
     * a ChannelSocketClient bound to that channel.
     *
     * This method is called on a pool-owner (or any pool-connected) client.
     * It sends the CREATE_CHANNEL command, then returns a NEW client
     * instance that is scoped to the created channel.
     *
     * @param string $channelName Unique channel identifier.
     * @param int    $capacity    Maximum buffer size (0 = unbounded).
     * @return self A connected client scoped to the new channel (not the pool owner).
     * @throws Exception If creation fails.
     */
    public function createChannelInPool(
        string $channelName,
        int $capacity = 0,
    ): self {
        $this->ensureConnected();

        $this->sendLine("CREATE_CHANNEL:{$channelName}:{$capacity}");
        $response = $this->readLine($this->readTimeout);

        if ($response !== null && str_starts_with($response, "CREATED:")) {
            $port = (int) substr($response, 8);

            // Create a new client instance scoped to this channel
            $client = new self(
                $channelName,
                $port,
                false,
                $this->readTimeout,
                true,
            );
            $client->connectToBroker();

            return $client;
        }

        if ($response !== null && str_starts_with($response, "ERROR:")) {
            throw new Exception(
                "ChannelSocketClient::createChannelInPool() failed: " .
                    substr($response, 6),
            );
        }

        throw new Exception(
            "ChannelSocketClient::createChannelInPool() unexpected response: " .
                ($response ?? "null/timeout"),
        );
    }

    /**
     * Connect to an existing channel in a pool by port number.
     *
     * @param string $channelName Channel name within the pool.
     * @param int    $port        Pool TCP port.
     * @param float  $readTimeout Read timeout for blocking operations.
     * @return self A connected client in pool mode (not the owner).
     * @throws Exception If the connection fails.
     */
    public static function connectToPool(
        string $channelName,
        int $port,
        float $readTimeout = 30.0,
    ): self {
        $client = new self($channelName, $port, false, $readTimeout, true);
        $client->connectToBroker();
        return $client;
    }

    /**
     * Connect to an existing broker by channel name via port file discovery.
     *
     * @deprecated The broker no longer writes a port file. Use
     *             connectByPort() instead, or capture the Channel object
     *             and call $chan->connect() in the child process.
     *
     * @param string      $channelName Unique channel identifier.
     * @param float       $readTimeout Read timeout for blocking operations.
     * @param string|null $tempDir     Temp directory (default: sys_get_temp_dir()).
     * @param float       $connectTimeout Max seconds to wait for port file.
     * @return self A connected client (not the owner).
     * @throws Exception If the port file is not found or connection fails.
     */
    public static function connect(
        string $channelName,
        float $readTimeout = 30.0,
        ?string $tempDir = null,
        float $connectTimeout = 10.0,
    ): self {
        $tempDir = $tempDir ?: sys_get_temp_dir();
        $portFile =
            $tempDir .
            DIRECTORY_SEPARATOR .
            "channel_broker_" .
            md5($channelName) .
            ".port";

        // Wait for the port file to appear (the broker may still be starting)
        $deadline = microtime(true) + $connectTimeout;
        $port = null;

        while (microtime(true) < $deadline) {
            if (file_exists($portFile)) {
                $content = @file_get_contents($portFile);
                if (
                    $content !== false &&
                    $content !== "" &&
                    is_numeric(trim($content))
                ) {
                    $port = (int) trim($content);
                    break;
                }
            }
            usleep(10_000); // 10ms
        }

        if ($port === null) {
            throw new Exception(
                "ChannelSocketClient: Port file for channel '{$channelName}' not found at {$portFile} within {$connectTimeout}s",
            );
        }

        $client = new self($channelName, $port, false, $readTimeout);
        $client->portFile = $portFile;
        $client->connectToBroker();

        return $client;
    }

    /**
     * Connect to an existing broker by port number directly.
     *
     * @param string $channelName Unique channel identifier.
     * @param int    $port        Broker TCP port.
     * @param float  $readTimeout Read timeout for blocking operations.
     * @return self A connected client (not the owner).
     * @throws Exception If the connection fails.
     */
    public static function connectByPort(
        string $channelName,
        int $port,
        float $readTimeout = 30.0,
    ): self {
        $client = new self($channelName, $port, false, $readTimeout);
        $client->connectToBroker();
        return $client;
    }

    // ─── Connection Management ───────────────────────────────────────

    /**
     * Establish a TCP connection to the broker.
     *
     * @throws Exception If the connection cannot be established.
     */
    private function connectToBroker(): void
    {
        $maxRetries = 10; // 10 × 20ms = 200ms max (broker is already READY)
        $retryDelay = 20_000; // 20ms

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            $conn = @stream_socket_client(
                "tcp://127.0.0.1:{$this->port}",
                $errno,
                $errstr,
                5.0,
                STREAM_CLIENT_CONNECT,
            );

            if ($conn !== false) {
                $this->conn = $conn;
                $this->connected = true;
                $this->readBuffer = "";

                // Set TCP_NODELAY for lower latency
                if (function_exists("socket_import_stream")) {
                    $socket = @socket_import_stream($conn);
                    if ($socket !== false && $socket !== null) {
                        @socket_set_option($socket, SOL_TCP, TCP_NODELAY, 1);
                    }
                }

                return;
            }

            usleep($retryDelay);
        }

        throw new Exception(
            "ChannelSocketClient: Failed to connect to broker at 127.0.0.1:{$this->port}: [{$errno}] {$errstr}",
        );
    }

    /**
     * Ensure the connection is alive, reconnecting if necessary.
     *
     * @throws Exception If reconnection fails.
     */
    private function ensureConnected(): void
    {
        if (
            $this->conn !== null &&
            is_resource($this->conn) &&
            !feof($this->conn)
        ) {
            return;
        }

        $this->connected = false;
        $this->conn = null;
        $this->readBuffer = "";
        $this->connectToBroker();
    }

    /**
     * Check if the client is connected.
     */
    public function isConnected(): bool
    {
        return $this->connected &&
            $this->conn !== null &&
            is_resource($this->conn) &&
            !feof($this->conn);
    }

    // ─── Channel Operations ──────────────────────────────────────────

    /**
     * Send a value through the channel (blocking).
     *
     * Serializes the value, sends it to the broker, and waits for
     * confirmation. If the buffer is full, the broker queues this
     * request and responds when space is available (backpressure).
     *
     * @param mixed $value The value to send.
     * @throws Exception If the channel is closed or communication fails.
     */
    public function send(mixed $value): void
    {
        $this->ensureConnected();

        $payload = base64_encode(serialize($value));
        $this->sendLine($this->prefixCommand("SEND:" . $payload));

        $response = $this->readLine($this->readTimeout);

        if ($response === "OK") {
            return;
        }

        if ($response === "CLOSED") {
            throw new Exception("Channel is closed");
        }

        if (
            $response !== null &&
            str_starts_with($response, "NO_SUCH_CHANNEL:")
        ) {
            throw new Exception(
                "Channel '{$this->channelName}' does not exist in the pool",
            );
        }

        throw new Exception(
            "ChannelSocketClient::send() unexpected response: " .
                ($response ?? "null/timeout"),
        );
    }

    /**
     * Receive a value from the channel (blocking).
     *
     * Sends a RECV command to the broker and waits for a value.
     * If the buffer is empty, the broker queues this request and
     * responds when data arrives (event-driven, no polling).
     *
     * @return mixed The received value.
     * @throws Exception If the channel is closed and empty or communication fails.
     */
    public function receive(): mixed
    {
        $this->ensureConnected();

        $this->sendLine($this->prefixCommand("RECV"));

        $response = $this->readLine($this->readTimeout);

        if ($response !== null && str_starts_with($response, "VALUE:")) {
            $payload = substr($response, 6);
            $decoded = base64_decode($payload, true);
            if ($decoded === false) {
                throw new Exception(
                    "ChannelSocketClient::receive() failed to base64_decode response",
                );
            }
            return unserialize($decoded);
        }

        if ($response === "CLOSED_EMPTY") {
            throw new Exception("Channel is closed and empty");
        }

        if ($response === "CLOSED") {
            throw new Exception("Channel is closed");
        }

        if (
            $response !== null &&
            str_starts_with($response, "NO_SUCH_CHANNEL:")
        ) {
            throw new Exception(
                "Channel '{$this->channelName}' does not exist in the pool",
            );
        }

        throw new Exception(
            "ChannelSocketClient::receive() unexpected response: " .
                ($response ?? "null/timeout"),
        );
    }

    /**
     * Try to send a value (non-blocking).
     *
     * @param mixed $value The value to send.
     * @return bool True if the value was sent, false if buffer is full.
     */
    public function trySend(mixed $value): bool
    {
        $this->ensureConnected();

        $payload = base64_encode(serialize($value));
        $this->sendLine($this->prefixCommand("TRY_SEND:" . $payload));

        $response = $this->readLine(5.0);

        if ($response === "OK") {
            return true;
        }

        if ($response === "FULL" || $response === "CLOSED") {
            return false;
        }

        return false;
    }

    /**
     * Try to receive a value (non-blocking).
     *
     * @return mixed The received value, or null if the buffer is empty.
     */
    public function tryReceive(): mixed
    {
        $this->ensureConnected();

        $this->sendLine($this->prefixCommand("TRY_RECV"));

        $response = $this->readLine(5.0);

        if ($response !== null && str_starts_with($response, "VALUE:")) {
            $payload = substr($response, 6);
            $decoded = base64_decode($payload, true);
            if ($decoded === false) {
                return null;
            }
            return unserialize($decoded);
        }

        // EMPTY, CLOSED_EMPTY, CLOSED, NO_SUCH_CHANNEL, or timeout — all return null
        return null;
    }

    /**
     * Close the channel.
     *
     * After closing, no more values can be sent. Buffered values can
     * still be received until the buffer is drained.
     */
    public function close(): void
    {
        if (!$this->isConnected()) {
            return;
        }

        $this->sendLine($this->prefixCommand("CLOSE"));

        // Read the response (OK)
        $this->readLine(5.0);
    }

    /**
     * Check if the channel is closed.
     */
    public function isClosed(): bool
    {
        if (!$this->isConnected()) {
            return true;
        }

        $this->sendLine($this->prefixCommand("IS_CLOSED"));
        $response = $this->readLine(5.0);

        return $response === "TRUE";
    }

    /**
     * Check if the channel buffer is empty.
     */
    public function isEmpty(): bool
    {
        if (!$this->isConnected()) {
            return true;
        }

        $this->sendLine($this->prefixCommand("IS_EMPTY"));
        $response = $this->readLine(5.0);

        return $response === "TRUE";
    }

    /**
     * Check if the channel buffer is full.
     */
    public function isFull(): bool
    {
        if (!$this->isConnected()) {
            return false;
        }

        $this->sendLine($this->prefixCommand("IS_FULL"));
        $response = $this->readLine(5.0);

        return $response === "TRUE";
    }

    /**
     * Get the current buffer size.
     */
    public function size(): int
    {
        if (!$this->isConnected()) {
            return 0;
        }

        $this->sendLine($this->prefixCommand("SIZE"));
        $response = $this->readLine(5.0);

        if ($response !== null && str_starts_with($response, "SIZE:")) {
            return (int) substr($response, 5);
        }

        return 0;
    }

    /**
     * Get channel info from the broker.
     *
     * @return array Channel information.
     */
    public function getInfo(): array
    {
        if (!$this->isConnected()) {
            return [
                "capacity" => 0,
                "size" => 0,
                "closed" => true,
                "inter_process" => true,
                "channel_name" => $this->channelName,
                "transport" => $this->poolMode ? "socket_pool" : "socket",
                "connected" => false,
                "pool_mode" => $this->poolMode,
            ];
        }

        $this->sendLine($this->prefixCommand("INFO"));
        $response = $this->readLine(5.0);

        if ($response !== null && str_starts_with($response, "INFO:")) {
            $payload = substr($response, 5);
            $decoded = base64_decode($payload, true);
            if ($decoded !== false) {
                $info = unserialize($decoded);
                if (is_array($info)) {
                    return $info;
                }
            }
        }

        return [
            "capacity" => 0,
            "size" => 0,
            "closed" => false,
            "inter_process" => true,
            "channel_name" => $this->channelName,
            "transport" => "socket",
            "connected" => $this->isConnected(),
            "port" => $this->port,
        ];
    }

    /**
     * Shutdown the broker (owner only).
     *
     * Sends the SHUTDOWN command to the broker and waits for the
     * background process to exit. Also cleans up the port file.
     */
    public function shutdown(): void
    {
        // Send SHUTDOWN command to broker (or POOL_SHUTDOWN for pool mode owner)
        if ($this->isConnected()) {
            try {
                if ($this->poolMode && $this->isOwner) {
                    // Pool owner: shut down the entire pool process
                    $this->sendLine("POOL_SHUTDOWN");
                } elseif ($this->poolMode) {
                    // Pool non-owner: just close the channel, don't kill pool
                    $this->sendLine($this->prefixCommand("SHUTDOWN"));
                } else {
                    $this->sendLine("SHUTDOWN");
                }
                // Read the OK response (best-effort)
                $this->readLine(2.0);
            } catch (\Throwable) {
                // Ignore errors during shutdown
            }
        }

        // Close our connection
        $this->disconnect();

        // Wait for broker/pool process to exit (owner only)
        if (
            $this->brokerProcess !== null &&
            is_resource($this->brokerProcess)
        ) {
            // Close stdin to signal parent death
            if ($this->brokerPipes !== null) {
                if (is_resource($this->brokerPipes[0] ?? null)) {
                    @fclose($this->brokerPipes[0]);
                }
                if (is_resource($this->brokerPipes[1] ?? null)) {
                    @fclose($this->brokerPipes[1]);
                }
                if (is_resource($this->brokerPipes[2] ?? null)) {
                    @fclose($this->brokerPipes[2]);
                }
                $this->brokerPipes = null;
            }

            // Wait up to 3 seconds for graceful exit
            $deadline = microtime(true) + 3.0;
            while (microtime(true) < $deadline) {
                $status = proc_get_status($this->brokerProcess);
                if (!$status["running"]) {
                    break;
                }
                usleep(10_000);
            }

            // Force kill if still running
            $status = proc_get_status($this->brokerProcess);
            if ($status["running"]) {
                proc_terminate($this->brokerProcess, 9);
            }

            @proc_close($this->brokerProcess);
            $this->brokerProcess = null;
        }
    }

    /**
     * Shutdown the pool process (pool-owner only).
     *
     * Unlike shutdown() which handles per-channel cleanup, this method
     * explicitly sends POOL_SHUTDOWN to terminate the entire pool process
     * and all channels it manages.
     *
     * @throws Exception If not connected.
     */
    public function shutdownPool(): void
    {
        if (!$this->poolMode) {
            throw new Exception(
                "ChannelSocketClient::shutdownPool() can only be called in pool mode",
            );
        }

        $this->shutdown();
    }

    /**
     * Disconnect from the broker (close the TCP connection).
     */
    public function disconnect(): void
    {
        if ($this->conn !== null && is_resource($this->conn)) {
            @fclose($this->conn);
        }
        $this->conn = null;
        $this->connected = false;
        $this->readBuffer = "";
    }

    // ─── Accessors ───────────────────────────────────────────────────

    /**
     * Get the channel name.
     */
    public function getChannelName(): string
    {
        return $this->channelName;
    }

    /**
     * Get the broker's TCP port.
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * Check if this client is the owner of the broker.
     */
    public function isOwner(): bool
    {
        return $this->isOwner;
    }

    /**
     * Check if this client is operating in pool mode.
     */
    public function isPoolMode(): bool
    {
        return $this->poolMode;
    }

    // ─── Low-level Communication ─────────────────────────────────────

    /**
     * Prefix a command with CH:<channelName>: when in pool mode.
     * In non-pool mode, the command is returned as-is.
     *
     * @param string $command The raw command (e.g. "SEND:payload", "RECV").
     * @return string The possibly-prefixed command.
     */
    private function prefixCommand(string $command): string
    {
        if ($this->poolMode) {
            return "CH:" . $this->channelName . ":" . $command;
        }
        return $command;
    }

    /**
     * Send a line to the broker.
     *
     * @param string $line The line to send (without trailing newline).
     * @throws Exception If the send fails.
     */
    private function sendLine(string $line): void
    {
        if ($this->conn === null || !is_resource($this->conn)) {
            throw new Exception("ChannelSocketClient: Not connected to broker");
        }

        $data = $line . "\n";
        $len = strlen($data);
        $written = 0;

        // Use blocking mode for reliable write
        @stream_set_blocking($this->conn, true);

        while ($written < $len) {
            $bytes = @fwrite($this->conn, substr($data, $written));
            if ($bytes === false || $bytes === 0) {
                @stream_set_blocking($this->conn, false);
                $this->connected = false;
                throw new Exception(
                    "ChannelSocketClient: Failed to write to broker (connection broken)",
                );
            }
            $written += $bytes;
        }

        @fflush($this->conn);
        @stream_set_blocking($this->conn, false);
    }

    /**
     * Read a complete line from the broker.
     *
     * Uses stream_select() for efficient blocking with timeout. No busy-wait.
     *
     * @param float $timeout Maximum seconds to wait for a complete line.
     * @return string|null The line (without newline), or null on timeout.
     * @throws Exception If the connection is broken.
     */
    private function readLine(float $timeout): ?string
    {
        if ($this->conn === null || !is_resource($this->conn)) {
            throw new Exception("ChannelSocketClient: Not connected to broker");
        }

        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            // Check if we already have a complete line in the buffer
            $nlPos = strpos($this->readBuffer, "\n");
            if ($nlPos !== false) {
                $line = substr($this->readBuffer, 0, $nlPos);
                $this->readBuffer = substr($this->readBuffer, $nlPos + 1);
                return rtrim($line, "\r");
            }

            // Wait for data using stream_select
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                break;
            }

            $read = [$this->conn];
            $write = null;
            $except = null;

            $tvSec = (int) floor($remaining);
            $tvUsec = (int) (($remaining - $tvSec) * 1_000_000);
            // Cap at a reasonable poll interval
            if ($tvSec > 0 || $tvUsec > 100_000) {
                $tvSec = 0;
                $tvUsec = 100_000; // 100ms max per select
            }

            $ready = @stream_select($read, $write, $except, $tvSec, $tvUsec);

            if ($ready === false) {
                // stream_select error — connection likely broken
                $this->connected = false;
                throw new Exception(
                    "ChannelSocketClient: stream_select failed",
                );
            }

            if ($ready > 0) {
                $chunk = @fread($this->conn, 1048576); // 1 MB

                if ($chunk === false || $chunk === "") {
                    // Connection closed by broker
                    $this->connected = false;
                    throw new Exception(
                        "ChannelSocketClient: Connection to broker lost",
                    );
                }

                $this->readBuffer .= $chunk;
            }
        }

        // Check one more time after the loop
        $nlPos = strpos($this->readBuffer, "\n");
        if ($nlPos !== false) {
            $line = substr($this->readBuffer, 0, $nlPos);
            $this->readBuffer = substr($this->readBuffer, $nlPos + 1);
            return rtrim($line, "\r");
        }

        return null; // timeout
    }

    /**
     * Wait for the READY:<port> line from the broker's stdout.
     *
     * @param resource $stdout The broker's stdout pipe.
     * @param float    $timeout Maximum seconds to wait.
     * @return int|null The broker's TCP port, or null on timeout/failure.
     */
    private static function waitForBrokerReady($stdout, float $timeout): ?int
    {
        $deadline = microtime(true) + $timeout;
        $buffer = "";

        while (microtime(true) < $deadline) {
            $read = [$stdout];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, 0, 50_000); // 50ms

            if ($ready === false) {
                return null;
            }

            if ($ready > 0) {
                $chunk = @fread($stdout, 65536);
                if ($chunk === false || $chunk === "") {
                    // Pipe closed — broker exited prematurely
                    return null;
                }
                $buffer .= $chunk;

                // Look for READY:<port>\n
                if (($nlPos = strpos($buffer, "\n")) !== false) {
                    $line = rtrim(substr($buffer, 0, $nlPos), "\r");
                    if (str_starts_with($line, "READY:")) {
                        $port = (int) substr($line, 6);
                        if ($port > 0 && $port <= 65535) {
                            return $port;
                        }
                    }
                }
            }

            usleep(5_000);
        }

        return null; // timeout
    }

    // ─── Lifecycle ───────────────────────────────────────────────────

    /**
     * Destructor — disconnect from broker and shut it down if owner.
     */
    public function __destruct()
    {
        if ($this->isOwner) {
            // Pool owners only shut down the pool if they own the process.
            // Non-owner pool clients just disconnect.
            $this->shutdown();
        } else {
            $this->disconnect();
        }
    }
}
