<?php

declare(strict_types=1);

/**
 * Diagnostic script to inspect the Launch scheduler queue and optionally
 * perform joins on queued jobs to observe before/after state.
 *
 * Usage:
 *   php tests/inspect_queue.php          # just print queue snapshot
 *   php tests/inspect_queue.php --join   # perform join() on each queued job and re-print snapshot
 *
 * The script is non-destructive: it clones the Launch::$queue when inspecting
 * to avoid mutating the real scheduler queue during inspection. If you pass
 * `--join`, it will collect the queued jobs into an array and call join() on
 * each (this will drive the tasks to completion and may block until each job
 * finishes).
 *
 * The output includes per-Job details:
 *   - job id
 *   - status (JobState)
 *   - poolManaged (boolean)
 *   - drivingJoin (boolean)
 *   - fiber info (object id + started/suspended/terminated flags)
 *
 * It also prints RuntimeFiberPool stats when available.
 */

require_once __DIR__ . "/../vendor/autoload.php";

use vosaka\foroutines\Launch;
use vosaka\foroutines\RuntimeFiberPool;

function snapshotQueue(): array
{
    if (!isset(Launch::$queue)) {
        echo "Launch::\$queue is not initialized.\n";
        return [];
    }

    $snapshot = [];
    try {
        // Clone the queue so we can inspect without mutating it
        $clone = clone Launch::$queue;

        $i = 0;
        while (!$clone->isEmpty()) {
            $job = $clone->dequeue(); // Job instance
            $entry = [];

            // Basic identity
            $entry["index"] = $i;
            $entry["job_id"] = $job->id ?? null;

            // Status via accessor (Job::getStatus() -> enum name)
            try {
                $status = $job->getStatus();
                $entry["status"] =
                    is_object($status) && property_exists($status, "name")
                        ? $status->name
                        : (string) $status;
            } catch (Throwable $e) {
                $entry["status"] = "UNKNOWN";
            }

            // Pool flags (public on Launch)
            $entry["poolManaged"] = $job->poolManaged ?? false;
            $entry["drivingJoin"] = $job->drivingJoin ?? false;

            // Fiber info (public property on Job)
            $fiber = $job->fiber ?? null;
            if ($fiber === null) {
                $entry["fiber"] = null;
            } else {
                $fiberInfo = [];
                $fiberInfo["object_id"] = function_exists("spl_object_id")
                    ? spl_object_id($fiber)
                    : null;
                try {
                    $fiberInfo["is_started"] = method_exists(
                        $fiber,
                        "isStarted",
                    )
                        ? $fiber->isStarted()
                        : null;
                    $fiberInfo["is_suspended"] = method_exists(
                        $fiber,
                        "isSuspended",
                    )
                        ? $fiber->isSuspended()
                        : null;
                    $fiberInfo["is_terminated"] = method_exists(
                        $fiber,
                        "isTerminated",
                    )
                        ? $fiber->isTerminated()
                        : null;
                } catch (Throwable $e) {
                    $fiberInfo["error"] = $e->getMessage();
                }
                $entry["fiber"] = $fiberInfo;
            }

            $snapshot[] = $entry;
            $i++;
        }
    } catch (Throwable $e) {
        echo "Error while snapshotting Launch::\$queue: " .
            $e->getMessage() .
            PHP_EOL;
    }

    return $snapshot;
}

function printSnapshot(array $snap, string $title = "Queue snapshot"): void
{
    echo "=== {$title} ===\n";
    $count = count($snap);
    echo "Total queued items: {$count}\n";
    foreach ($snap as $entry) {
        $idx = $entry["index"] ?? "?";
        $jid = $entry["job_id"] ?? "?";
        $status = $entry["status"] ?? "UNKNOWN";
        $pool = !empty($entry["poolManaged"]) ? "Y" : "N";
        $driving = !empty($entry["drivingJoin"]) ? "Y" : "N";
        $fiber = $entry["fiber"];
        if ($fiber === null) {
            $fiberDesc = "null";
        } else {
            $fid = $fiber["object_id"] ?? "?";
            $s = isset($fiber["is_started"])
                ? ($fiber["is_started"]
                    ? "started"
                    : "not-started")
                : "?";
            $su = isset($fiber["is_suspended"])
                ? ($fiber["is_suspended"]
                    ? "suspended"
                    : "not-suspended")
                : "?";
            $t = isset($fiber["is_terminated"])
                ? ($fiber["is_terminated"]
                    ? "terminated"
                    : "not-terminated")
                : "?";
            $fiberDesc = "id={$fid} / {$s}, {$su}, {$t}";
        }
        printf(
            "  [%2s] job_id=%s status=%s poolManaged=%s drivingJoin=%s fiber=%s\n",
            $idx,
            $jid,
            $status,
            $pool,
            $driving,
            $fiberDesc,
        );
    }
    echo str_repeat("-", 60) . PHP_EOL;
}

function printRuntimePoolStats(): void
{
    if (!class_exists(RuntimeFiberPool::class)) {
        echo "RuntimeFiberPool class not present.\n";
        return;
    }

    try {
        if (!RuntimeFiberPool::isBooted()) {
            echo "RuntimeFiberPool not booted.\n";
            return;
        }
        $pool = RuntimeFiberPool::getInstance();
        $stats = $pool->stats();
        echo "RuntimeFiberPool stats:\n";
        foreach ($stats as $k => $v) {
            echo "  {$k}: " . var_export($v, true) . PHP_EOL;
        }
    } catch (Throwable $e) {
        echo "Error reading RuntimeFiberPool stats: " .
            $e->getMessage() .
            PHP_EOL;
    }
    echo str_repeat("-", 60) . PHP_EOL;
}

// Main
$doJoin = in_array("--join", $GLOBALS["argv"] ?? [], true);

echo PHP_EOL;
echo "Inspecting Launch queue (non-destructive).\n";
echo "Launch::\$activeCount = " .
    (isset(Launch::$activeCount) ? Launch::$activeCount : "N/A") .
    PHP_EOL;
echo "Launch::\$queue count = " .
    (isset(Launch::$queue) ? count(Launch::$queue) : "N/A") .
    PHP_EOL;
printRuntimePoolStats();

$before = snapshotQueue();
printSnapshot($before, "BEFORE (cloned)");

if (!$doJoin) {
    echo "Done. To drive joins on queued jobs and re-inspect, re-run with --join\n";
    exit(0);
}

// If --join requested: collect queued jobs, perform join() on each
echo "Proceeding to join() each queued job (this will drive tasks to completion)...\n";

// Collect the actual job objects (we must extract from the real queue safely)
// We can't simply iterate Launch::$queue by dequeueing (that would mutate scheduler).
// So clone once and dequeue to get references to the job objects in order.
$jobsToJoin = [];
try {
    if (!isset(Launch::$queue)) {
        echo "Launch::\$queue not initialized, nothing to join.\n";
    } else {
        $cl = clone Launch::$queue;
        while (!$cl->isEmpty()) {
            $jobsToJoin[] = $cl->dequeue();
        }
    }
} catch (Throwable $e) {
    echo "Error while preparing join list: " . $e->getMessage() . PHP_EOL;
}

$total = count($jobsToJoin);
echo "Collected {$total} jobs to join.\n";

$idx = 0;
foreach ($jobsToJoin as $job) {
    $idx++;
    try {
        echo "Joining job {$idx}/{$total} (id=" . ($job->id ?? "?") . ") ... ";
        $res = $job->join();
        echo "OK, result=" . var_export($res, true) . PHP_EOL;
    } catch (Throwable $e) {
        echo "EXCEPTION during join(): " .
            $e::class .
            " - " .
            $e->getMessage() .
            PHP_EOL;
    }
    // Print intermediate pool/queue state snapshot if desired
    if ($idx % 5 === 0) {
        echo "  intermediate: Launch::\$activeCount=" .
            Launch::$activeCount .
            " Launch::\$queue=" .
            count(Launch::$queue) .
            PHP_EOL;
        printRuntimePoolStats();
    }
}

echo "All joins attempted. Final scheduler state:\n";
echo "Launch::\$activeCount = " .
    (isset(Launch::$activeCount) ? Launch::$activeCount : "N/A") .
    PHP_EOL;
echo "Launch::\$queue count = " .
    (isset(Launch::$queue) ? count(Launch::$queue) : "N/A") .
    PHP_EOL;
$after = snapshotQueue();
printSnapshot($after, "AFTER (cloned)");

echo "Inspect complete.\n";
