<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Fiber;
use RuntimeException;
use InvalidArgumentException;
use Exception;

/**
 * AsyncIO — Non-blocking stream I/O via stream_select() for true async I/O
 * in Dispatchers::DEFAULT context (single-process, fiber-based).
 *
 * This class bridges PHP's blocking I/O gap by wrapping stream operations
 * with stream_select() and cooperative Fiber yielding. Instead of spawning
 * a child process (Dispatchers::IO) for every network/file operation, tasks
 * using AsyncIO can perform non-blocking I/O within the main process while
 * other fibers continue to make progress.
 *
 * Architecture:
 *   - A global registry of pending stream watchers (read/write)
 *   - Each watcher is associated with a suspended Fiber
 *   - The scheduler tick (pollOnce) calls stream_select() with zero or
 *     micro timeout to check readiness, then resumes ready fibers
 *   - Fibers call the static helper methods (read, write, connect, etc.)
 *     which register a watcher and Fiber::suspend() until the stream is ready
 *
 * Usage from within a Fiber (e.g. inside Launch::new or Async::new):
 *
 *     $body = AsyncIO::httpGet('https://example.com/api');
 *     $data = AsyncIO::fileGetContents('/path/to/file');
 *     $socket = AsyncIO::tcpConnect('example.com', 80);
 *     AsyncIO::streamWrite($socket, "GET / HTTP/1.0\r\nHost: example.com\r\n\r\n");
 *     $response = AsyncIO::streamRead($socket);
 */
final class AsyncIO
{
    // ─── Watcher registry ────────────────────────────────────────────

    /**
     * Pending read watchers: stream resource => Fiber
     * @var array<int, array{stream: resource, fiber: Fiber}>
     */
    private static array $readWatchers = [];

    /**
     * Pending write watchers: stream resource => Fiber
     * @var array<int, array{stream: resource, fiber: Fiber}>
     */
    private static array $writeWatchers = [];

    /**
     * Auto-increment ID for watcher keys
     */
    private static int $nextId = 0;

    /**
     * Default stream_select timeout in microseconds.
     * 0 = pure poll (non-blocking), >0 = willing to wait a bit.
     * Kept small so the cooperative scheduler stays responsive.
     */
    private const SELECT_TIMEOUT_US = 200;

    /**
     * Default read chunk size in bytes.
     */
    private const READ_CHUNK_SIZE = 65536;

    /**
     * Default connect timeout in seconds.
     */
    private const CONNECT_TIMEOUT_S = 30;

    /**
     * Default stream operation timeout in seconds.
     */
    private const STREAM_TIMEOUT_S = 30;

    // ─── Scheduler integration ───────────────────────────────────────

    /**
     * Poll all registered stream watchers ONCE using stream_select().
     *
     * This method is designed to be called from the main scheduler loop
     * (Thread::wait, RunBlocking::new, Delay::new, Async::waitOutsideFiber)
     * on every tick, right alongside WorkerPool::run() and Launch::runOnce().
     *
     * It performs a non-blocking stream_select() across all registered
     * read and write streams. For each stream that is ready, the
     * corresponding Fiber is resumed so it can continue its I/O work.
     *
     * @return bool True if at least one fiber was resumed (work was done).
     */
    public static function pollOnce(): bool
    {
        if (empty(self::$readWatchers) && empty(self::$writeWatchers)) {
            return false;
        }

        $readStreams = [];
        $readMap = [];
        foreach (self::$readWatchers as $id => $watcher) {
            if (!is_resource($watcher['stream']) || feof($watcher['stream'])) {
                // Stream closed or EOF — resume fiber so it can handle it
                $fiber = $watcher['fiber'];
                unset(self::$readWatchers[$id]);
                if ($fiber->isSuspended()) {
                    $fiber->resume(false); // signal EOF/error
                }
                continue;
            }
            $readStreams[$id] = $watcher['stream'];
            $readMap[(int) $watcher['stream']] = $id;
        }

        $writeStreams = [];
        $writeMap = [];
        foreach (self::$writeWatchers as $id => $watcher) {
            if (!is_resource($watcher['stream'])) {
                $fiber = $watcher['fiber'];
                unset(self::$writeWatchers[$id]);
                if ($fiber->isSuspended()) {
                    $fiber->resume(false);
                }
                continue;
            }
            $writeStreams[$id] = $watcher['stream'];
            $writeMap[(int) $watcher['stream']] = $id;
        }

        if (empty($readStreams) && empty($writeStreams)) {
            return false;
        }

        $except = null;
        $readReady = array_values($readStreams);
        $writeReady = array_values($writeStreams);

        // Non-blocking select — returns immediately or waits at most SELECT_TIMEOUT_US
        $changed = @stream_select(
            $readReady,
            $writeReady,
            $except,
            0,
            self::SELECT_TIMEOUT_US,
        );

        if ($changed === false || $changed === 0) {
            return false;
        }

        $didWork = false;

        // Resume fibers whose read streams are ready
        if ($readReady) {
            foreach ($readReady as $stream) {
                $streamId = (int) $stream;
                if (isset($readMap[$streamId])) {
                    $watcherId = $readMap[$streamId];
                    if (isset(self::$readWatchers[$watcherId])) {
                        $fiber = self::$readWatchers[$watcherId]['fiber'];
                        unset(self::$readWatchers[$watcherId]);
                        if ($fiber->isSuspended()) {
                            $fiber->resume(true); // signal: stream is ready
                            $didWork = true;
                        }
                    }
                }
            }
        }

        // Resume fibers whose write streams are ready
        if ($writeReady) {
            foreach ($writeReady as $stream) {
                $streamId = (int) $stream;
                if (isset($writeMap[$streamId])) {
                    $watcherId = $writeMap[$streamId];
                    if (isset(self::$writeWatchers[$watcherId])) {
                        $fiber = self::$writeWatchers[$watcherId]['fiber'];
                        unset(self::$writeWatchers[$watcherId]);
                        if ($fiber->isSuspended()) {
                            $fiber->resume(true);
                            $didWork = true;
                        }
                    }
                }
            }
        }

        return $didWork;
    }

    /**
     * Returns true if there are any pending I/O watchers.
     */
    public static function hasPending(): bool
    {
        return !empty(self::$readWatchers) || !empty(self::$writeWatchers);
    }

    /**
     * Returns the number of pending watchers.
     */
    public static function pendingCount(): int
    {
        return count(self::$readWatchers) + count(self::$writeWatchers);
    }

    /**
     * Cancel all pending watchers. Useful for cleanup/shutdown.
     */
    public static function cancelAll(): void
    {
        self::$readWatchers = [];
        self::$writeWatchers = [];
    }

    // ─── Internal watcher helpers ────────────────────────────────────

    /**
     * Register a read watcher for the given stream and suspend the current Fiber.
     *
     * When stream_select() detects the stream is readable, the Fiber will
     * be resumed with `true`. On EOF/error, it will be resumed with `false`.
     *
     * @param resource $stream The stream to watch for readability.
     * @return bool True if the stream became ready, false on EOF/error.
     * @throws RuntimeException If called outside a Fiber context.
     */
    private static function waitForRead($stream): bool
    {
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            throw new RuntimeException(
                'AsyncIO::waitForRead() must be called from within a Fiber. ' .
                'Wrap your code in Launch::new() or Async::new().',
            );
        }

        $id = self::$nextId++;
        self::$readWatchers[$id] = [
            'stream' => $stream,
            'fiber' => $fiber,
        ];

        // Suspend — the scheduler's pollOnce() will resume us
        return (bool) Fiber::suspend();
    }

    /**
     * Register a write watcher for the given stream and suspend the current Fiber.
     *
     * @param resource $stream The stream to watch for writability.
     * @return bool True if the stream became ready, false on error.
     * @throws RuntimeException If called outside a Fiber context.
     */
    private static function waitForWrite($stream): bool
    {
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            throw new RuntimeException(
                'AsyncIO::waitForWrite() must be called from within a Fiber. ' .
                'Wrap your code in Launch::new() or Async::new().',
            );
        }

        $id = self::$nextId++;
        self::$writeWatchers[$id] = [
            'stream' => $stream,
            'fiber' => $fiber,
        ];

        return (bool) Fiber::suspend();
    }

    // ─── High-level async I/O primitives ─────────────────────────────

    /**
     * Open a non-blocking TCP connection to a host:port.
     *
     * This is the async equivalent of fsockopen(). The Fiber suspends
     * while the TCP handshake completes, allowing other fibers to run.
     *
     * @param string $host Hostname or IP address.
     * @param int $port Port number.
     * @param float $timeoutSeconds Connection timeout in seconds.
     * @return resource The connected socket stream.
     * @throws RuntimeException On connection failure or timeout.
     */
    public static function tcpConnect(
        string $host,
        int $port,
        float $timeoutSeconds = self::CONNECT_TIMEOUT_S,
    ) {
        $address = "tcp://{$host}:{$port}";

        // Create non-blocking socket
        $context = stream_context_create();
        $socket = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            0, // non-blocking connect — timeout handled below
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            $context,
        );

        if ($socket === false) {
            throw new RuntimeException(
                "Failed to initiate TCP connection to {$address}: [{$errno}] {$errstr}",
            );
        }

        stream_set_blocking($socket, false);

        // Wait for the socket to become writable (= connection established)
        $start = microtime(true);
        while (true) {
            $ready = self::waitForWrite($socket);
            if ($ready) {
                // Verify the connection actually succeeded
                $error = socket_get_status($socket);
                if (!empty($error['timed_out'])) {
                    fclose($socket);
                    throw new RuntimeException(
                        "TCP connection to {$address} timed out",
                    );
                }
                return $socket;
            }

            if (microtime(true) - $start >= $timeoutSeconds) {
                fclose($socket);
                throw new RuntimeException(
                    "TCP connection to {$address} timed out after {$timeoutSeconds}s",
                );
            }
        }
    }

    /**
     * Asynchronously connect via TLS/SSL.
     *
     * @param string $host Hostname.
     * @param int $port Port number (default 443).
     * @param float $timeoutSeconds Connection timeout.
     * @return resource The connected TLS socket stream.
     * @throws RuntimeException On connection failure.
     */
    public static function tlsConnect(
        string $host,
        int $port = 443,
        float $timeoutSeconds = self::CONNECT_TIMEOUT_S,
    ) {
        $address = "tls://{$host}:{$port}";

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ]);

        $socket = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            (int) $timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($socket === false) {
            throw new RuntimeException(
                "Failed TLS connection to {$address}: [{$errno}] {$errstr}",
            );
        }

        stream_set_blocking($socket, false);
        return $socket;
    }

    /**
     * Non-blocking read from a stream.
     *
     * Suspends the current Fiber until data is available, then reads up to
     * $maxBytes bytes. Returns empty string on EOF.
     *
     * @param resource $stream The stream to read from.
     * @param int $maxBytes Maximum bytes to read per call.
     * @param float $timeoutSeconds Read timeout.
     * @return string The data read, or empty string on EOF.
     * @throws RuntimeException On read failure or timeout.
     */
    public static function streamRead(
        $stream,
        int $maxBytes = self::READ_CHUNK_SIZE,
        float $timeoutSeconds = self::STREAM_TIMEOUT_S,
    ): string {
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('Expected a valid stream resource');
        }

        stream_set_blocking($stream, false);

        $start = microtime(true);

        while (true) {
            // Try reading immediately — data might already be buffered
            $data = @fread($stream, $maxBytes);

            if ($data !== false && strlen($data) > 0) {
                return $data;
            }

            if (feof($stream)) {
                return '';
            }

            if (microtime(true) - $start >= $timeoutSeconds) {
                throw new RuntimeException(
                    "Stream read timed out after {$timeoutSeconds}s",
                );
            }

            // No data yet — register watcher and suspend
            $ready = self::waitForRead($stream);
            if (!$ready) {
                return ''; // EOF or stream closed
            }
        }
    }

    /**
     * Read ALL available data from a stream until EOF.
     *
     * @param resource $stream The stream to read from.
     * @param float $timeoutSeconds Total read timeout.
     * @return string The complete data.
     * @throws RuntimeException On timeout.
     */
    public static function streamReadAll(
        $stream,
        float $timeoutSeconds = self::STREAM_TIMEOUT_S,
    ): string {
        $buffer = '';
        $start = microtime(true);

        while (true) {
            $chunk = self::streamRead(
                $stream,
                self::READ_CHUNK_SIZE,
                $timeoutSeconds - (microtime(true) - $start),
            );

            if ($chunk === '') {
                break; // EOF
            }

            $buffer .= $chunk;

            if (microtime(true) - $start >= $timeoutSeconds) {
                throw new RuntimeException(
                    "streamReadAll timed out after {$timeoutSeconds}s (read " .
                    strlen($buffer) . ' bytes so far)',
                );
            }
        }

        return $buffer;
    }

    /**
     * Non-blocking write to a stream.
     *
     * Writes the entire $data buffer, suspending the Fiber as needed when
     * the stream's write buffer is full.
     *
     * @param resource $stream The stream to write to.
     * @param string $data The data to write.
     * @param float $timeoutSeconds Write timeout.
     * @return int Total bytes written.
     * @throws RuntimeException On write failure or timeout.
     */
    public static function streamWrite(
        $stream,
        string $data,
        float $timeoutSeconds = self::STREAM_TIMEOUT_S,
    ): int {
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('Expected a valid stream resource');
        }

        stream_set_blocking($stream, false);

        $totalLength = strlen($data);
        $written = 0;
        $start = microtime(true);

        while ($written < $totalLength) {
            $chunk = @fwrite($stream, substr($data, $written));

            if ($chunk === false) {
                throw new RuntimeException(
                    "Stream write failed after {$written}/{$totalLength} bytes",
                );
            }

            if ($chunk > 0) {
                $written += $chunk;
                continue;
            }

            // fwrite returned 0 — buffer is full, wait for writability
            if (microtime(true) - $start >= $timeoutSeconds) {
                throw new RuntimeException(
                    "Stream write timed out after {$timeoutSeconds}s " .
                    "({$written}/{$totalLength} bytes written)",
                );
            }

            $ready = self::waitForWrite($stream);
            if (!$ready) {
                throw new RuntimeException(
                    "Stream became unwritable after {$written}/{$totalLength} bytes",
                );
            }
        }

        return $written;
    }

    // ─── Convenience: async HTTP GET ─────────────────────────────────

    /**
     * Perform a non-blocking HTTP GET request.
     *
     * This is the async equivalent of file_get_contents() for HTTP URLs.
     * The Fiber suspends during network I/O, allowing other fibers to run.
     *
     * Supports both http:// and https:// URLs.
     *
     * @param string $url Full URL (http:// or https://).
     * @param array<string, string> $headers Additional request headers (key => value).
     * @param float $timeoutSeconds Total request timeout.
     * @return string The response body.
     * @throws RuntimeException On connection or HTTP errors.
     * @throws InvalidArgumentException On malformed URL.
     */
    public static function httpGet(
        string $url,
        array $headers = [],
        float $timeoutSeconds = self::STREAM_TIMEOUT_S,
    ): string {
        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['host'])) {
            throw new InvalidArgumentException("Invalid URL: {$url}");
        }

        $scheme = strtolower($parsed['scheme'] ?? 'http');
        $host = $parsed['host'];
        $port = $parsed['port'] ?? ($scheme === 'https' ? 443 : 80);
        $path = ($parsed['path'] ?? '/');
        if (isset($parsed['query'])) {
            $path .= '?' . $parsed['query'];
        }

        // Connect
        if ($scheme === 'https') {
            $socket = self::tlsConnect($host, $port, $timeoutSeconds);
        } else {
            $socket = self::tcpConnect($host, $port, $timeoutSeconds);
        }

        // Build request
        $request = "GET {$path} HTTP/1.1\r\n";
        $request .= "Host: {$host}\r\n";
        $request .= "Connection: close\r\n";
        $request .= "User-Agent: VOsaka-AsyncIO/1.0\r\n";

        foreach ($headers as $key => $value) {
            $request .= "{$key}: {$value}\r\n";
        }

        $request .= "\r\n";

        // Send
        $remainingTimeout = $timeoutSeconds - (microtime(true));
        self::streamWrite($socket, $request, max(1.0, $timeoutSeconds / 2));

        // Read full response
        $response = self::streamReadAll($socket, $timeoutSeconds);
        fclose($socket);

        // Split headers from body
        $headerEnd = strpos($response, "\r\n\r\n");
        if ($headerEnd === false) {
            return $response;
        }

        $responseHeaders = substr($response, 0, $headerEnd);
        $body = substr($response, $headerEnd + 4);

        // Handle chunked transfer encoding
        if (stripos($responseHeaders, 'Transfer-Encoding: chunked') !== false) {
            $body = self::decodeChunked($body);
        }

        return $body;
    }

    /**
     * Perform a non-blocking HTTP POST request.
     *
     * @param string $url Full URL.
     * @param string $body Request body.
     * @param array<string, string> $headers Additional headers.
     * @param string $contentType Content-Type header value.
     * @param float $timeoutSeconds Total request timeout.
     * @return string The response body.
     * @throws RuntimeException On errors.
     */
    public static function httpPost(
        string $url,
        string $body,
        array $headers = [],
        string $contentType = 'application/json',
        float $timeoutSeconds = self::STREAM_TIMEOUT_S,
    ): string {
        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['host'])) {
            throw new InvalidArgumentException("Invalid URL: {$url}");
        }

        $scheme = strtolower($parsed['scheme'] ?? 'http');
        $host = $parsed['host'];
        $port = $parsed['port'] ?? ($scheme === 'https' ? 443 : 80);
        $path = ($parsed['path'] ?? '/');
        if (isset($parsed['query'])) {
            $path .= '?' . $parsed['query'];
        }

        if ($scheme === 'https') {
            $socket = self::tlsConnect($host, $port, $timeoutSeconds);
        } else {
            $socket = self::tcpConnect($host, $port, $timeoutSeconds);
        }

        $request = "POST {$path} HTTP/1.1\r\n";
        $request .= "Host: {$host}\r\n";
        $request .= "Connection: close\r\n";
        $request .= "Content-Type: {$contentType}\r\n";
        $request .= "Content-Length: " . strlen($body) . "\r\n";
        $request .= "User-Agent: VOsaka-AsyncIO/1.0\r\n";

        foreach ($headers as $key => $value) {
            $request .= "{$key}: {$value}\r\n";
        }

        $request .= "\r\n";
        $request .= $body;

        self::streamWrite($socket, $request, max(1.0, $timeoutSeconds / 2));
        $response = self::streamReadAll($socket, $timeoutSeconds);
        fclose($socket);

        $headerEnd = strpos($response, "\r\n\r\n");
        if ($headerEnd === false) {
            return $response;
        }

        $responseHeaders = substr($response, 0, $headerEnd);
        $responseBody = substr($response, $headerEnd + 4);

        if (stripos($responseHeaders, 'Transfer-Encoding: chunked') !== false) {
            $responseBody = self::decodeChunked($responseBody);
        }

        return $responseBody;
    }

    // ─── Convenience: async file I/O ─────────────────────────────────

    /**
     * Non-blocking file read.
     *
     * Opens the file in non-blocking mode and reads its entire contents
     * while cooperatively yielding to other fibers.
     *
     * Note: On many OSes, regular file I/O is always "ready" in
     * stream_select(), but this still benefits from the cooperative
     * yielding pattern for very large files and keeps the API consistent.
     *
     * @param string $filePath Path to the file.
     * @return string The file contents.
     * @throws RuntimeException If the file cannot be opened.
     */
    public static function fileGetContents(string $filePath): string
    {
        $stream = @fopen($filePath, 'rb');
        if ($stream === false) {
            throw new RuntimeException("Cannot open file: {$filePath}");
        }

        stream_set_blocking($stream, false);

        $buffer = '';
        while (!feof($stream)) {
            $chunk = @fread($stream, self::READ_CHUNK_SIZE);

            if ($chunk !== false && strlen($chunk) > 0) {
                $buffer .= $chunk;
            }

            // Yield to let other fibers run even during large reads
            if (Fiber::getCurrent() !== null) {
                Pause::new();
            }
        }

        fclose($stream);
        return $buffer;
    }

    /**
     * Non-blocking file write.
     *
     * @param string $filePath Path to the file.
     * @param string $data Data to write.
     * @param int $flags Same as file_put_contents flags (FILE_APPEND, LOCK_EX, etc.)
     * @return int Bytes written.
     * @throws RuntimeException If the file cannot be opened.
     */
    public static function filePutContents(
        string $filePath,
        string $data,
        int $flags = 0,
    ): int {
        $mode = ($flags & FILE_APPEND) ? 'ab' : 'wb';

        $stream = @fopen($filePath, $mode);
        if ($stream === false) {
            throw new RuntimeException("Cannot open file for writing: {$filePath}");
        }

        if ($flags & LOCK_EX) {
            flock($stream, LOCK_EX);
        }

        stream_set_blocking($stream, false);

        $totalLength = strlen($data);
        $written = 0;

        while ($written < $totalLength) {
            $chunk = @fwrite($stream, substr($data, $written));

            if ($chunk === false) {
                break;
            }

            $written += $chunk;

            // Yield periodically for large writes
            if (Fiber::getCurrent() !== null) {
                Pause::new();
            }
        }

        if ($flags & LOCK_EX) {
            flock($stream, LOCK_UN);
        }

        fclose($stream);
        return $written;
    }

    // ─── Convenience: async DNS resolution ───────────────────────────

    /**
     * Async-friendly DNS resolution.
     *
     * PHP's gethostbyname() is blocking. This wraps it in a Fiber-aware
     * pattern: it yields once before the (potentially slow) DNS lookup
     * so that other fibers get a chance to run, then performs the lookup.
     *
     * For truly non-blocking DNS, consider using Dispatchers::IO to offload
     * the lookup to a child process.
     *
     * @param string $hostname The hostname to resolve.
     * @return string The resolved IP address.
     * @throws RuntimeException If resolution fails.
     */
    public static function dnsResolve(string $hostname): string
    {
        // If it's already an IP, return immediately
        if (filter_var($hostname, FILTER_VALIDATE_IP)) {
            return $hostname;
        }

        // Yield once so other fibers can run before the blocking call
        if (Fiber::getCurrent() !== null) {
            Pause::new();
        }

        $ip = gethostbyname($hostname);

        if ($ip === $hostname) {
            // gethostbyname returns the hostname unchanged on failure
            throw new RuntimeException("DNS resolution failed for: {$hostname}");
        }

        return $ip;
    }

    // ─── Utilities ───────────────────────────────────────────────────

    /**
     * Decode HTTP chunked transfer encoding.
     *
     * @param string $data The chunked-encoded body.
     * @return string The decoded body.
     */
    private static function decodeChunked(string $data): string
    {
        $decoded = '';
        $pos = 0;
        $length = strlen($data);

        while ($pos < $length) {
            // Find the end of the chunk size line
            $lineEnd = strpos($data, "\r\n", $pos);
            if ($lineEnd === false) {
                break;
            }

            // Parse chunk size (hex)
            $chunkSizeHex = trim(substr($data, $pos, $lineEnd - $pos));

            // Handle chunk extensions (e.g., "1a;ext=value")
            $semiPos = strpos($chunkSizeHex, ';');
            if ($semiPos !== false) {
                $chunkSizeHex = substr($chunkSizeHex, 0, $semiPos);
            }

            $chunkSize = hexdec($chunkSizeHex);

            if ($chunkSize === 0) {
                break; // Last chunk
            }

            $chunkStart = $lineEnd + 2; // skip \r\n after size
            $decoded .= substr($data, $chunkStart, (int) $chunkSize);
            $pos = $chunkStart + (int) $chunkSize + 2; // skip chunk data + trailing \r\n
        }

        return $decoded;
    }

    /**
     * Create a pair of connected non-blocking stream sockets.
     *
     * Useful for in-process fiber-to-fiber communication via streams,
     * e.g. piping data between a producer and consumer fiber.
     *
     * @return array{0: resource, 1: resource} [readable, writable] socket pair.
     * @throws RuntimeException If socket pair creation fails.
     */
    public static function createSocketPair(): array
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows: use a loopback TCP pair
            return self::createWindowsSocketPair();
        }

        // Unix: use stream_socket_pair
        $pair = stream_socket_pair(
            STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP,
        );

        if ($pair === false) {
            throw new RuntimeException('Failed to create Unix socket pair');
        }

        stream_set_blocking($pair[0], false);
        stream_set_blocking($pair[1], false);

        return $pair;
    }

    /**
     * Windows-compatible socket pair creation via loopback TCP.
     *
     * @return array{0: resource, 1: resource}
     * @throws RuntimeException On failure.
     */
    private static function createWindowsSocketPair(): array
    {
        $server = @stream_socket_server(
            'tcp://127.0.0.1:0',
            $errno,
            $errstr,
        );

        if ($server === false) {
            throw new RuntimeException(
                "Failed to create loopback server: [{$errno}] {$errstr}",
            );
        }

        $serverName = stream_socket_get_name($server, false);

        $client = @stream_socket_client(
            "tcp://{$serverName}",
            $errno,
            $errstr,
            1,
        );

        if ($client === false) {
            fclose($server);
            throw new RuntimeException(
                "Failed to connect to loopback: [{$errno}] {$errstr}",
            );
        }

        $accepted = @stream_socket_accept($server, 1);
        fclose($server);

        if ($accepted === false) {
            fclose($client);
            throw new RuntimeException('Failed to accept loopback connection');
        }

        stream_set_blocking($client, false);
        stream_set_blocking($accepted, false);

        return [$client, $accepted];
    }
}
