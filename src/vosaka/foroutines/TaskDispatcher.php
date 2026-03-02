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
 *     communication channel.
 *
 *   - pollResults() — performs a non-blocking read on every busy worker,
 *     parses RESULT/ERROR/READY lines, and stores completed results in
 *     {@see WorkerPoolState::$returns}.
 *
 * Both methods are static and operate on the shared state held in
 * {@see WorkerPoolState}. Communication with workers is delegated to
 * {@see WorkerPoolCommunication}, and health checks / respawning to
 * {@see WorkerLifecycle}.
 *
 * @internal This class is not part of the public API.
 */
final class TaskDispatcher
{
    /**
     * Send pending tasks to free workers.
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
    public static function dispatchPending(): void
    {
        if (empty(WorkerPoolState::$pendingTasks)) {
            return;
        }

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

            // Serialize the closure using SerializableClosure
            try {
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
                $encoded = base64_encode($serialized);
            } catch (\Throwable $e) {
                WorkerPoolState::$returns[$taskId] =
                    "Error: Failed to serialize closure: " . $e->getMessage();
                continue;
            }

            // Send to worker
            $sent = WorkerPoolCommunication::sendToWorker($i, "TASK:" . $encoded);

            if ($sent) {
                WorkerPoolState::$workers[$i]["busy"] = true;
                WorkerPoolState::$activeTasks[$i] = $taskId;
            } else {
                // Worker is dead or broken — re-queue and respawn
                array_unshift(WorkerPoolState::$pendingTasks, $task);
                WorkerLifecycle::respawnWorker($i);
            }
        }
    }

    /**
     * Non-blocking poll of all active workers for completed results.
     *
     * For each busy worker:
     *   1. Reads all available complete lines (non-blocking).
     *   2. Parses each line:
     *      - READY   → marks worker as idle, clears active task mapping.
     *      - RESULT: → decodes the base64 payload, deserializes and stores
     *                   the result in WorkerPoolState::$returns.
     *      - ERROR:  → decodes the error payload, stores an error string
     *                   in WorkerPoolState::$returns.
     *   3. Drains stderr for socket-mode workers (for logging).
     *
     * After processing all busy workers, checks for dead workers that
     * had active tasks — stores an error result for the task and triggers
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
                    continue;
                }

                $taskId = WorkerPoolState::$activeTasks[$i] ?? null;

                if (str_starts_with($line, "RESULT:") && $taskId !== null) {
                    $payload = substr($line, 7);
                    try {
                        $decoded = base64_decode($payload, true);
                        if ($decoded === false) {
                            throw new \RuntimeException("base64_decode failed");
                        }
                        WorkerPoolState::$returns[$taskId] = unserialize($decoded);
                    } catch (\Throwable $e) {
                        WorkerPoolState::$returns[$taskId] =
                            "Error: Failed to decode result: " .
                            $e->getMessage();
                    }
                    continue;
                }

                if (str_starts_with($line, "ERROR:") && $taskId !== null) {
                    $payload = substr($line, 6);
                    try {
                        $decoded = base64_decode($payload, true);
                        $errorData =
                            $decoded !== false ? unserialize($decoded) : null;
                        $message = is_array($errorData)
                            ? $errorData["message"] ?? "Unknown worker error"
                            : "Unknown worker error";
                        WorkerPoolState::$returns[$taskId] = "Error: " . $message;
                    } catch (\Throwable) {
                        WorkerPoolState::$returns[$taskId] =
                            "Error: Worker error (decode failed)";
                    }
                    continue;
                }
            }

            // Drain stderr for socket-mode workers (for logging)
            if ($worker["mode"] === "socket") {
                WorkerPoolCommunication::drainStderr($i);
            }
        }

        // Check for dead workers that had active tasks
        foreach (WorkerPoolState::$activeTasks as $i => $taskId) {
            if (!WorkerLifecycle::isWorkerAlive($i)) {
                WorkerPoolState::$returns[$taskId] =
                    "Error: Worker process died unexpectedly";
                WorkerPoolState::$workers[$i]["busy"] = false;
                unset(WorkerPoolState::$activeTasks[$i]);
                WorkerLifecycle::respawnWorker($i);
            }
        }
    }

    /** Prevent instantiation */
    private function __construct() {}
}
