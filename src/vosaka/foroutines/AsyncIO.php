<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Fiber;
use RuntimeException;
use InvalidArgumentException;

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
 * All public I/O methods return an Deferred instance. The actual
 * work is deferred until the caller invokes ->await(), making the async
 * nature of the code explicit and consistent:
 *
 *     $body = AsyncIO::httpGet('https://example.com/api')->await();
 *     $data = AsyncIO::fileGetContents('/path/to/file')->await();
 *     $socket = AsyncIO::tcpConnect('example.com', 80)->await();
 *     AsyncIO::streamWrite($socket, "GET / HTTP/1.0\r\nHost: example.com\r\n\r\n")->await();
 *     $response = AsyncIO::streamRead($socket)->await();
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
     * (Thread::await, RunBlocking::new, Delay::new, Async::waitOutsideFiber)
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
            if (!is_resource($watcher["stream"])) {
                // Stream closed — resume fiber so it can handle it
                $fiber = $watcher["fiber"];
                unset(self::$readWatchers[$id]);
                if ($fiber->isSuspended()) {
                    $fiber->resume(false); // signal EOF/error
                }
                continue;
            }
            $readStreams[$id] = $watcher["stream"];
            $readMap[(int) $watcher["stream"]] = $id;
        }

        $writeStreams = [];
        $writeMap = [];
        foreach (self::$writeWatchers as $id => $watcher) {
            if (!is_resource($watcher["stream"])) {
                $fiber = $watcher["fiber"];
                unset(self::$writeWatchers[$id]);
                if ($fiber->isSuspended()) {
                    $fiber->resume(false);
                }
                continue;
            }
            $writeStreams[$id] = $watcher["stream"];
            $writeMap[(int) $watcher["stream"]] = $id;
        }

        if (empty($readStreams) && empty($writeStreams)) {
            return false;
        }

        $except = null;
        $readReady = array_values($readStreams);
        $writeReady = array_values($writeStreams);
        $except = null;

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

        if ($readReady) {
            foreach ($readReady as $stream) {
                $streamId = (int) $stream;
                if (isset($readMap[$streamId])) {
                    $watcherId = $readMap[$streamId];
                    if (isset(self::$readWatchers[$watcherId])) {
                        $fiber = self::$readWatchers[$watcherId]["fiber"];
                        unset(self::$readWatchers[$watcherId]);
                        if ($fiber->isSuspended()) {
                            $fiber->resume(true);
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
                        $fiber = self::$writeWatchers[$watcherId]["fiber"];
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
     * Resets all static watcher state to initial values.
     *
     * This is used by ForkProcess after pcntl_fork() to clear stale
     * read/write watchers inherited from the parent process. Stream
     * resources and their associated Fibers are meaningless in the
     * child's address space and would cause hasPending() to return
     * true indefinitely, preventing Thread::await() from terminating.
     */
    public static function resetState(): void
    {
        self::$readWatchers = [];
        self::$writeWatchers = [];
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
     * IMPORTANT: If the scheduler resumes the fiber without an argument (spurious
     * wake-up), this method returns `null`. Callers MUST check for null and
     * typically continue their I/O loop.
     *
     * @param resource $stream The stream to watch for readability.
     * @return bool|null True if ready, false on EOF/error, null on spurious wake-up.
     * @throws RuntimeException If called outside a Fiber context.
     */
    private static function waitForRead($stream): ?bool
    {
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            throw new RuntimeException(
                "AsyncIO::waitForRead() must be called from within a Fiber. " .
                    "Wrap your code in Launch::new() or Async::new().",
            );
        }

        $id = self::$nextId++;
        self::$readWatchers[$id] = [
            "stream" => $stream,
            "fiber" => $fiber,
        ];

        // Suspend — the scheduler's pollOnce() will resume us with true/false,
        // or the scheduler loop will resume us with null.
        $result = Fiber::suspend();

        if (isset(self::$readWatchers[$id])) {
            unset(self::$readWatchers[$id]);
        }

        return $result === null ? null : (bool) $result;
    }

    /**
     * Register a write watcher for the given stream and suspend the current Fiber.
     *
     * @param resource $stream The stream to watch for writability.
     * @return bool|null True if ready, false on error, null on spurious wake-up.
     * @throws RuntimeException If called outside a Fiber context.
     */
    private static function waitForWrite($stream): ?bool
    {
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            throw new RuntimeException(
                "AsyncIO::waitForWrite() must be called from within a Fiber. " .
                    "Wrap your code in Launch::new() or Async::new().",
            );
        }

        $id = self::$nextId++;
        self::$writeWatchers[$id] = [
            "stream" => $stream,
            "fiber" => $fiber,
        ];

        $result = Fiber::suspend();

        if (isset(self::$writeWatchers[$id])) {
            unset(self::$writeWatchers[$id]);
        }

        return $result === null ? null : (bool) $result;
    }

    // ─── High-level async I/O primitives (return Deferred) ───

    /**
     * Open a non-blocking TCP connection to a host:port.
     *
     * This is the async equivalent of fsockopen(). The Fiber suspends
     * while the TCP handshake completes, allowing other fibers to run.
     *
     * @param string $host Hostname or IP address.
     * @param int $port Port number.
     * @param float $timeoutSeconds Connection timeout in seconds.
     * @return Deferred Resolves to the connected socket stream resource.
     */
    public static function tcpConnect(
        string $host,
        int $port,
        float $timeoutSeconds = self::CONNECT_TIMEOUT_S,
    ): Deferred {
        return new LazyDeferred(static function () use (
            $host,
            $port,
            $timeoutSeconds,
        ) {
            return self::doTcpConnect($host, $port, $timeoutSeconds);
        });
    }

    /**
     * Asynchronously connect via TLS/SSL.
     *
     * @param string $host Hostname.
     * @param int $port Port number (default 443).
     * @param float $timeoutSeconds Connection timeout.
     * @return Deferred Resolves to the connected TLS socket stream resource.
     */
    public static function tlsConnect(
        string $host,
        int $port = 443,
        float $timeoutSeconds = self::CONNECT_TIMEOUT_S,
    ): Deferred {
        return new LazyDeferred(static function () use (
            $host,
            $port,
            $timeoutSeconds,
        ) {
            return self::doTlsConnect($host, $port, $timeoutSeconds);
        });
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
     * @return Deferred Resolves to the data read, or empty string on EOF.
     */
    public static function streamRead(
        $stream,
        int $maxBytes = self::READ_CHUNK_SIZE,
        float $timeoutSeconds = self::STREAM_TIMEOUT_S,
    ): Deferred {
        return new LazyDeferred(static function () use (
            $stream,
            $maxBytes,
            $timeoutSeconds,
        ): string {
            return self::doStreamRead($stream, $maxBytes, $timeoutSeconds);
        });
    }

    /**
     * Read ALL available data from a stream until EOF.
     *
     * @param resource $stream The stream to read from.
     * @param float $timeoutSeconds Total read timeout.
     * @return Deferred Resolves to the complete data string.
     */
    public static function streamReadAll(
        $stream,
        float $timeoutSeconds = self::STREAM_TIMEOUT_S,
    ): Deferred {
        return new LazyDeferred(static function () use (
            $stream,
            $timeoutSeconds,
        ): string {
            return self::doStreamReadAll($stream, $timeoutSeconds);
        });
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
     * @return Deferred Resolves to total bytes written (int).
     */
    public static function streamWrite(
        $stream,
        string $data,
        float $timeoutSeconds = self::STREAM_TIMEOUT_S,
    ): Deferred {
        return new LazyDeferred(static function () use (
            $stream,
            $data,
            $timeoutSeconds,
        ): int {
            return self::doStreamWrite($stream, $data, $timeoutSeconds);
        });
    }

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
     * @return Deferred Resolves to the response body string.
     */
    public static function httpGet(
        string $url,
        array $headers = [],
        float $timeoutSeconds = self::STREAM_TIMEOUT_S,
    ): Deferred {
        return new LazyDeferred(static function () use (
            $url,
            $headers,
            $timeoutSeconds,
        ): string {
            return self::doHttpGet($url, $headers, $timeoutSeconds);
        });
    }

    /**
     * Perform a non-blocking HTTP POST request.
     *
     * @param string $url Full URL.
     * @param string $body Request body.
     * @param array<string, string> $headers Additional headers.
     * @param string $contentType Content-Type header value.
     * @param float $timeoutSeconds Total request timeout.
     * @return Deferred Resolves to the response body string.
     */
    public static function httpPost(
        string $url,
        string $body,
        array $headers = [],
        string $contentType = "application/json",
        float $timeoutSeconds = self::STREAM_TIMEOUT_S,
    ): Deferred {
        return new LazyDeferred(static function () use (
            $url,
            $body,
            $headers,
            $contentType,
            $timeoutSeconds,
        ): string {
            return self::doHttpPost(
                $url,
                $body,
                $headers,
                $contentType,
                $timeoutSeconds,
            );
        });
    }

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
     * @return Deferred Resolves to the file contents string.
     */
    public static function fileGetContents(string $filePath): Deferred
    {
        return new LazyDeferred(static function () use ($filePath): string {
            return self::doFileGetContents($filePath);
        });
    }

    /**
     * Non-blocking file write.
     *
     * @param string $filePath Path to the file.
     * @param string $data Data to write.
     * @param int $flags Same as file_put_contents flags (FILE_APPEND, LOCK_EX, etc.)
     * @return Deferred Resolves to bytes written (int).
     */
    public static function filePutContents(
        string $filePath,
        string $data,
        int $flags = 0,
    ): Deferred {
        return new LazyDeferred(static function () use (
            $filePath,
            $data,
            $flags,
        ): int {
            return self::doFilePutContents($filePath, $data, $flags);
        });
    }

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
     * @return Deferred Resolves to the resolved IP address string.
     */
    public static function dnsResolve(string $hostname): Deferred
    {
        return new LazyDeferred(static function () use ($hostname): string {
            return self::doDnsResolve($hostname);
        });
    }

    /**
     * Create a pair of connected non-blocking stream sockets.
     *
     * Useful for in-process fiber-to-fiber communication via streams,
     * e.g. piping data between a producer and consumer fiber.
     *
     * @return Deferred Resolves to array{0: resource, 1: resource} [readable, writable] socket pair.
     */
    public static function createSocketPair(): Deferred
    {
        return new LazyDeferred(static function (): array {
            return self::doCreateSocketPair();
        });
    }

    // ─── Internal implementations ────────────────────────────────────

    /**
     * @param string $host
     * @param int $port
     * @param float $timeoutSeconds
     * @return resource
     * @throws RuntimeException
     */
    private static function doTcpConnect(
        string $host,
        int $port,
        float $timeoutSeconds,
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

            if ($ready === null) {
                // Spurious scheduler wake-up
                continue;
            }

            if ($ready) {
                // Verify the connection actually succeeded
                $error = socket_get_status($socket);
                if (!empty($error["timed_out"])) {
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
     * @param string $host
     * @param int $port
     * @param float $timeoutSeconds
     * @return resource
     * @throws RuntimeException
     */
    private static function doTlsConnect(
        string $host,
        int $port,
        float $timeoutSeconds,
    ) {
        $address = "tls://{$host}:{$port}";

        $context = stream_context_create([
            "ssl" => [
                "verify_peer" => true,
                "verify_peer_name" => true,
                "allow_self_signed" => false,
                "SNI_enabled" => true,
                "peer_name" => $host,
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
     * @param resource $stream
     * @param int $maxBytes
     * @param float $timeoutSeconds
     * @return string
     * @throws RuntimeException|InvalidArgumentException
     */
    private static function doStreamRead(
        $stream,
        int $maxBytes,
        float $timeoutSeconds,
    ): string {
        if (!is_resource($stream)) {
            throw new InvalidArgumentException(
                "Expected a valid stream resource",
            );
        }

        stream_set_blocking($stream, false);

        $start = microtime(true);

        while (true) {
            // Try reading immediately — data might already be buffered
            $data = @fread($stream, $maxBytes);

            if ($data !== false && $data !== "") {
                return $data;
            }

            if ($data === false) {
                return "";
            }

            if (microtime(true) - $start >= $timeoutSeconds) {
                throw new RuntimeException(
                    "Stream read timed out after {$timeoutSeconds}s",
                );
            }

            // No data right now — register watcher and suspend until stream_select says it's ready.
            $ready = self::waitForRead($stream);

            if ($ready === null) {
                // Spurious scheduler wake-up — loop again and try fread
                continue;
            }

            if (!$ready) {
                return ""; // Stream closed (signalled by pollOnce)
            }

            // pollOnce (via stream_select) said the stream is readable.
            // Attempt a read. If it's STILL empty, it definitively means EOF.
            $data = @fread($stream, $maxBytes);
            if ($data === false || $data === "") {
                return "";
            }

            return $data;
        }
    }

    /**
     * @param resource $stream
     * @param float $timeoutSeconds
     * @return string
     * @throws RuntimeException
     */
    private static function doStreamReadAll(
        $stream,
        float $timeoutSeconds,
    ): string {
        $buffer = "";
        $start = microtime(true);

        while (true) {
            $chunk = self::doStreamRead(
                $stream,
                self::READ_CHUNK_SIZE,
                $timeoutSeconds - (microtime(true) - $start),
            );

            if ($chunk === "") {
                break; // EOF
            }

            $buffer .= $chunk;

            if (microtime(true) - $start >= $timeoutSeconds) {
                throw new RuntimeException(
                    "streamReadAll timed out after {$timeoutSeconds}s (read " .
                        strlen($buffer) .
                        " bytes so far)",
                );
            }
        }

        return $buffer;
    }

    /**
     * @param resource $stream
     * @param string $data
     * @param float $timeoutSeconds
     * @return int
     * @throws RuntimeException|InvalidArgumentException
     */
    private static function doStreamWrite(
        $stream,
        string $data,
        float $timeoutSeconds,
    ): int {
        if (!is_resource($stream)) {
            throw new InvalidArgumentException(
                "Expected a valid stream resource",
            );
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

            if ($ready === null) {
                continue;
            }

            if (!$ready) {
                throw new RuntimeException(
                    "Stream became unwritable after {$written}/{$totalLength} bytes",
                );
            }
        }

        return $written;
    }

    /**
     * @param string $url
     * @param array<string, string> $headers
     * @param float $timeoutSeconds
     * @return string
     * @throws RuntimeException|InvalidArgumentException
     */
    private static function doHttpGet(
        string $url,
        array $headers,
        float $timeoutSeconds,
    ): string {
        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed["host"])) {
            throw new InvalidArgumentException("Invalid URL: {$url}");
        }

        $scheme = strtolower($parsed["scheme"] ?? "http");
        $host = $parsed["host"];
        $port = $parsed["port"] ?? ($scheme === "https" ? 443 : 80);
        $path = $parsed["path"] ?? "/";
        if (isset($parsed["query"])) {
            $path .= "?" . $parsed["query"];
        }

        // Connect
        if ($scheme === "https") {
            $socket = self::doTlsConnect($host, $port, $timeoutSeconds);
        } else {
            $socket = self::doTcpConnect($host, $port, $timeoutSeconds);
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
        self::doStreamWrite($socket, $request, max(1.0, $timeoutSeconds / 2));

        // Read full response
        $response = self::doStreamReadAll($socket, $timeoutSeconds);
        fclose($socket);

        // Split headers from body
        $headerEnd = strpos($response, "\r\n\r\n");
        if ($headerEnd === false) {
            return $response;
        }

        $responseHeaders = substr($response, 0, $headerEnd);
        $body = substr($response, $headerEnd + 4);

        // Handle chunked transfer encoding
        if (stripos($responseHeaders, "Transfer-Encoding: chunked") !== false) {
            $body = self::decodeChunked($body);
        }

        return $body;
    }

    /**
     * @param string $url
     * @param string $body
     * @param array<string, string> $headers
     * @param string $contentType
     * @param float $timeoutSeconds
     * @return string
     * @throws RuntimeException|InvalidArgumentException
     */
    private static function doHttpPost(
        string $url,
        string $body,
        array $headers,
        string $contentType,
        float $timeoutSeconds,
    ): string {
        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed["host"])) {
            throw new InvalidArgumentException("Invalid URL: {$url}");
        }

        $scheme = strtolower($parsed["scheme"] ?? "http");
        $host = $parsed["host"];
        $port = $parsed["port"] ?? ($scheme === "https" ? 443 : 80);
        $path = $parsed["path"] ?? "/";
        if (isset($parsed["query"])) {
            $path .= "?" . $parsed["query"];
        }

        if ($scheme === "https") {
            $socket = self::doTlsConnect($host, $port, $timeoutSeconds);
        } else {
            $socket = self::doTcpConnect($host, $port, $timeoutSeconds);
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

        self::doStreamWrite($socket, $request, max(1.0, $timeoutSeconds / 2));
        $response = self::doStreamReadAll($socket, $timeoutSeconds);
        fclose($socket);

        $headerEnd = strpos($response, "\r\n\r\n");
        if ($headerEnd === false) {
            return $response;
        }

        $responseHeaders = substr($response, 0, $headerEnd);
        $responseBody = substr($response, $headerEnd + 4);

        if (stripos($responseHeaders, "Transfer-Encoding: chunked") !== false) {
            $responseBody = self::decodeChunked($responseBody);
        }

        return $responseBody;
    }

    /**
     * @param string $filePath
     * @return string
     * @throws RuntimeException
     */
    private static function doFileGetContents(string $filePath): string
    {
        $stream = @fopen($filePath, "rb");
        if ($stream === false) {
            throw new RuntimeException("Cannot open file: {$filePath}");
        }

        stream_set_blocking($stream, false);

        $buffer = "";
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
     * @param string $filePath
     * @param string $data
     * @param int $flags
     * @return int
     * @throws RuntimeException
     */
    private static function doFilePutContents(
        string $filePath,
        string $data,
        int $flags,
    ): int {
        $mode = $flags & FILE_APPEND ? "ab" : "wb";

        $stream = @fopen($filePath, $mode);
        if ($stream === false) {
            throw new RuntimeException(
                "Cannot open file for writing: {$filePath}",
            );
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

    /**
     * @param string $hostname
     * @return string
     * @throws RuntimeException
     */
    private static function doDnsResolve(string $hostname): string
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
            throw new RuntimeException(
                "DNS resolution failed for: {$hostname}",
            );
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
        $decoded = "";
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
            $semiPos = strpos($chunkSizeHex, ";");
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
     * @return array{0: resource, 1: resource}
     * @throws RuntimeException
     */
    private static function doCreateSocketPair(): array
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === "WIN") {
            // Windows: use a loopback TCP pair
            return self::doCreateWindowsSocketPair();
        }

        // Unix: use stream_socket_pair
        $pair = stream_socket_pair(
            STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP,
        );

        if ($pair === false) {
            throw new RuntimeException("Failed to create Unix socket pair");
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
    private static function doCreateWindowsSocketPair(): array
    {
        $server = @stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr);

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
            throw new RuntimeException("Failed to accept loopback connection");
        }

        stream_set_blocking($client, false);
        stream_set_blocking($accepted, false);

        return [$client, $accepted];
    }
}
