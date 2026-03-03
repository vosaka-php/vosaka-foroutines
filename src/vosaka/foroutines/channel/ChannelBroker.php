<?php

declare(strict_types=1);

namespace vosaka\foroutines\channel;

use Exception;

/**
 * ChannelBroker — TCP server managing in-memory ring buffer for inter-process
 * channel communication.
 *
 * Architecture:
 *   The broker runs as a background process (via channel_broker_process.php)
 *   and manages a single channel's buffer entirely in-memory. Producers and
 *   consumers (in any process) connect to the broker over TCP loopback sockets
 *   and exchange messages using a simple line-based protocol.
 *
 *   This eliminates the file_get_contents / file_put_contents polling overhead
 *   of the original file-based inter-process Channel implementation. Instead,
 *   we get event-driven, non-blocking I/O via stream_select() — the same
 *   proven pattern used by SocketWorkerManager for worker communication.
 *
 * Protocol (line-based, each message terminated by \n):
 *
 *   Client → Broker:
 *     SEND:<base64(serialize(value))>\n       — send a value into the channel
 *     RECV\n                                   — blocking receive (broker replies when data available)
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
 * Responsibilities:
 *   - Manage the TCP server socket on 127.0.0.1:<port>
 *   - Maintain in-memory ring buffer with capacity limit
 *   - Handle multiple simultaneous client connections via stream_select()
 *   - Queue RECV requests when buffer is empty (push notification)
 *   - Queue SEND requests when buffer is full (backpressure)
 *   - Detect client disconnection and clean up pending requests
 *   - Graceful shutdown via SHUTDOWN command or SIGTERM
 *
 * @internal This class is not part of the public API.
 */
final class ChannelBroker
{
    /** @var resource|null TCP server socket */
    private $serverSocket = null;

    /** @var int TCP port the broker is listening on */
    private int $port = 0;

    /** @var string Channel name for identification */
    private string $channelName;

    /** @var int Maximum buffer capacity (0 = unbounded) */
    private int $capacity;

    /** @var array In-memory ring buffer */
    private array $buffer = [];

    /** @var bool Whether the channel has been closed */
    private bool $closed = false;

    /** @var bool Whether the broker should stop its event loop */
    private bool $shouldStop = false;

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

    /**
     * Pending RECV requests — clients waiting for data.
     * Each entry: ['client_id' => int, 'stream' => resource]
     * @var array<int, array{client_id: int, stream: resource}>
     */
    private array $pendingReceivers = [];

    /**
     * Pending SEND requests — clients waiting for buffer space.
     * Each entry: ['client_id' => int, 'stream' => resource, 'value' => string]
     * @var array<int, array{client_id: int, stream: resource, value: string}>
     */
    private array $pendingSenders = [];

    /** @var int Auto-incrementing client ID */
    private int $nextClientId = 1;

    /** @var array<int, int> Map stream resource ID → client ID */
    private array $streamToClientId = [];

    /** @var float Timestamp of last activity (for idle timeout) */
    private float $lastActivity;

    /** @var float Idle timeout in seconds (0 = no timeout) */
    private float $idleTimeout;

    /**
     * @param string $channelName Unique channel identifier.
     * @param int    $capacity    Maximum buffer size (0 = unbounded).
     * @param float  $idleTimeout Seconds of inactivity before auto-shutdown (0 = never).
     */
    public function __construct(
        string $channelName,
        int $capacity = 0,
        float $idleTimeout = 0.0,
    ) {
        $this->channelName = $channelName;
        $this->capacity = $capacity;
        $this->idleTimeout = $idleTimeout;
        $this->lastActivity = microtime(true);
    }

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
                "ChannelBroker: Failed to create TCP server: [{$errno}] {$errstr}",
            );
        }

        // Extract the actual port assigned by the OS
        $name = stream_socket_get_name($server, false);
        if ($name === false) {
            @fclose($server);
            throw new Exception(
                "ChannelBroker: Failed to get TCP server address",
            );
        }

        $parts = explode(":", $name);
        $this->port = (int) end($parts);
        $this->serverSocket = $server;

        stream_set_blocking($server, false);
    }

    /**
     * Get the port the broker is listening on.
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * Get the channel name.
     */
    public function getChannelName(): string
    {
        return $this->channelName;
    }

    /**
     * Run the broker event loop. This blocks until shutdown.
     *
     * Uses stream_select() for efficient, non-blocking multiplexed I/O.
     * No file polling, no busy-waiting.
     */
    public function run(): void
    {
        if ($this->serverSocket === null) {
            throw new Exception(
                "ChannelBroker: Server not started. Call listen() first.",
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
     * Exposed for testing and embedding in external event loops.
     *
     * @param float $selectTimeout Timeout for stream_select in seconds.
     * @return bool False if the broker should stop.
     */
    public function tick(float $selectTimeout = 0.05): bool
    {
        if ($this->serverSocket === null || !is_resource($this->serverSocket)) {
            return false;
        }

        // Build the set of streams to watch for readability
        $readStreams = [$this->serverSocket];
        foreach ($this->clients as $clientId => $stream) {
            if (is_resource($stream)) {
                $readStreams[] = $stream;
            } else {
                // Stream gone — clean up
                $this->removeClient($clientId);
            }
        }

        $write = null;
        $except = null;

        // stream_select timeout: seconds + microseconds
        $tvSec = (int) floor($selectTimeout);
        $tvUsec = (int) (($selectTimeout - $tvSec) * 1_000_000);

        $ready = @stream_select($readStreams, $write, $except, $tvSec, $tvUsec);

        if ($ready === false) {
            // stream_select error — likely interrupted by signal
            return !$this->shouldStop;
        }

        if ($ready > 0) {
            $this->lastActivity = microtime(true);

            foreach ($readStreams as $stream) {
                if ($stream === $this->serverSocket) {
                    // New client connection
                    $this->acceptClient();
                } else {
                    // Data from an existing client
                    $clientId = $this->streamToClientId[(int) $stream] ?? null;
                    if ($clientId !== null) {
                        $this->readFromClient($clientId);
                    }
                }
            }
        }

        // Check idle timeout
        if (
            $this->idleTimeout > 0 &&
            empty($this->clients) &&
            (microtime(true) - $this->lastActivity) > $this->idleTimeout
        ) {
            $this->shouldStop = true;
        }

        return !$this->shouldStop;
    }

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

        // Disable Nagle's algorithm for lower latency
        if (function_exists('socket_import_stream')) {
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
            // Client disconnected
            $this->removeClient($clientId);
            return;
        }

        $this->readBuffers[$clientId] .= $chunk;

        // Process all complete lines
        while (($nlPos = strpos($this->readBuffers[$clientId], "\n")) !== false) {
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
     * Handle a single command from a client.
     */
    private function handleCommand(int $clientId, string $line): void
    {
        $stream = $this->clients[$clientId] ?? null;
        if ($stream === null || !is_resource($stream)) {
            return;
        }

        // ── SEND:<payload> ───────────────────────────────────────────
        if (str_starts_with($line, "SEND:")) {
            $payload = substr($line, 5);
            $this->handleSend($clientId, $stream, $payload);
            return;
        }

        // ── TRY_SEND:<payload> ───────────────────────────────────────
        if (str_starts_with($line, "TRY_SEND:")) {
            $payload = substr($line, 9);
            $this->handleTrySend($clientId, $stream, $payload);
            return;
        }

        // ── RECV ─────────────────────────────────────────────────────
        if ($line === "RECV") {
            $this->handleRecv($clientId, $stream);
            return;
        }

        // ── TRY_RECV ─────────────────────────────────────────────────
        if ($line === "TRY_RECV") {
            $this->handleTryRecv($clientId, $stream);
            return;
        }

        // ── CLOSE ────────────────────────────────────────────────────
        if ($line === "CLOSE") {
            $this->handleClose($clientId, $stream);
            return;
        }

        // ── IS_CLOSED ────────────────────────────────────────────────
        if ($line === "IS_CLOSED") {
            $this->sendLine($stream, $this->closed ? "TRUE" : "FALSE");
            return;
        }

        // ── IS_EMPTY ─────────────────────────────────────────────────
        if ($line === "IS_EMPTY") {
            $this->sendLine($stream, empty($this->buffer) ? "TRUE" : "FALSE");
            return;
        }

        // ── IS_FULL ──────────────────────────────────────────────────
        if ($line === "IS_FULL") {
            $isFull =
                $this->capacity > 0 &&
                count($this->buffer) >= $this->capacity;
            $this->sendLine($stream, $isFull ? "TRUE" : "FALSE");
            return;
        }

        // ── SIZE ─────────────────────────────────────────────────────
        if ($line === "SIZE") {
            $this->sendLine($stream, "SIZE:" . count($this->buffer));
            return;
        }

        // ── INFO ─────────────────────────────────────────────────────
        if ($line === "INFO") {
            $info = [
                "capacity" => $this->capacity,
                "size" => count($this->buffer),
                "closed" => $this->closed,
                "inter_process" => true,
                "channel_name" => $this->channelName,
                "transport" => "socket",
                "clients" => count($this->clients),
                "pending_receivers" => count($this->pendingReceivers),
                "pending_senders" => count($this->pendingSenders),
                "platform" => PHP_OS,
                "port" => $this->port,
            ];
            $encoded = base64_encode(serialize($info));
            $this->sendLine($stream, "INFO:" . $encoded);
            return;
        }

        // ── SHUTDOWN ─────────────────────────────────────────────────
        if ($line === "SHUTDOWN") {
            $this->sendLine($stream, "OK");
            $this->shouldStop = true;
            return;
        }

        // ── Unknown command ──────────────────────────────────────────
        $this->sendLine($stream, "ERROR:Unknown command");
    }

    /**
     * Handle SEND command — blocking send.
     *
     * If there's a pending receiver, deliver directly (rendezvous).
     * If buffer has space, buffer the value and respond OK.
     * If buffer is full, queue the sender (backpressure).
     */
    private function handleSend(int $clientId, $stream, string $payload): void
    {
        if ($this->closed) {
            $this->sendLine($stream, "CLOSED");
            return;
        }

        // Fast path: if there's a pending receiver, deliver directly
        while (!empty($this->pendingReceivers)) {
            $receiver = array_shift($this->pendingReceivers);
            $rStream = $receiver["stream"];

            // Check if the receiver is still connected
            if (!is_resource($rStream)) {
                continue;
            }

            // Deliver value directly to waiting receiver
            $this->sendLine($rStream, "VALUE:" . $payload);
            $this->sendLine($stream, "OK");
            return;
        }

        // Buffer has space (or unbounded)
        if ($this->capacity === 0 || count($this->buffer) < $this->capacity) {
            $this->buffer[] = $payload;
            $this->sendLine($stream, "OK");
            return;
        }

        // Buffer full — queue this sender for later (backpressure)
        $this->pendingSenders[] = [
            "client_id" => $clientId,
            "stream" => $stream,
            "value" => $payload,
        ];
    }

    /**
     * Handle TRY_SEND command — non-blocking send.
     */
    private function handleTrySend(int $clientId, $stream, string $payload): void
    {
        if ($this->closed) {
            $this->sendLine($stream, "CLOSED");
            return;
        }

        // Fast path: deliver directly to a waiting receiver
        while (!empty($this->pendingReceivers)) {
            $receiver = array_shift($this->pendingReceivers);
            $rStream = $receiver["stream"];

            if (!is_resource($rStream)) {
                continue;
            }

            $this->sendLine($rStream, "VALUE:" . $payload);
            $this->sendLine($stream, "OK");
            return;
        }

        // Buffer has space (or unbounded)
        if ($this->capacity === 0 || count($this->buffer) < $this->capacity) {
            $this->buffer[] = $payload;
            $this->sendLine($stream, "OK");
            return;
        }

        // Buffer full — non-blocking, just report FULL
        $this->sendLine($stream, "FULL");
    }

    /**
     * Handle RECV command — blocking receive.
     *
     * If buffer has data, shift and respond.
     * If a sender is waiting (backpressure), accept their value.
     * If buffer is empty and channel is open, queue the receiver.
     * If buffer is empty and channel is closed, respond CLOSED_EMPTY.
     */
    private function handleRecv(int $clientId, $stream): void
    {
        // If buffer has data, deliver immediately
        if (!empty($this->buffer)) {
            $payload = array_shift($this->buffer);
            $this->sendLine($stream, "VALUE:" . $payload);

            // If there are pending senders (buffer was full), accept one now
            $this->drainOnePendingSender();
            return;
        }

        // Buffer empty — try to get from a pending sender directly
        while (!empty($this->pendingSenders)) {
            $sender = array_shift($this->pendingSenders);
            $sStream = $sender["stream"];

            if (!is_resource($sStream)) {
                continue;
            }

            // Deliver sender's value directly to this receiver
            $this->sendLine($stream, "VALUE:" . $sender["value"]);
            // Tell the sender their send succeeded
            $this->sendLine($sStream, "OK");
            return;
        }

        // Buffer empty, no pending senders
        if ($this->closed) {
            $this->sendLine($stream, "CLOSED_EMPTY");
            return;
        }

        // Queue this receiver — broker will notify when data arrives
        $this->pendingReceivers[] = [
            "client_id" => $clientId,
            "stream" => $stream,
        ];
    }

    /**
     * Handle TRY_RECV command — non-blocking receive.
     */
    private function handleTryRecv(int $clientId, $stream): void
    {
        if (!empty($this->buffer)) {
            $payload = array_shift($this->buffer);
            $this->sendLine($stream, "VALUE:" . $payload);

            // Accept a pending sender if any
            $this->drainOnePendingSender();
            return;
        }

        // Buffer empty — try direct from pending sender
        while (!empty($this->pendingSenders)) {
            $sender = array_shift($this->pendingSenders);
            $sStream = $sender["stream"];

            if (!is_resource($sStream)) {
                continue;
            }

            $this->sendLine($stream, "VALUE:" . $sender["value"]);
            $this->sendLine($sStream, "OK");
            return;
        }

        // Nothing available
        if ($this->closed) {
            $this->sendLine($stream, "CLOSED_EMPTY");
            return;
        }

        $this->sendLine($stream, "EMPTY");
    }

    /**
     * Handle CLOSE command.
     *
     * Closes the channel:
     *   - No more sends are accepted.
     *   - Pending receivers are notified with CLOSED_EMPTY (if buffer is empty)
     *     or will receive remaining buffered data.
     *   - Pending senders are notified with CLOSED.
     */
    private function handleClose(int $clientId, $stream): void
    {
        if ($this->closed) {
            $this->sendLine($stream, "OK");
            return;
        }

        $this->closed = true;
        $this->sendLine($stream, "OK");

        // Notify all pending senders that the channel is closed
        foreach ($this->pendingSenders as $sender) {
            if (is_resource($sender["stream"])) {
                $this->sendLine($sender["stream"], "CLOSED");
            }
        }
        $this->pendingSenders = [];

        // Notify pending receivers that have no data to receive
        if (empty($this->buffer)) {
            foreach ($this->pendingReceivers as $receiver) {
                if (is_resource($receiver["stream"])) {
                    $this->sendLine($receiver["stream"], "CLOSED_EMPTY");
                }
            }
            $this->pendingReceivers = [];
        }
        // If buffer still has data, pending receivers will get it via
        // future RECV/TRY_RECV commands or the next tick.
        // But we should also try to drain pending receivers with available data.
        $this->drainPendingReceiversFromBuffer();
    }

    /**
     * Accept one pending sender's value into the buffer.
     * Called after a receive frees up buffer space.
     */
    private function drainOnePendingSender(): void
    {
        while (!empty($this->pendingSenders)) {
            $sender = array_shift($this->pendingSenders);
            $sStream = $sender["stream"];

            if (!is_resource($sStream)) {
                continue;
            }

            if ($this->closed) {
                $this->sendLine($sStream, "CLOSED");
                continue;
            }

            // Accept the value into the buffer
            $this->buffer[] = $sender["value"];
            $this->sendLine($sStream, "OK");
            return;
        }
    }

    /**
     * Drain pending receivers from the buffer.
     * Called after close when buffer still has data and receivers are waiting.
     */
    private function drainPendingReceiversFromBuffer(): void
    {
        while (!empty($this->pendingReceivers) && !empty($this->buffer)) {
            $receiver = array_shift($this->pendingReceivers);
            $rStream = $receiver["stream"];

            if (!is_resource($rStream)) {
                continue;
            }

            $payload = array_shift($this->buffer);
            $this->sendLine($rStream, "VALUE:" . $payload);
        }

        // If buffer is now empty and channel is closed, notify remaining receivers
        if ($this->closed && empty($this->buffer)) {
            foreach ($this->pendingReceivers as $receiver) {
                if (is_resource($receiver["stream"])) {
                    $this->sendLine($receiver["stream"], "CLOSED_EMPTY");
                }
            }
            $this->pendingReceivers = [];
        }
    }

    /**
     * Remove a client and clean up all its pending requests.
     */
    private function removeClient(int $clientId): void
    {
        $stream = $this->clients[$clientId] ?? null;

        // Remove from pending receivers
        $this->pendingReceivers = array_values(
            array_filter(
                $this->pendingReceivers,
                fn($r) => $r["client_id"] !== $clientId,
            ),
        );

        // Remove from pending senders
        $this->pendingSenders = array_values(
            array_filter(
                $this->pendingSenders,
                fn($s) => $s["client_id"] !== $clientId,
            ),
        );

        // Close the stream
        if ($stream !== null && is_resource($stream)) {
            unset($this->streamToClientId[(int) $stream]);
            @fclose($stream);
        }

        unset($this->clients[$clientId]);
        unset($this->readBuffers[$clientId]);
    }

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

        // Temporarily set blocking for reliable write
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

    /**
     * Signal the broker to stop after the current tick.
     */
    public function stop(): void
    {
        $this->shouldStop = true;
    }

    /**
     * Check if the broker is still running (hasn't been signaled to stop).
     */
    public function isRunning(): bool
    {
        return !$this->shouldStop;
    }

    /**
     * Get the number of connected clients.
     */
    public function getClientCount(): int
    {
        return count($this->clients);
    }

    /**
     * Get the number of pending receivers.
     */
    public function getPendingReceiverCount(): int
    {
        return count($this->pendingReceivers);
    }

    /**
     * Get the number of pending senders.
     */
    public function getPendingSenderCount(): int
    {
        return count($this->pendingSenders);
    }

    /**
     * Get the current buffer size.
     */
    public function getBufferSize(): int
    {
        return count($this->buffer);
    }

    /**
     * Check if the channel is closed.
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Clean up all resources: close client connections, close server socket.
     */
    public function cleanup(): void
    {
        // Notify all pending receivers
        foreach ($this->pendingReceivers as $receiver) {
            if (is_resource($receiver["stream"])) {
                $this->sendLine($receiver["stream"], "CLOSED_EMPTY");
            }
        }
        $this->pendingReceivers = [];

        // Notify all pending senders
        foreach ($this->pendingSenders as $sender) {
            if (is_resource($sender["stream"])) {
                $this->sendLine($sender["stream"], "CLOSED");
            }
        }
        $this->pendingSenders = [];

        // Close all client connections
        foreach ($this->clients as $clientId => $stream) {
            if (is_resource($stream)) {
                @fclose($stream);
            }
        }
        $this->clients = [];
        $this->readBuffers = [];
        $this->streamToClientId = [];

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
