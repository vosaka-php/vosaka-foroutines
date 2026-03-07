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
 *     BATCH:<base64(json([{"payload":"<base64closure>","id":<int>}, ...]))>\n
 *     SHUTDOWN\n
 *
 *   Child → Parent (STDOUT):
 *     RESULT:<base64-encoded serialized result>\n
 *     ERROR:<base64-encoded serialized error array>\n
 *     BATCH_RESULTS:<base64(json([{"id":<int>,"type":"result"|"error","payload":"<base64>"}, ...]))>\n
 *     READY\n
 *
 * The worker sends "READY\n" immediately on startup to signal that it is
 * alive and waiting for tasks. After completing each task it also sends
 * READY\n (after the RESULT/ERROR line) to indicate it is free again.
 *
 * All STDERR output is forwarded as-is for debugging purposes.
 */

declare(strict_types=1);

ini_set("display_errors", "off");
ini_set("log_errors", "1");
error_reporting(E_ALL);

// ── Bootstrap: find and load Composer autoloader ──────────────────────

require_once __DIR__ . '/script_functions.php';
require_once findAutoload(__DIR__);

\vosaka\foroutines\WorkerPoolState::$isWorker = true;

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

/**
 * Execute a batch of task closures and send all results back as a single message.
 *
 * Protocol:
 *   Input:  BATCH:<base64(json([{"payload":"<base64closure>","id":<int>}, ...]))>
 *   Output: BATCH_RESULTS:<base64(json([{"id":<int>,"type":"result"|"error","payload":"<base64>"}, ...]))>
 *           READY
 *
 * @param string $base64Payload Base64-encoded JSON array of task entries.
 */
function worker_execute_batch(string $base64Payload): void
{
    $results = [];

    try {
        $decoded = base64_decode($base64Payload, true);
        if ($decoded === false) {
            throw new RuntimeException("Failed to base64_decode batch payload");
        }

        $tasks = json_decode($decoded, true);
        if (!is_array($tasks)) {
            throw new RuntimeException("Failed to json_decode batch payload");
        }

        foreach ($tasks as $taskEntry) {
            $taskId = $taskEntry["id"] ?? null;
            $taskPayload = $taskEntry["payload"] ?? null;

            if ($taskId === null || $taskPayload === null) {
                continue;
            }

            try {
                $closureDecoded = base64_decode($taskPayload, true);
                if ($closureDecoded === false) {
                    throw new RuntimeException(
                        "Failed to base64_decode task payload",
                    );
                }

                /** @var SerializableClosure $sc */
                $sc = unserialize($closureDecoded);
                $closure = $sc->getClosure();

                // Execute the closure
                $result = \vosaka\foroutines\CallableUtils::executeTask($closure);

                $results[] = [
                    "id" => $taskId,
                    "type" => "result",
                    "payload" => base64_encode(serialize($result)),
                ];
            } catch (Throwable $e) {
                $errorData = \vosaka\foroutines\CallableUtils::buildWorkerError($e);
                $results[] = [
                    "id" => $taskId,
                    "type" => "error",
                    "payload" => base64_encode(serialize($errorData)),
                ];

                fwrite(
                    STDERR,
                    "worker_loop batch error: " . $e->getMessage() . "\n",
                );
            }
        }
    } catch (Throwable $e) {
        fwrite(
            STDERR,
            "worker_loop: batch decode failed: " . $e->getMessage() . "\n",
        );
    }

    // Send all results in a single message
    $batchJson = json_encode($results, JSON_UNESCAPED_SLASHES);
    $batchEncoded = base64_encode($batchJson);
    worker_send("BATCH_RESULTS:" . $batchEncoded);

    // Signal readiness for next task/batch
    worker_send("READY");
}

// ── Signal the parent that we are alive ───────────────────────────────

worker_send("READY");

// ── Main loop ─────────────────────────────────────────────────────────

while (true) {
    $line = worker_recv();

    // EOF means the parent closed our STDIN → time to exit
    if ($line === false) {
        break;
    }

    // Empty line (e.g. stray newline) → ignore
    if ($line === "") {
        continue;
    }

    // Graceful shutdown command
    if ($line === "SHUTDOWN") {
        break;
    }

    // ── Task dispatch (single task) ───────────────────────────────
    if (str_starts_with($line, "TASK:")) {
        $payload = substr($line, 5); // strip "TASK:" prefix

        try {
            $decoded = base64_decode($payload, true);
            if ($decoded === false) {
                throw new RuntimeException(
                    "Failed to base64_decode task payload",
                );
            }

            /** @var SerializableClosure $sc */
            $sc = unserialize($decoded);
            $closure = $sc->getClosure();

            // Execute the closure
            $result = \vosaka\foroutines\CallableUtils::executeTask($closure);

            $serializedResult = serialize($result);
            $encodedResult = base64_encode($serializedResult);

            worker_send("RESULT:" . $encodedResult);
        } catch (Throwable $e) {
            $errorData = \vosaka\foroutines\CallableUtils::buildWorkerError($e);

            $encodedError = base64_encode(serialize($errorData));
            worker_send("ERROR:" . $encodedError);

            fwrite(STDERR, "worker_loop error: " . $e->getMessage() . "\n");
        }

        // Signal readiness for next task
        worker_send("READY");
        continue;
    }

    // ── Batch dispatch (multiple tasks) ───────────────────────────
    if (str_starts_with($line, "BATCH:")) {
        $payload = substr($line, 6); // strip "BATCH:" prefix
        worker_execute_batch($payload);
        continue;
    }

    // Unknown command → log and ignore
    fwrite(STDERR, "worker_loop: unknown command: {$line}\n");
}

exit(0);
