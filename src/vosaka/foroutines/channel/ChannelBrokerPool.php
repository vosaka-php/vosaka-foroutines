<?php

declare(strict_types=1);

namespace vosaka\foroutines\channel;

use Exception;

/**
 * ChannelBrokerPool — A single TCP server process that manages multiple
 * channels simultaneously, eliminating the need for one process per channel.
 *
 * Architecture:
 *   Instead of spawning a separate broker process for each channel
 *   (N channels = N processes), the ChannelBrokerPool runs a single
 *   background process that hosts multiple virtual channels. Each channel
 *   has its own in-memory buffer, pending senders/receivers queues, and
 *   capacity — but they all share the same TCP server and event loop.
 *
 *   ┌─────────────────────────────────────────────────┐
 *   │              ChannelBrokerPool Process           │
 *   │                                                  │
 *   │  ┌──────────┐  ┌──────────┐  ┌──────────┐      │
 *   │  │ Channel A│  │ Channel B│  │ Channel C│ ...   │
 *   │  │ buf/cap  │  │ buf/cap  │  │ buf/cap  │      │
 *   │  └──────────┘  └──────────┘  └──────────┘      │
 *   │                                                  │
 *   │        Single TCP Server (stream_select)         │
 *   └───────────────────┬─────────────────────────────┘
 *                       │ TCP 127.0.0.1:<port>
 *         ┌─────────────┼─────────────┐
 *         │             │             │
 *    ┌────┴────┐  ┌────┴────┐  ┌────┴────┐
 *    │Client 1 │  │Client 2 │  │Client 3 │
 *    │(Chan A) │  │(Chan B) │  │(Chan A) │
 *    └─────────┘  └─────────┘  └─────────┘
 *
 * Protocol (line-based, each message terminated by \n):
 *
 *   All channel-scoped commands are prefixed with CH:<channel_name>:
 *
 *   Client → Pool:
 *     CREATE_CHANNEL:<channel_name>:<capacity>\n   — create a new channel in the pool
 *     REMOVE_CHANNEL:<channel_name>\n              — remove a channel from the pool
 *     LIST_CHANNELS\n                               — list all channel names
 *     POOL_INFO\n                                   — get pool-level info
 *     POOL_SHUTDOWN\n                               — shutdown the entire pool
 *
 *     CH:<channel_name>:SEND:<payload>\n           — send value to a channel
 *     CH:<channel_name>:RECV\n                     — blocking receive from a channel
 *     CH:<channel_name>:TRY_SEND:<payload>\n       — non-blocking send
 *     CH:<channel_name>:TRY_RECV\n                 — non-blocking receive
 *     CH:<channel_name>:CLOSE\n                    — close a channel
 *     CH:<channel_name>:IS_CLOSED\n                — query closed state
 *     CH:<channel_name>:IS_EMPTY\n                 — query empty state
 *     CH:<channel_name>:IS_FULL\n                  — query full state
 *     CH:<channel_name>:SIZE\n                     — query buffer size
 *     CH:<channel_name>:INFO\n                     — query channel info
 *
 *   Pool → Client:
 *     CREATED:<port>\n                              — channel created (port = pool port)
 *     REMOVED\n                                     — channel removed
 *     CHANNELS:<base64(serialize(list))>\n          — channel list
 *     POOL_INFO:<base64(serialize(info))>\n         — pool info
 *     OK\n                                          — operation succeeded
 *     VALUE:<payload>\n                             — received value
 *     FULL\n                                        — buffer full (try_send)
 *     EMPTY\n                                       — buffer empty (try_receive)
 *     CLOSED\n                                      — channel is closed
 *     CLOSED_EMPTY\n                                — channel closed and empty
 *     TRUE\n / FALSE\n                              — boolean query
 *     SIZE:<n>\n                                    — size response
 *     INFO:<base64(serialize(info))>\n              — channel info
 *     ERROR:<message>\n                             — error
 *     NO_SUCH_CHANNEL:<name>\n                      — channel not found
 *     CHANNEL_EXISTS:<name>\n                       — channel already exists
 *
 * @internal This class is not part of the public API.
 */
final class ChannelBrokerPool
{
    /** @var resource|null TCP server socket */
    private $serverSocket = null;

    /** @var int TCP port the pool is listening on */
    private int $port = 0;

    /** @var bool Whether the pool should stop its event loop */
    private bool $shouldStop = false;

    // ─── Per-channel state ───────────────────────────────────────────

    /**
     * Channel buffers.
     * @var array<string, array> channelName => [value, value, ...]
     */
    private array $buffers = [];

    /**
     * Channel capacities.
     * @var array<string, int> channelName => capacity (0 = unbounded)
     */
    private array $capacities = [];

    /**
     * Channel closed flags.
     * @var array<string, bool> channelName => closed
     */
    private array $closedFlags = [];

    /**
     * Pending receivers per channel.
     * @var array<string, array<int, array{client_id: int, stream: resource}>>
     */
    private array $pendingReceivers = [];

    /**
     * Pending senders per channel.
     * @var array<string, array<int, array{client_id: int, stream: resource, value: string}>>
     */
    private array $pendingSenders = [];

    // ─── Client management ───────────────────────────────────────────

    /**
     * Connected client streams.
     * @var array<int, resource>
     */
    private array $clients = [];

    /**
     * Per-client read buffer for accumulating partial lines.
     * @var array<int, string>
     */
    private array $readBuffers = [];

    /** @var int Auto-incrementing client ID */
    private int $nextClientId = 1;

    /** @var array<int, int> Map stream resource ID → client ID */
    private array $streamToClientId = [];

    /**
     * Per-client channel binding. When a client sends a CH:<name>:...
     * command, the pool remembers which channel that client belongs to.
     * Subsequent legacy (non-prefixed) commands are auto-routed to the
     * bound channel — this provides backward compatibility with code
     * that uses connectSocketByPort() and sends raw SEND/RECV/etc.
     *
     * @var array<int, string> clientId → channelName
     */
    private array $clientChannelMap = [];

    // ─── Timing ──────────────────────────────────────────────────────

    /** @var float Timestamp of last activity */
    private float $lastActivity;

    /** @var float Idle timeout in seconds (0 = no timeout) */
    private float $idleTimeout;

    /**
     * @param float $idleTimeout Seconds of inactivity before auto-shutdown (0 = never).
     */
    public function __construct(float $idleTimeout = 0.0)
    {
        $this->idleTimeout = $idleTimeout;
        $this->lastActivity = microtime(true);
    }

    // ═════════════════════════════════════════════════════════════════
    //  Server lifecycle
    // ═════════════════════════════════════════════════════════════════

    /**
     * Create the TCP server and start listening.
     *
     * @param int $port Port to bind (0 = let OS pick ephemeral port).
     * @throws Exception If the server cannot be created.
     */
    public function listen(int $port = 0): void
    {
        $server = @stream_socket_server(
            "tcp://127.0.0.1:{$port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );

        if ($server === false) {
            throw new Exception(
                "ChannelBrokerPool: Failed to create TCP server: [{$errno}] {$errstr}",
            );
        }

        $name = stream_socket_get_name($server, false);
        if ($name === false) {
            @fclose($server);
            throw new Exception(
                "ChannelBrokerPool: Failed to get TCP server address",
            );
        }

        $parts = explode(":", $name);
        $this->port = (int) end($parts);
        $this->serverSocket = $server;

        stream_set_blocking($server, false);
    }

    /**
     * Get the port the pool is listening on.
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * Run the event loop. This blocks until shutdown.
     */
    public function run(): void
    {
        if ($this->serverSocket === null) {
            throw new Exception(
                "ChannelBrokerPool: Server not started. Call listen() first.",
            );
        }

        $this->shouldStop = false;
        $this->lastActivity = microtime(true);

        while (!$this->shouldStop) {
            $this->tick();
        }

        $this->cleanup();
    }

    /**
     * Run a single iteration of the event loop.
     *
     * @param float $selectTimeout Timeout for stream_select in seconds.
     * @return bool False if the pool should stop.
     */
    public function tick(float $selectTimeout = 0.05): bool
    {
        if ($this->serverSocket === null || !is_resource($this->serverSocket)) {
            return false;
        }

        $readStreams = [$this->serverSocket];
        foreach ($this->clients as $clientId => $stream) {
            if (is_resource($stream)) {
                $readStreams[] = $stream;
            } else {
                $this->removeClient($clientId);
            }
        }

        $write = null;
        $except = null;

        $tvSec = (int) floor($selectTimeout);
        $tvUsec = (int) (($selectTimeout - $tvSec) * 1_000_000);

        $ready = @stream_select($readStreams, $write, $except, $tvSec, $tvUsec);

        if ($ready === false) {
            return !$this->shouldStop;
        }

        if ($ready > 0) {
            $this->lastActivity = microtime(true);

            foreach ($readStreams as $stream) {
                if ($stream === $this->serverSocket) {
                    $this->acceptClient();
                } else {
                    $clientId = $this->streamToClientId[(int) $stream] ?? null;
                    if ($clientId !== null) {
                        $this->readFromClient($clientId);
                    }
                }
            }
        }

        // Check idle timeout — only when no channels exist and no clients
        if (
            $this->idleTimeout > 0 &&
            empty($this->clients) &&
            empty($this->buffers) &&
            microtime(true) - $this->lastActivity > $this->idleTimeout
        ) {
            $this->shouldStop = true;
        }

        return !$this->shouldStop;
    }

    /**
     * Signal the pool to stop after the current tick.
     */
    public function stop(): void
    {
        $this->shouldStop = true;
    }

    /**
     * Check if the pool is still running.
     */
    public function isRunning(): bool
    {
        return !$this->shouldStop;
    }

    // ═════════════════════════════════════════════════════════════════
    //  Channel management
    // ═════════════════════════════════════════════════════════════════

    /**
     * Create a new channel within the pool.
     *
     * @param string $channelName Unique channel identifier.
     * @param int    $capacity    Maximum buffer size (0 = unbounded).
     * @return bool True if created, false if already exists.
     */
    public function createChannel(string $channelName, int $capacity = 0): bool
    {
        if (isset($this->buffers[$channelName])) {
            return false;
        }

        $this->buffers[$channelName] = [];
        $this->capacities[$channelName] = $capacity;
        $this->closedFlags[$channelName] = false;
        $this->pendingReceivers[$channelName] = [];
        $this->pendingSenders[$channelName] = [];

        return true;
    }

    /**
     * Remove a channel from the pool and notify all pending clients.
     *
     * @param string $channelName The channel to remove.
     * @return bool True if removed, false if not found.
     */
    public function removeChannel(string $channelName): bool
    {
        if (!isset($this->buffers[$channelName])) {
            return false;
        }

        // Notify pending receivers
        foreach ($this->pendingReceivers[$channelName] as $receiver) {
            if (is_resource($receiver["stream"])) {
                $this->sendLine($receiver["stream"], "CLOSED_EMPTY");
            }
        }

        // Notify pending senders
        foreach ($this->pendingSenders[$channelName] as $sender) {
            if (is_resource($sender["stream"])) {
                $this->sendLine($sender["stream"], "CLOSED");
            }
        }

        unset($this->buffers[$channelName]);
        unset($this->capacities[$channelName]);
        unset($this->closedFlags[$channelName]);
        unset($this->pendingReceivers[$channelName]);
        unset($this->pendingSenders[$channelName]);

        return true;
    }

    /**
     * Check if a channel exists in the pool.
     */
    public function hasChannel(string $channelName): bool
    {
        return isset($this->buffers[$channelName]);
    }

    /**
     * Get list of all channel names in the pool.
     *
     * @return string[]
     */
    public function getChannelNames(): array
    {
        return array_keys($this->buffers);
    }

    /**
     * Get the number of channels in the pool.
     */
    public function getChannelCount(): int
    {
        return count($this->buffers);
    }

    /**
     * Get the number of connected clients.
     */
    public function getClientCount(): int
    {
        return count($this->clients);
    }

    // ═════════════════════════════════════════════════════════════════
    //  Client I/O
    // ═════════════════════════════════════════════════════════════════

    /**
     * Accept a new client connection.
     */
    private function acceptClient(): void
    {
        $client = @stream_socket_accept($this->serverSocket, 0);
        if ($client === false) {
            return;
        }

        stream_set_blocking($client, false);

        if (function_exists("socket_import_stream")) {
            $socket = @socket_import_stream($client);
            if ($socket !== false) {
                @socket_set_option($socket, SOL_TCP, TCP_NODELAY, 1);
            }
        }

        $clientId = $this->nextClientId++;
        $this->clients[$clientId] = $client;
        $this->readBuffers[$clientId] = "";
        $this->streamToClientId[(int) $client] = $clientId;
    }

    /**
     * Read data from a client and process complete lines.
     */
    private function readFromClient(int $clientId): void
    {
        $stream = $this->clients[$clientId] ?? null;
        if ($stream === null || !is_resource($stream)) {
            $this->removeClient($clientId);
            return;
        }

        $chunk = @fread($stream, 1048576); // 1 MB

        if ($chunk === false || $chunk === "") {
            $this->removeClient($clientId);
            return;
        }

        $this->readBuffers[$clientId] .= $chunk;

        while (
            ($nlPos = strpos($this->readBuffers[$clientId], "\n")) !== false
        ) {
            $line = substr($this->readBuffers[$clientId], 0, $nlPos);
            $this->readBuffers[$clientId] = substr(
                $this->readBuffers[$clientId],
                $nlPos + 1,
            );
            $line = rtrim($line, "\r");

            if ($line !== "") {
                $this->handleCommand($clientId, $line);
            }
        }
    }

    /**
     * Remove a client and clean up all its pending requests across all channels.
     */
    private function removeClient(int $clientId): void
    {
        $stream = $this->clients[$clientId] ?? null;

        // Clean up pending requests in ALL channels for this client
        foreach ($this->pendingReceivers as $channelName => &$receivers) {
            $receivers = array_values(
                array_filter(
                    $receivers,
                    fn($r) => $r["client_id"] !== $clientId,
                ),
            );
        }
        unset($receivers);

        foreach ($this->pendingSenders as $channelName => &$senders) {
            $senders = array_values(
                array_filter($senders, fn($s) => $s["client_id"] !== $clientId),
            );
        }
        unset($senders);

        if ($stream !== null && is_resource($stream)) {
            unset($this->streamToClientId[(int) $stream]);
            @fclose($stream);
        }

        unset($this->clients[$clientId]);
        unset($this->readBuffers[$clientId]);
        unset($this->clientChannelMap[$clientId]);
    }

    // ═════════════════════════════════════════════════════════════════
    //  Command dispatch
    // ═════════════════════════════════════════════════════════════════

    /**
     * Handle a single command line from a client.
     */
    private function handleCommand(int $clientId, string $line): void
    {
        $stream = $this->clients[$clientId] ?? null;
        if ($stream === null || !is_resource($stream)) {
            return;
        }

        // ── Pool-level commands ──────────────────────────────────────

        // CREATE_CHANNEL:<channel_name>:<capacity>
        if (str_starts_with($line, "CREATE_CHANNEL:")) {
            $rest = substr($line, 15);
            $colonPos = strrpos($rest, ":");
            if ($colonPos === false) {
                $this->sendLine(
                    $stream,
                    "ERROR:Invalid CREATE_CHANNEL format. Expected CREATE_CHANNEL:<name>:<capacity>",
                );
                return;
            }
            $channelName = substr($rest, 0, $colonPos);
            $capacity = (int) substr($rest, $colonPos + 1);

            if ($channelName === "") {
                $this->sendLine($stream, "ERROR:Channel name cannot be empty");
                return;
            }

            if ($this->hasChannel($channelName)) {
                // Channel already exists — not an error, just report the port
                $this->sendLine($stream, "CREATED:" . $this->port);
                return;
            }

            $this->createChannel($channelName, $capacity);
            $this->sendLine($stream, "CREATED:" . $this->port);
            return;
        }

        // REMOVE_CHANNEL:<channel_name>
        if (str_starts_with($line, "REMOVE_CHANNEL:")) {
            $channelName = substr($line, 15);
            if ($this->removeChannel($channelName)) {
                $this->sendLine($stream, "REMOVED");
            } else {
                $this->sendLine($stream, "NO_SUCH_CHANNEL:" . $channelName);
            }
            return;
        }

        // LIST_CHANNELS
        if ($line === "LIST_CHANNELS") {
            $names = $this->getChannelNames();
            $encoded = base64_encode(serialize($names));
            $this->sendLine($stream, "CHANNELS:" . $encoded);
            return;
        }

        // POOL_INFO
        if ($line === "POOL_INFO") {
            $info = $this->getPoolInfo();
            $encoded = base64_encode(serialize($info));
            $this->sendLine($stream, "POOL_INFO:" . $encoded);
            return;
        }

        // POOL_SHUTDOWN
        if ($line === "POOL_SHUTDOWN") {
            $this->sendLine($stream, "OK");
            $this->shouldStop = true;
            return;
        }

        // ── PING — protocol auto-detection ───────────────────────────
        // Clients send PING to determine whether they are talking to a
        // ChannelBrokerPool (responds POOL_PONG) or a legacy single-
        // channel ChannelBroker (responds ERROR:Unknown command).
        if ($line === "PING") {
            $this->sendLine($stream, "POOL_PONG");
            return;
        }

        // ── Channel-scoped commands: CH:<channelName>:<command> ──────

        if (str_starts_with($line, "CH:")) {
            $rest = substr($line, 3);
            // Find the channel name — it's everything up to the next ":"
            $colonPos = strpos($rest, ":");
            if ($colonPos === false) {
                $this->sendLine($stream, "ERROR:Invalid CH: command format");
                return;
            }

            $channelName = substr($rest, 0, $colonPos);
            $command = substr($rest, $colonPos + 1);

            if (!$this->hasChannel($channelName)) {
                $this->sendLine($stream, "NO_SUCH_CHANNEL:" . $channelName);
                return;
            }

            // Bind this client to the channel so future legacy commands
            // (without CH: prefix) are auto-routed here.
            $this->clientChannelMap[$clientId] = $channelName;

            $this->handleChannelCommand(
                $clientId,
                $stream,
                $channelName,
                $command,
            );
            return;
        }

        // ── Legacy single-channel commands (backward compatibility) ──
        // If the client was previously bound to a channel via a CH: command,
        // or if there is exactly one channel in the pool, route the raw
        // command there automatically. This lets code that uses
        // connectSocketByPort() / the old ChannelBroker protocol work
        // transparently against a ChannelBrokerPool.

        $boundChannel = $this->clientChannelMap[$clientId] ?? null;

        if ($boundChannel === null && count($this->buffers) === 1) {
            // Only one channel exists — auto-bind to it.
            $boundChannel = array_key_first($this->buffers);
            $this->clientChannelMap[$clientId] = $boundChannel;
        }

        if ($boundChannel !== null && $this->hasChannel($boundChannel)) {
            $this->handleChannelCommand(
                $clientId,
                $stream,
                $boundChannel,
                $line,
            );
            return;
        }

        // ── SHUTDOWN (pool-level, legacy) ────────────────────────────
        if ($line === "SHUTDOWN") {
            $this->sendLine($stream, "OK");
            $this->shouldStop = true;
            return;
        }

        $this->sendLine(
            $stream,
            "ERROR:Unknown command. Use CH:<channel_name>:<command> or pool-level commands.",
        );
    }

    /**
     * Handle a command scoped to a specific channel.
     *
     * @param int      $clientId    Client identifier.
     * @param resource $stream      Client stream.
     * @param string   $channelName Target channel.
     * @param string   $command     The command (after CH:<name>: prefix).
     */
    private function handleChannelCommand(
        int $clientId,
        $stream,
        string $channelName,
        string $command,
    ): void {
        // ── SEND:<payload> ───────────────────────────────────────────
        if (str_starts_with($command, "SEND:")) {
            $payload = substr($command, 5);
            $this->handleSend($clientId, $stream, $channelName, $payload);
            return;
        }

        // ── TRY_SEND:<payload> ───────────────────────────────────────
        if (str_starts_with($command, "TRY_SEND:")) {
            $payload = substr($command, 9);
            $this->handleTrySend($clientId, $stream, $channelName, $payload);
            return;
        }

        // ── RECV ─────────────────────────────────────────────────────
        if ($command === "RECV") {
            $this->handleRecv($clientId, $stream, $channelName);
            return;
        }

        // ── TRY_RECV ─────────────────────────────────────────────────
        if ($command === "TRY_RECV") {
            $this->handleTryRecv($clientId, $stream, $channelName);
            return;
        }

        // ── CLOSE ────────────────────────────────────────────────────
        if ($command === "CLOSE") {
            $this->handleClose($clientId, $stream, $channelName);
            return;
        }

        // ── IS_CLOSED ────────────────────────────────────────────────
        if ($command === "IS_CLOSED") {
            $closed = $this->closedFlags[$channelName] ?? false;
            $this->sendLine($stream, $closed ? "TRUE" : "FALSE");
            return;
        }

        // ── IS_EMPTY ─────────────────────────────────────────────────
        if ($command === "IS_EMPTY") {
            $empty = empty($this->buffers[$channelName]);
            $this->sendLine($stream, $empty ? "TRUE" : "FALSE");
            return;
        }

        // ── IS_FULL ──────────────────────────────────────────────────
        if ($command === "IS_FULL") {
            $capacity = $this->capacities[$channelName] ?? 0;
            $isFull =
                $capacity > 0 &&
                count($this->buffers[$channelName] ?? []) >= $capacity;
            $this->sendLine($stream, $isFull ? "TRUE" : "FALSE");
            return;
        }

        // ── SIZE ─────────────────────────────────────────────────────
        if ($command === "SIZE") {
            $size = count($this->buffers[$channelName] ?? []);
            $this->sendLine($stream, "SIZE:" . $size);
            return;
        }

        // ── INFO ─────────────────────────────────────────────────────
        if ($command === "INFO") {
            $info = [
                "capacity" => $this->capacities[$channelName] ?? 0,
                "size" => count($this->buffers[$channelName] ?? []),
                "closed" => $this->closedFlags[$channelName] ?? false,
                "inter_process" => true,
                "channel_name" => $channelName,
                "transport" => "socket_pool",
                "clients" => count($this->clients),
                "pending_receivers" => count(
                    $this->pendingReceivers[$channelName] ?? [],
                ),
                "pending_senders" => count(
                    $this->pendingSenders[$channelName] ?? [],
                ),
                "platform" => PHP_OS,
                "port" => $this->port,
                "pool_channels" => count($this->buffers),
            ];
            $encoded = base64_encode(serialize($info));
            $this->sendLine($stream, "INFO:" . $encoded);
            return;
        }

        // ── SHUTDOWN (per-channel alias: close + remove) ─────────────
        if ($command === "SHUTDOWN") {
            $this->handleClose($clientId, $stream, $channelName);
            $this->removeChannel($channelName);
            return;
        }

        $this->sendLine($stream, "ERROR:Unknown channel command: " . $command);
    }

    // ═════════════════════════════════════════════════════════════════
    //  Channel operation handlers
    // ═════════════════════════════════════════════════════════════════

    /**
     * Handle SEND — blocking send to a channel.
     */
    private function handleSend(
        int $clientId,
        $stream,
        string $channelName,
        string $payload,
    ): void {
        if ($this->closedFlags[$channelName] ?? false) {
            $this->sendLine($stream, "CLOSED");
            return;
        }

        // Fast path: deliver directly to a pending receiver
        $receivers = &$this->pendingReceivers[$channelName];
        while (!empty($receivers)) {
            $receiver = array_shift($receivers);
            $rStream = $receiver["stream"];

            if (!is_resource($rStream)) {
                continue;
            }

            $this->sendLine($rStream, "VALUE:" . $payload);
            $this->sendLine($stream, "OK");
            return;
        }

        // Buffer has space (or unbounded)
        $capacity = $this->capacities[$channelName] ?? 0;
        if (
            $capacity === 0 ||
            count($this->buffers[$channelName]) < $capacity
        ) {
            $this->buffers[$channelName][] = $payload;
            $this->sendLine($stream, "OK");
            return;
        }

        // Buffer full — queue this sender (backpressure)
        $this->pendingSenders[$channelName][] = [
            "client_id" => $clientId,
            "stream" => $stream,
            "value" => $payload,
        ];
    }

    /**
     * Handle TRY_SEND — non-blocking send to a channel.
     */
    private function handleTrySend(
        int $clientId,
        $stream,
        string $channelName,
        string $payload,
    ): void {
        if ($this->closedFlags[$channelName] ?? false) {
            $this->sendLine($stream, "CLOSED");
            return;
        }

        // Fast path: deliver directly to a pending receiver
        $receivers = &$this->pendingReceivers[$channelName];
        while (!empty($receivers)) {
            $receiver = array_shift($receivers);
            $rStream = $receiver["stream"];

            if (!is_resource($rStream)) {
                continue;
            }

            $this->sendLine($rStream, "VALUE:" . $payload);
            $this->sendLine($stream, "OK");
            return;
        }

        // Buffer has space (or unbounded)
        $capacity = $this->capacities[$channelName] ?? 0;
        if (
            $capacity === 0 ||
            count($this->buffers[$channelName]) < $capacity
        ) {
            $this->buffers[$channelName][] = $payload;
            $this->sendLine($stream, "OK");
            return;
        }

        // Buffer full — non-blocking, just report FULL
        $this->sendLine($stream, "FULL");
    }

    /**
     * Handle RECV — blocking receive from a channel.
     */
    private function handleRecv(
        int $clientId,
        $stream,
        string $channelName,
    ): void {
        // If buffer has data, deliver immediately
        if (!empty($this->buffers[$channelName])) {
            $payload = array_shift($this->buffers[$channelName]);
            $this->sendLine($stream, "VALUE:" . $payload);

            $this->drainOnePendingSender($channelName);
            return;
        }

        // Buffer empty — try to get from a pending sender directly
        $senders = &$this->pendingSenders[$channelName];
        while (!empty($senders)) {
            $sender = array_shift($senders);
            $sStream = $sender["stream"];

            if (!is_resource($sStream)) {
                continue;
            }

            $this->sendLine($stream, "VALUE:" . $sender["value"]);
            $this->sendLine($sStream, "OK");
            return;
        }

        // Buffer empty, no pending senders
        if ($this->closedFlags[$channelName] ?? false) {
            $this->sendLine($stream, "CLOSED_EMPTY");
            return;
        }

        // Queue this receiver
        $this->pendingReceivers[$channelName][] = [
            "client_id" => $clientId,
            "stream" => $stream,
        ];
    }

    /**
     * Handle TRY_RECV — non-blocking receive from a channel.
     */
    private function handleTryRecv(
        int $clientId,
        $stream,
        string $channelName,
    ): void {
        if (!empty($this->buffers[$channelName])) {
            $payload = array_shift($this->buffers[$channelName]);
            $this->sendLine($stream, "VALUE:" . $payload);

            $this->drainOnePendingSender($channelName);
            return;
        }

        // Buffer empty — try direct from pending sender
        $senders = &$this->pendingSenders[$channelName];
        while (!empty($senders)) {
            $sender = array_shift($senders);
            $sStream = $sender["stream"];

            if (!is_resource($sStream)) {
                continue;
            }

            $this->sendLine($stream, "VALUE:" . $sender["value"]);
            $this->sendLine($sStream, "OK");
            return;
        }

        if ($this->closedFlags[$channelName] ?? false) {
            $this->sendLine($stream, "CLOSED_EMPTY");
            return;
        }

        $this->sendLine($stream, "EMPTY");
    }

    /**
     * Handle CLOSE — close a channel.
     */
    private function handleClose(
        int $clientId,
        $stream,
        string $channelName,
    ): void {
        if ($this->closedFlags[$channelName] ?? false) {
            $this->sendLine($stream, "OK");
            return;
        }

        $this->closedFlags[$channelName] = true;
        $this->sendLine($stream, "OK");

        // Notify all pending senders
        foreach ($this->pendingSenders[$channelName] as $sender) {
            if (is_resource($sender["stream"])) {
                $this->sendLine($sender["stream"], "CLOSED");
            }
        }
        $this->pendingSenders[$channelName] = [];

        // If buffer is empty, notify pending receivers
        if (empty($this->buffers[$channelName])) {
            foreach ($this->pendingReceivers[$channelName] as $receiver) {
                if (is_resource($receiver["stream"])) {
                    $this->sendLine($receiver["stream"], "CLOSED_EMPTY");
                }
            }
            $this->pendingReceivers[$channelName] = [];
        } else {
            // Drain pending receivers with available buffer data
            $this->drainPendingReceiversFromBuffer($channelName);
        }
    }

    // ═════════════════════════════════════════════════════════════════
    //  Drain helpers
    // ═════════════════════════════════════════════════════════════════

    /**
     * Accept one pending sender's value into a channel's buffer.
     */
    private function drainOnePendingSender(string $channelName): void
    {
        $senders = &$this->pendingSenders[$channelName];

        while (!empty($senders)) {
            $sender = array_shift($senders);
            $sStream = $sender["stream"];

            if (!is_resource($sStream)) {
                continue;
            }

            if ($this->closedFlags[$channelName] ?? false) {
                $this->sendLine($sStream, "CLOSED");
                continue;
            }

            $this->buffers[$channelName][] = $sender["value"];
            $this->sendLine($sStream, "OK");
            return;
        }
    }

    /**
     * Drain pending receivers from a channel's buffer.
     */
    private function drainPendingReceiversFromBuffer(string $channelName): void
    {
        $receivers = &$this->pendingReceivers[$channelName];
        $buffer = &$this->buffers[$channelName];

        while (!empty($receivers) && !empty($buffer)) {
            $receiver = array_shift($receivers);
            $rStream = $receiver["stream"];

            if (!is_resource($rStream)) {
                continue;
            }

            $payload = array_shift($buffer);
            $this->sendLine($rStream, "VALUE:" . $payload);
        }

        // If buffer is now empty and channel is closed, notify remaining receivers
        if (($this->closedFlags[$channelName] ?? false) && empty($buffer)) {
            foreach ($receivers as $receiver) {
                if (is_resource($receiver["stream"])) {
                    $this->sendLine($receiver["stream"], "CLOSED_EMPTY");
                }
            }
            $this->pendingReceivers[$channelName] = [];
        }
    }

    // ═════════════════════════════════════════════════════════════════
    //  Low-level I/O
    // ═════════════════════════════════════════════════════════════════

    /**
     * Send a line to a client stream.
     *
     * @param resource $stream The client stream.
     * @param string   $line   The line to send (without trailing newline).
     * @return bool True if the send succeeded.
     */
    private function sendLine($stream, string $line): bool
    {
        if (!is_resource($stream)) {
            return false;
        }

        $data = $line . "\n";
        $len = strlen($data);
        $written = 0;

        @stream_set_blocking($stream, true);

        while ($written < $len) {
            $bytes = @fwrite($stream, substr($data, $written));
            if ($bytes === false || $bytes === 0) {
                @stream_set_blocking($stream, false);
                return false;
            }
            $written += $bytes;
        }

        @fflush($stream);
        @stream_set_blocking($stream, false);
        return true;
    }

    // ═════════════════════════════════════════════════════════════════
    //  Info / stats
    // ═════════════════════════════════════════════════════════════════

    /**
     * Get pool-level information.
     */
    public function getPoolInfo(): array
    {
        $channelInfos = [];
        foreach ($this->buffers as $name => $buffer) {
            $channelInfos[$name] = [
                "capacity" => $this->capacities[$name] ?? 0,
                "size" => count($buffer),
                "closed" => $this->closedFlags[$name] ?? false,
                "pending_receivers" => count(
                    $this->pendingReceivers[$name] ?? [],
                ),
                "pending_senders" => count($this->pendingSenders[$name] ?? []),
            ];
        }

        return [
            "port" => $this->port,
            "channel_count" => count($this->buffers),
            "client_count" => count($this->clients),
            "channels" => $channelInfos,
            "platform" => PHP_OS,
            "idle_timeout" => $this->idleTimeout,
        ];
    }

    // ═════════════════════════════════════════════════════════════════
    //  Cleanup
    // ═════════════════════════════════════════════════════════════════

    /**
     * Clean up all resources.
     */
    public function cleanup(): void
    {
        // Notify and clean up all channels
        foreach (array_keys($this->buffers) as $channelName) {
            // Notify pending receivers
            foreach ($this->pendingReceivers[$channelName] ?? [] as $receiver) {
                if (is_resource($receiver["stream"])) {
                    $this->sendLine($receiver["stream"], "CLOSED_EMPTY");
                }
            }

            // Notify pending senders
            foreach ($this->pendingSenders[$channelName] ?? [] as $sender) {
                if (is_resource($sender["stream"])) {
                    $this->sendLine($sender["stream"], "CLOSED");
                }
            }
        }

        $this->pendingReceivers = [];
        $this->pendingSenders = [];
        $this->buffers = [];
        $this->capacities = [];
        $this->closedFlags = [];

        // Close all client connections
        foreach ($this->clients as $clientId => $stream) {
            if (is_resource($stream)) {
                @fclose($stream);
            }
        }
        $this->clients = [];
        $this->readBuffers = [];
        $this->streamToClientId = [];
        $this->clientChannelMap = [];

        // Close the server socket
        if ($this->serverSocket !== null && is_resource($this->serverSocket)) {
            @fclose($this->serverSocket);
            $this->serverSocket = null;
        }
    }

    /**
     * Destructor — ensure cleanup.
     */
    public function __destruct()
    {
        $this->cleanup();
    }
}
