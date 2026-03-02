<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Exception;

/**
 * Manages socket-based worker processes (Windows / fallback when pcntl
 * is not available).
 *
 * Socket workers communicate with the parent over a TCP loopback socket
 * rather than Unix socket pairs or proc_open pipes (which are broken on
 * Windows for non-blocking I/O).
 *
 * Architecture:
 *   1. Parent creates a TCP server on 127.0.0.1 with an ephemeral port.
 *   2. Each worker is spawned via proc_open running worker_socket_loop.php
 *      with the port number as an argument.
 *   3. The child connects to the TCP server; parent accepts the connection.
 *   4. All subsequent communication (TASK/RESULT/ERROR/READY/SHUTDOWN)
 *      goes through the accepted TCP stream, NOT through stdin/stdout pipes.
 *   5. The stdin pipe is kept open only so the child can detect parent death
 *      (EOF on stdin).
 *
 * Protocol (line-based, each message is a single line over TCP):
 *   Parent → Child:  TASK:<base64(serialize(SerializableClosure))>\n
 *                     SHUTDOWN\n
 *   Child → Parent:  READY\n
 *                     RESULT:<base64(serialize(result))>\n
 *                     ERROR:<base64(serialize(errorArray))>\n
 *
 * Responsibilities:
 *   - createTcpServer()          — bind a TCP server on loopback
 *   - spawn()                    — spawn a single socket-based worker
 *   - acceptWorkerConnection()   — accept a TCP connection from a child
 *   - shutdown()                 — gracefully stop a socket-based worker
 *
 * All methods are static and operate on the shared state held in
 * {@see WorkerPoolState}.
 *
 * @internal This class is not part of the public API.
 */
final class SocketWorkerManager
{
    /**
     * Creates a TCP server on loopback (127.0.0.1) with an ephemeral port.
     * Workers will connect to this server for bidirectional communication.
     *
     * TCP sockets on Windows fully support:
     *   - stream_set_blocking(false)
     *   - stream_select() (correctly reports readability)
     *   - fread() / fwrite() in non-blocking mode
     *
     * This is in contrast to proc_open pipes which are broken on Windows:
     *   - stream_set_blocking(false) silently fails
     *   - stream_select() always returns 1
     *   - stream_set_timeout() is ignored by fgets()
     *
     * @throws Exception If the server socket cannot be created.
     */
    public static function createTcpServer(): void
    {
        // Bind to 127.0.0.1 on port 0 (OS picks a free ephemeral port)
        $server = @stream_socket_server(
            "tcp://127.0.0.1:0",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );

        if ($server === false) {
            throw new Exception(
                "WorkerPool: Failed to create TCP server: [{$errno}] {$errstr}",
            );
        }

        // Extract the port that was assigned
        $name = stream_socket_get_name($server, false);
        if ($name === false) {
            @fclose($server);
            throw new Exception("WorkerPool: Failed to get TCP server address");
        }
        $parts = explode(":", $name);
        $port = (int) end($parts);

        WorkerPoolState::$serverSocket = $server;
        WorkerPoolState::$serverPort = $port;

        // Set server to non-blocking so stream_socket_accept doesn't block
        stream_set_blocking($server, false);
    }

    /**
     * Spawns a single socket-based worker using proc_open with the
     * persistent worker_socket_loop.php script.
     *
     * Communication goes through a TCP loopback socket, NOT through
     * the proc_open pipes (which are broken on Windows for non-blocking
     * I/O). The stdin pipe is kept open only to detect when the parent
     * dies (the child polls for EOF on stdin).
     *
     * Boot sequence:
     *   1. Parent has a TCP server listening on 127.0.0.1:<port>.
     *   2. Parent spawns child with the port number as an argument.
     *   3. Child connects to 127.0.0.1:<port>.
     *   4. Parent accepts the connection (stream_socket_accept).
     *   5. Parent sets the accepted connection to non-blocking.
     *   6. Child sends "READY\n" over the TCP socket.
     *   7. Parent polls with stream_select() (works correctly on TCP).
     *
     * All subsequent communication (TASK/RESULT/ERROR/READY/SHUTDOWN)
     * goes through the TCP socket, not through stdin/stdout pipes.
     *
     * @param int $index Worker slot index.
     * @throws Exception If the worker script is missing, proc_open fails,
     *                    or the child fails to connect via TCP.
     */
    public static function spawn(int $index): void
    {
        $workerScript =
            __DIR__ . DIRECTORY_SEPARATOR . "worker_socket_loop.php";

        if (!is_file($workerScript)) {
            throw new Exception(
                "WorkerPool: worker_socket_loop.php not found at {$workerScript}",
            );
        }

        $command = [PHP_BINARY, $workerScript, (string) WorkerPoolState::$serverPort];

        $descriptors = [
            0 => ["pipe", "r"], // STDIN  — kept open as heartbeat
            1 => ["pipe", "w"], // STDOUT — not used, but must exist
            2 => ["pipe", "w"], // STDERR — forwarded for debugging
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new Exception(
                "WorkerPool: Failed to spawn worker process {$index}",
            );
        }

        // We don't use stdout/stderr pipes for communication — just
        // keep them to prevent the child from getting broken-pipe errors.
        // Set them non-blocking so we can drain without blocking.
        if (is_resource($pipes[1])) {
            stream_set_blocking($pipes[1], false);
        }
        if (is_resource($pipes[2])) {
            stream_set_blocking($pipes[2], false);
        }

        // Accept the TCP connection from the child. The child connects
        // to our server immediately after starting. We use a blocking
        // accept with a timeout.
        $conn = self::acceptWorkerConnection(10.0);

        if ($conn === null) {
            // Child failed to connect — kill it and throw
            @fclose($pipes[0]);
            @fclose($pipes[1]);
            @fclose($pipes[2]);
            proc_terminate($process, 9);
            @proc_close($process);
            throw new Exception(
                "WorkerPool: Worker {$index} failed to connect via TCP",
            );
        }

        // Set the accepted connection to non-blocking for polling.
        // This WORKS on TCP sockets on Windows (unlike proc_open pipes).
        stream_set_blocking($conn, false);

        WorkerPoolState::$workers[$index] = [
            "busy" => false,
            "process" => $process,
            "stdin" => $pipes[0],
            "stdout" => $pipes[1],
            "stderr" => $pipes[2],
            "conn" => $conn,
            "mode" => "socket",
        ];
        WorkerPoolState::$readBuffers[$index] = "";

        // Wait for READY signal over TCP
        WorkerPoolCommunication::waitForReady($index, 10.0);
    }

    /**
     * Accept a single TCP connection from a worker child.
     * Polls the server socket with short timeouts until a connection
     * arrives or the deadline is reached.
     *
     * @param float $timeoutSeconds Maximum seconds to wait.
     * @return resource|null The accepted stream, or null on timeout.
     */
    public static function acceptWorkerConnection(float $timeoutSeconds): mixed
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $conn = @stream_socket_accept(WorkerPoolState::$serverSocket, 0.05);
            if ($conn !== false) {
                return $conn;
            }
            usleep(5_000);
        }

        return null;
    }

    /**
     * Shutdown a socket-based worker. Send SHUTDOWN, close connection,
     * terminate process.
     *
     * Attempts a graceful shutdown by sending the SHUTDOWN command over
     * the TCP connection. If the child doesn't exit within 2 seconds,
     * it is forcibly terminated with signal 9 (SIGKILL).
     *
     * @param int $index Worker slot index.
     */
    public static function shutdown(int $index): void
    {
        $worker = WorkerPoolState::$workers[$index] ?? null;
        if ($worker === null) {
            return;
        }

        // Send SHUTDOWN over TCP connection
        $conn = $worker["conn"] ?? null;
        if (is_resource($conn)) {
            try {
                // Temporarily make blocking for the send
                stream_set_blocking($conn, true);
                @fwrite($conn, "SHUTDOWN\n");
                @fflush($conn);
            } catch (\Throwable) {
            }
            @fclose($conn);
        }

        // Close the stdin pipe (signals parent death to child)
        if (is_resource($worker["stdin"] ?? null)) {
            @fclose($worker["stdin"]);
        }
        if (is_resource($worker["stdout"] ?? null)) {
            @fclose($worker["stdout"]);
        }
        if (is_resource($worker["stderr"] ?? null)) {
            @fclose($worker["stderr"]);
        }

        // Wait for process to exit (with timeout)
        $process = $worker["process"] ?? null;
        if (is_resource($process)) {
            $deadline = microtime(true) + 2.0;
            while (microtime(true) < $deadline) {
                $status = proc_get_status($process);
                if (!$status["running"]) {
                    break;
                }
                usleep(10_000);
            }
            $status = proc_get_status($process);
            if ($status["running"]) {
                proc_terminate($process, 9);
            }
            @proc_close($process);
        }
    }

    /** Prevent instantiation */
    private function __construct() {}
}
