<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Closure;
use Laravel\SerializableClosure\SerializableClosure;

/**
 * Dispatches pending tasks to free workers and polls active workers
 * for completed results.
 *
 * This class encapsulates the two core scheduling operations that were
 * previously inlined inside WorkerPool::run():
 *
 *   - dispatchPending() — iterates over idle worker slots and assigns
 *     queued tasks by serializing closures and sending them over the
 *     communication channel. Supports both single-task (TASK:) and
 *     batch (BATCH:) protocols.
 *
 *   - pollResults() — performs a non-blocking read on every busy worker,
 *     parses RESULT/ERROR/READY/BATCH_RESULTS lines, and stores completed
 *     results in {@see WorkerPoolState::$returns}.
 *
 * Both methods are static and operate on the shared state held in
 * {@see WorkerPoolState}. Communication with workers is delegated to
 * {@see WorkerPoolCommunication}, and health checks / respawning to
 * {@see WorkerLifecycle}.
 *
 * Task Batching:
 *   When WorkerPoolState::$batchSize > 1, dispatchPending() groups
 *   multiple pending tasks into a single BATCH: message. The worker
 *   processes them sequentially and returns all results in a single
 *   BATCH_RESULTS: response. This dramatically reduces IPC round-trips
 *   for workloads with many small tasks.
 *
 *   Protocol (batch mode):
 *     Parent → Child:  BATCH:<base64(json([{payload,id}, ...]))>\n
 *     Child → Parent:  BATCH_RESULTS:<base64(json([{id,type,payload}, ...]))>\n
 *                      READY\n
 *
 * @internal This class is not part of the public API.
 */
final class TaskDispatcher
{
    /**
     * Send pending tasks to free workers.
     *
     * Routes to either single-task dispatch or batch dispatch based on
     * the configured WorkerPoolState::$batchSize.
     */
    public static function dispatchPending(): void
    {
        if (empty(WorkerPoolState::$pendingTasks)) {
            return;
        }

        if (WorkerPoolState::$batchSize > 1) {
            self::dispatchBatch();
        } else {
            self::dispatchSingle();
        }
    }

    /**
     * Single-task dispatch (original behavior, batchSize == 1).
     *
     * Iterates over all worker slots. For each idle (non-busy) worker:
     *   1. Checks that the worker process is still alive (respawns if not).
     *   2. Dequeues the next pending task.
     *   3. Serializes the closure via SerializableClosure.
     *   4. Sends the TASK:<base64> line to the worker.
     *   5. Marks the worker as busy and records the active task mapping.
     *
     * If serialization fails, the task is immediately resolved with an
     * error result. If sending fails, the task is re-queued at the front
     * and the worker is respawned.
     */
    private static function dispatchSingle(): void
    {
        foreach (WorkerPoolState::$workers as $i => $worker) {
            if (empty(WorkerPoolState::$pendingTasks)) {
                break;
            }

            if ($worker["busy"]) {
                continue;
            }

            // Check if worker process is still alive
            if (!WorkerLifecycle::isWorkerAlive($i)) {
                WorkerLifecycle::respawnWorker($i);
                continue;
            }

            // Dequeue next pending task
            $task = array_shift(WorkerPoolState::$pendingTasks);
            $closure = $task["closure"];
            $taskId = $task["id"];

            // Serialize the closure
            $encoded = self::serializeClosure($closure, $i);
            if ($encoded === null) {
                WorkerPoolState::$returns[$taskId] =
                    new \RuntimeException("Worker task failed to serialize closure");
                continue;
            }

            // Send to worker
            $sent = WorkerPoolCommunication::sendToWorker(
                $i,
                "TASK:" . $encoded,
            );

            if ($sent) {
                WorkerPoolState::$workers[$i]["busy"] = true;
                WorkerPoolState::$activeTasks[$i] = $taskId;
                // Reset idle timestamp — worker is now busy
                WorkerPoolState::$workerIdleSince[$i] = 0.0;
            } else {
                // Worker is dead or broken — re-queue and respawn
                array_unshift(WorkerPoolState::$pendingTasks, $task);
                WorkerLifecycle::respawnWorker($i);
            }
        }
    }

    /**
     * Batch dispatch (batchSize > 1).
     *
     * For each idle worker, collects up to $batchSize pending tasks,
     * serializes them all, packs them into a single BATCH: message,
     * and sends it to the worker. The worker will process all tasks
     * sequentially and return a single BATCH_RESULTS: response.
     *
     * This reduces IPC round-trips from N to ceil(N / batchSize).
     *
     * Protocol:
     *   BATCH:<base64(json([{"payload":"<base64closure>","id":<int>}, ...]))>
     *
     * The worker responds with:
     *   BATCH_RESULTS:<base64(json([{"id":<int>,"type":"result"|"error","payload":"<base64>"}, ...]))>
     *   READY
     */
    private static function dispatchBatch(): void
    {
        $batchSize = WorkerPoolState::$batchSize;

        foreach (WorkerPoolState::$workers as $i => $worker) {
            if (empty(WorkerPoolState::$pendingTasks)) {
                break;
            }

            if ($worker["busy"]) {
                continue;
            }

            // Check if worker process is still alive
            if (!WorkerLifecycle::isWorkerAlive($i)) {
                WorkerLifecycle::respawnWorker($i);
                continue;
            }

            // Collect up to $batchSize tasks
            $batchTasks = [];
            $batchPayloads = [];
            $taskIds = [];
            $count = 0;

            while (
                !empty(WorkerPoolState::$pendingTasks) &&
                $count < $batchSize
            ) {
                $task = array_shift(WorkerPoolState::$pendingTasks);
                $closure = $task["closure"];
                $taskId = $task["id"];

                $encoded = self::serializeClosure($closure, $i);
                if ($encoded === null) {
                    // Serialization failed — resolve immediately
                    WorkerPoolState::$returns[$taskId] =
                        new \RuntimeException("Worker batch task failed to serialize closure");
                    continue;
                }

                $batchPayloads[] = [
                    "payload" => $encoded,
                    "id" => $taskId,
                ];
                $batchTasks[] = $task;
                $taskIds[] = $taskId;
                $count++;
            }

            if (empty($batchPayloads)) {
                continue;
            }

            // If only one task in the batch, use single-task protocol
            // for backward compatibility with workers that might not
            // support BATCH: yet (though our workers do).
            if (count($batchPayloads) === 1) {
                $sent = WorkerPoolCommunication::sendToWorker(
                    $i,
                    "TASK:" . $batchPayloads[0]["payload"],
                );

                if ($sent) {
                    WorkerPoolState::$workers[$i]["busy"] = true;
                    WorkerPoolState::$activeTasks[$i] = $taskIds[0];
                    WorkerPoolState::$workerIdleSince[$i] = 0.0;
                } else {
                    // Re-queue tasks and respawn
                    foreach (array_reverse($batchTasks) as $t) {
                        array_unshift(WorkerPoolState::$pendingTasks, $t);
                    }
                    WorkerLifecycle::respawnWorker($i);
                }
                continue;
            }

            // Encode the batch as JSON then base64
            $batchJson = json_encode($batchPayloads, JSON_UNESCAPED_SLASHES);
            $batchEncoded = base64_encode($batchJson);

            $sent = WorkerPoolCommunication::sendToWorker(
                $i,
                "BATCH:" . $batchEncoded,
            );

            if ($sent) {
                WorkerPoolState::$workers[$i]["busy"] = true;
                // Store array of task IDs for batch tracking
                WorkerPoolState::$activeTasks[$i] = $taskIds;
                WorkerPoolState::$workerIdleSince[$i] = 0.0;
            } else {
                // Re-queue all tasks and respawn
                foreach (array_reverse($batchTasks) as $t) {
                    array_unshift(WorkerPoolState::$pendingTasks, $t);
                }
                WorkerLifecycle::respawnWorker($i);
            }
        }
    }

    /**
     * Serialize a closure for sending to a worker.
     *
     * Fork mode (Linux/macOS): The child process inherits the entire
     * parent memory via pcntl_fork(), so ALL class and function definitions
     * are already available. Direct serialization is used.
     *
     * Socket mode (Windows / fallback): The child is a fresh PHP process
     * that only has Composer autoload. User classes defined in the entry
     * script are NOT autoloadable, so makeCallableForThread() wraps the
     * closure with file-loading preamble.
     *
     * @param Closure $closure The closure to serialize.
     * @param int     $workerIndex Worker slot index (to determine mode).
     * @return string|null Base64-encoded serialized closure, or null on failure.
     */
    private static function serializeClosure(
        Closure $closure,
        int $workerIndex,
    ): ?string {
        try {
            $isForkMode =
                (WorkerPoolState::$workers[$workerIndex]["mode"] ?? "") ===
                "fork";

            if ($isForkMode) {
                $serialized = serialize(
                    new SerializableClosure(Closure::fromCallable($closure)),
                );
            } else {
                $serialized = serialize(
                    new SerializableClosure(
                        Closure::fromCallable(
                            CallableUtils::makeCallableForThread(
                                $closure,
                                get_included_files(),
                            ),
                        ),
                    ),
                );
            }

            return base64_encode($serialized);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Non-blocking poll of all active workers for completed results.
     *
     * For each busy worker:
     *   1. Reads all available complete lines (non-blocking).
     *   2. Parses each line:
     *      - READY          → marks worker as idle, clears active task mapping,
     *                          updates idle timestamp for dynamic scaling.
     *      - RESULT:        → decodes the base64 payload, deserializes and stores
     *                          the result in WorkerPoolState::$returns.
     *      - ERROR:         → decodes the error payload, stores an error string
     *                          in WorkerPoolState::$returns.
     *      - BATCH_RESULTS: → decodes the JSON array of results, stores each
     *                          result in WorkerPoolState::$returns by task ID.
     *   3. Drains stderr for socket-mode workers (for logging).
     *
     * After processing all busy workers, checks for dead workers that
     * had active tasks — stores an error result for the task(s) and triggers
     * a respawn.
     */
    public static function pollResults(): void
    {
        foreach (WorkerPoolState::$workers as $i => $worker) {
            if (!$worker["busy"]) {
                continue;
            }

            $lines = WorkerPoolCommunication::readLinesFromWorker($i);

            foreach ($lines as $line) {
                if ($line === "READY") {
                    WorkerPoolState::$workers[$i]["busy"] = false;
                    unset(WorkerPoolState::$activeTasks[$i]);
                    // Record when this worker became idle (for dynamic scaling)
                    WorkerPoolState::$workerIdleSince[$i] = microtime(true);
                    // Worker completed a task successfully — reset backoff
                    WorkerLifecycle::resetBackoff($i);
                    continue;
                }

                // ── Single-task RESULT ────────────────────────────
                if (str_starts_with($line, "RESULT:")) {
                    $taskId = self::getActiveTaskId($i);
                    if ($taskId !== null) {
                        $payload = substr($line, 7);
                        try {
                            $decoded = base64_decode($payload, true);
                            if ($decoded === false) {
                                throw new \RuntimeException(
                                    "base64_decode failed",
                                );
                            }
                            WorkerPoolState::$returns[$taskId] = unserialize(
                                $decoded,
                            );
                        } catch (\Throwable $e) {
                            WorkerPoolState::$returns[$taskId] =
                                new \RuntimeException("Failed to decode worker result: " . $e->getMessage(), 0, $e);
                        }
                    }
                    continue;
                }

                // ── Single-task ERROR ─────────────────────────────
                if (str_starts_with($line, "ERROR:")) {
                    $taskId = self::getActiveTaskId($i);
                    if ($taskId !== null) {
                        $payload = substr($line, 6);
                        try {
                            $decoded = base64_decode($payload, true);
                            $errorData =
                                $decoded !== false
                                ? unserialize($decoded)
                                : null;
                            $message = is_array($errorData)
                                ? $errorData["message"] ??
                                "Unknown worker error"
                                : "Unknown worker error";
                            WorkerPoolState::$returns[$taskId] =
                                new \RuntimeException("Worker exception: " . $message);
                        } catch (\Throwable) {
                            WorkerPoolState::$returns[$taskId] =
                                new \RuntimeException("Worker exception (decode failed)");
                        }
                    }
                    continue;
                }

                // ── Batch RESULTS ─────────────────────────────────
                if (str_starts_with($line, "BATCH_RESULTS:")) {
                    $payload = substr($line, 14);
                    self::processBatchResults($i, $payload);
                    continue;
                }
            }

            // Drain stderr for socket-mode workers (for logging)
            if ($worker["mode"] === "socket") {
                WorkerPoolCommunication::drainStderr($i);
            }
        }

        // Check for dead workers that had active tasks
        foreach (WorkerPoolState::$activeTasks as $i => $taskIdOrIds) {
            if (!WorkerLifecycle::isWorkerAlive($i)) {
                // Resolve all task IDs associated with this worker
                $taskIds = is_array($taskIdOrIds)
                    ? $taskIdOrIds
                    : [$taskIdOrIds];
                foreach ($taskIds as $tid) {
                    WorkerPoolState::$returns[$tid] =
                        new \RuntimeException("Worker process died unexpectedly");
                }

                WorkerPoolState::$workers[$i]["busy"] = false;
                unset(WorkerPoolState::$activeTasks[$i]);
                WorkerPoolState::$workerIdleSince[$i] = microtime(true);
                WorkerLifecycle::respawnWorker($i);
            }
        }
    }

    /**
     * Get the single active task ID for a worker.
     *
     * In single-task mode, $activeTasks[$i] is an int.
     * In batch mode, $activeTasks[$i] is an int[] — this method returns
     * the first ID (used as fallback when a worker sends single RESULT:
     * instead of BATCH_RESULTS: for a batch of 1).
     *
     * @param int $i Worker index.
     * @return int|null The task ID, or null if none.
     */
    private static function getActiveTaskId(int $i): ?int
    {
        $active = WorkerPoolState::$activeTasks[$i] ?? null;
        if ($active === null) {
            return null;
        }
        if (is_array($active)) {
            return $active[0] ?? null;
        }
        return $active;
    }

    /**
     * Process a BATCH_RESULTS: payload from a worker.
     *
     * The payload is base64(json([{id, type, payload}, ...])).
     * Each entry maps to a task result:
     *   - type "result" → deserialize and store
     *   - type "error"  → deserialize error data and store error string
     *
     * @param int    $workerIndex Worker slot index.
     * @param string $rawPayload  Base64-encoded JSON array.
     */
    private static function processBatchResults(
        int $workerIndex,
        string $rawPayload,
    ): void {
        try {
            $decoded = base64_decode($rawPayload, true);
            if ($decoded === false) {
                throw new \RuntimeException(
                    "base64_decode failed for batch results",
                );
            }

            $results = json_decode($decoded, true);
            if (!is_array($results)) {
                throw new \RuntimeException(
                    "json_decode failed for batch results",
                );
            }

            foreach ($results as $entry) {
                $taskId = $entry["id"] ?? null;
                $type = $entry["type"] ?? "error";
                $payload = $entry["payload"] ?? "";

                if ($taskId === null) {
                    continue;
                }

                if ($type === "result") {
                    try {
                        $resultDecoded = base64_decode($payload, true);
                        if ($resultDecoded === false) {
                            throw new \RuntimeException("base64_decode failed");
                        }
                        WorkerPoolState::$returns[$taskId] = unserialize(
                            $resultDecoded,
                        );
                    } catch (\Throwable $e) {
                        WorkerPoolState::$returns[$taskId] =
                            new \RuntimeException("Failed to decode worker batch result: " . $e->getMessage(), 0, $e);
                    }
                } elseif ($type === "error") {
                    try {
                        $errorDecoded = base64_decode($payload, true);
                        $errorData =
                            $errorDecoded !== false
                            ? unserialize($errorDecoded)
                            : null;
                        $message = is_array($errorData)
                            ? $errorData["message"] ?? "Unknown worker error"
                            : "Unknown worker error";
                        WorkerPoolState::$returns[$taskId] =
                            new \RuntimeException("Worker exception: " . $message);
                    } catch (\Throwable) {
                        WorkerPoolState::$returns[$taskId] =
                            new \RuntimeException("Worker exception (batch decode failed)");
                    }
                }
            }
        } catch (\Throwable $e) {
            // If batch decode fails entirely, resolve all active tasks for
            // this worker with error results.
            $taskIdOrIds = WorkerPoolState::$activeTasks[$workerIndex] ?? [];
            $taskIds = is_array($taskIdOrIds) ? $taskIdOrIds : [$taskIdOrIds];
            foreach ($taskIds as $tid) {
                if (!array_key_exists($tid, WorkerPoolState::$returns)) {
                    WorkerPoolState::$returns[$tid] =
                        new \RuntimeException("Failed to decode worker batch results: " . $e->getMessage(), 0, $e);
                }
            }
        }
    }

    /** Prevent instantiation */
    private function __construct()
    {
    }
}
