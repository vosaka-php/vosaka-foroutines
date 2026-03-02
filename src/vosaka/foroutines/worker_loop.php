<?php

/**
 * Persistent Worker Loop Script
 *
 * This script is spawned once per worker slot in the WorkerPool and stays
 * alive for the entire lifetime of the pool. It reads tasks from STDIN,
 * executes them, and writes results back to STDOUT.
 *
 * Protocol (line-based, each message is a single line):
 *
 *   Parent → Child (STDIN):
 *     TASK:<base64-encoded serialized closure>\n
 *     SHUTDOWN\n
 *
 *   Child → Parent (STDOUT):
 *     RESULT:<base64-encoded serialized result>\n
 *     ERROR:<base64-encoded serialized error array>\n
 *     READY\n
 *
 * The worker sends "READY\n" immediately on startup to signal that it is
 * alive and waiting for tasks. After completing each task it also sends
 * READY\n (after the RESULT/ERROR line) to indicate it is free again.
 *
 * All STDERR output is forwarded as-is for debugging purposes.
 */

declare(strict_types=1);

ini_set('display_errors', 'off');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// ── Bootstrap: find and load Composer autoloader ──────────────────────

$possiblePaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
    __DIR__ . '/../../../../vendor/autoload.php',
    __DIR__ . '/../../../../../vendor/autoload.php',
];

$autoloadFound = false;
foreach ($possiblePaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $autoloadFound = true;
        break;
    }
}

if (!$autoloadFound) {
    fwrite(STDERR, "worker_loop: Could not find vendor/autoload.php\n");
    exit(1);
}

use Laravel\SerializableClosure\SerializableClosure;

// ── Helpers ───────────────────────────────────────────────────────────

/**
 * Send a line to STDOUT (the parent reads this).
 */
function worker_send(string $line): void
{
    fwrite(STDOUT, $line . "\n");
    fflush(STDOUT);
}

/**
 * Read a single line from STDIN (blocking).
 * Returns the trimmed line, or false on EOF / error.
 */
function worker_recv(): string|false
{
    $line = fgets(STDIN);
    if ($line === false) {
        return false;
    }
    return rtrim($line, "\r\n");
}

// ── Signal the parent that we are alive ───────────────────────────────

worker_send('READY');

// ── Main loop ─────────────────────────────────────────────────────────

while (true) {
    $line = worker_recv();

    // EOF means the parent closed our STDIN → time to exit
    if ($line === false) {
        break;
    }

    // Empty line (e.g. stray newline) → ignore
    if ($line === '') {
        continue;
    }

    // Graceful shutdown command
    if ($line === 'SHUTDOWN') {
        break;
    }

    // ── Task dispatch ─────────────────────────────────────────────
    if (str_starts_with($line, 'TASK:')) {
        $payload = substr($line, 5); // strip "TASK:" prefix

        try {
            $decoded = base64_decode($payload, true);
            if ($decoded === false) {
                throw new RuntimeException('Failed to base64_decode task payload');
            }

            /** @var SerializableClosure $sc */
            $sc = unserialize($decoded);
            $closure = $sc->getClosure();

            // Execute the closure
            $result = $closure();

            // If the closure returned a Generator, exhaust it
            if ($result instanceof Generator) {
                $genResult = null;
                while ($result->valid()) {
                    $genResult = $result->current();
                    $result->next();
                }
                try {
                    $returnValue = $result->getReturn();
                    if ($returnValue !== null) {
                        $genResult = $returnValue;
                    }
                } catch (Exception) {
                    // Generator didn't use return; use last yielded value
                }
                $result = $genResult;
            }

            $serializedResult = serialize($result);
            $encodedResult = base64_encode($serializedResult);

            worker_send('RESULT:' . $encodedResult);
        } catch (Throwable $e) {
            $errorData = [
                '__worker_error__' => true,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ];

            $encodedError = base64_encode(serialize($errorData));
            worker_send('ERROR:' . $encodedError);

            fwrite(STDERR, 'worker_loop error: ' . $e->getMessage() . "\n");
        }

        // Signal readiness for next task
        worker_send('READY');
        continue;
    }

    // Unknown command → log and ignore
    fwrite(STDERR, "worker_loop: unknown command: {$line}\n");
}

exit(0);
