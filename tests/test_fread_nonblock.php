<?php

/**
 * Minimal test: does non-blocking fread() work on Windows proc_open pipes?
 *
 * Spawns worker_loop.php, sets stdout to non-blocking, then polls with
 * fread() to see if data arrives.
 */

require "../vendor/autoload.php";

use Laravel\SerializableClosure\SerializableClosure;

function debug(string $msg): void
{
    $ts = number_format(microtime(true), 4, ".", "");
    fwrite(STDERR, "[{$ts}] {$msg}" . PHP_EOL);
}

$workerScript =
    dirname(__DIR__) .
    DIRECTORY_SEPARATOR .
    "src" .
    DIRECTORY_SEPARATOR .
    "vosaka" .
    DIRECTORY_SEPARATOR .
    "foroutines" .
    DIRECTORY_SEPARATOR .
    "worker_loop.php";

debug("=== Test: non-blocking fread on proc_open pipes ===");
debug("Worker script: {$workerScript}");

$command = [PHP_BINARY, $workerScript];

$descriptors = [
    0 => ["pipe", "r"], // stdin  – child reads, parent writes
    1 => ["pipe", "w"], // stdout – child writes, parent reads
    2 => ["pipe", "w"], // stderr – child writes, parent reads
];

$process = proc_open($command, $descriptors, $pipes);
if (!is_resource($process)) {
    debug("FATAL: proc_open failed");
    exit(1);
}
debug("proc_open OK");

// ── Strategy A: set non-blocking IMMEDIATELY ─────────────────────────
debug("");
debug("--- Strategy A: set stdout non-blocking immediately ---");

stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

debug("Polling with fread() for READY (non-blocking)...");

$buffer = "";
$deadline = microtime(true) + 10.0;
$foundReady = false;
$pollCount = 0;

while (microtime(true) < $deadline) {
    $pollCount++;

    $chunk = @fread($pipes[1], 65536);

    if ($chunk === false) {
        debug("  poll #{$pollCount}: fread returned FALSE");
        // Check if child died
        $status = proc_get_status($process);
        if (!$status["running"]) {
            debug("  Child died! exit={$status["exitcode"]}");
            $errOut = @stream_get_contents($pipes[2]);
            if ($errOut) {
                debug("  stderr: " . json_encode($errOut));
            }
            break;
        }
    } elseif ($chunk === "") {
        // No data available right now – this is expected for non-blocking
        if ($pollCount <= 5 || $pollCount % 100 === 0) {
            debug("  poll #{$pollCount}: fread returned empty string (no data yet)");
        }
    } else {
        debug("  poll #{$pollCount}: fread returned " . strlen($chunk) . " bytes: " . json_encode($chunk));
        $buffer .= $chunk;
    }

    // Check buffer for READY line
    if (str_contains($buffer, "\n")) {
        $parts = explode("\n", $buffer, 2);
        $firstLine = rtrim($parts[0], "\r");
        if ($firstLine === "READY") {
            debug("  >>> Found READY in buffer!");
            $foundReady = true;
            $buffer = $parts[1] ?? "";
            break;
        }
    }

    usleep(1000); // 1ms
}

debug("Strategy A result: foundReady=" . ($foundReady ? "YES" : "NO") . " after {$pollCount} polls");

if (!$foundReady) {
    debug("");
    debug("--- Strategy A FAILED. Trying Strategy B: stream_select before fread ---");

    // Reset
    $buffer = "";
    $deadline = microtime(true) + 10.0;
    $pollCount = 0;

    while (microtime(true) < $deadline) {
        $pollCount++;

        $read = [$pipes[1]];
        $write = null;
        $except = null;
        $ready = @stream_select($read, $write, $except, 0, 50000); // 50ms timeout

        if ($ready === false) {
            debug("  poll #{$pollCount}: stream_select returned FALSE (error)");
            break;
        } elseif ($ready === 0) {
            if ($pollCount <= 3 || $pollCount % 50 === 0) {
                debug("  poll #{$pollCount}: stream_select returned 0 (no data)");
            }
        } else {
            debug("  poll #{$pollCount}: stream_select says data available");
            $chunk = @fread($pipes[1], 65536);
            debug("  fread returned: " . json_encode($chunk === false ? "FALSE" : (strlen($chunk) . " bytes: " . $chunk)));

            if ($chunk !== false && $chunk !== "") {
                $buffer .= $chunk;
            }
        }

        // Check buffer for READY line
        if (str_contains($buffer, "\n")) {
            $parts = explode("\n", $buffer, 2);
            $firstLine = rtrim($parts[0], "\r");
            if ($firstLine === "READY") {
                debug("  >>> Found READY in buffer!");
                $foundReady = true;
                $buffer = $parts[1] ?? "";
                break;
            }
        }

        // Check if child died
        $status = proc_get_status($process);
        if (!$status["running"]) {
            debug("  Child died! exit={$status["exitcode"]}");
            break;
        }
    }

    debug("Strategy B result: foundReady=" . ($foundReady ? "YES" : "NO") . " after {$pollCount} polls");
}

if (!$foundReady) {
    debug("");
    debug("--- Both strategies FAILED. Trying Strategy C: blocking fgets (known working) ---");

    // Restore blocking
    stream_set_blocking($pipes[1], true);
    stream_set_timeout($pipes[1], 5);

    $line = @fgets($pipes[1]);
    debug("fgets returned: " . json_encode($line));

    if ($line !== false) {
        $line = rtrim($line, "\r\n");
        if ($line === "READY") {
            debug("  >>> Got READY via blocking fgets!");
            $foundReady = true;
        }
    }

    debug("Strategy C result: foundReady=" . ($foundReady ? "YES" : "NO"));

    // Set back to non-blocking for the rest of the test
    stream_set_blocking($pipes[1], false);
}

if (!$foundReady) {
    debug("FATAL: Could not get READY from worker with any strategy");
    @fclose($pipes[0]);
    @fclose($pipes[1]);
    @fclose($pipes[2]);
    proc_terminate($process);
    proc_close($process);
    exit(1);
}

// ── Send a task and poll for result ─────────────────────────────────
debug("");
debug("=== Sending task: return 42 ===");

$closure = function () {
    return 42;
};

$serialized = serialize(new SerializableClosure($closure));
$encoded = base64_encode($serialized);
$taskLine = "TASK:" . $encoded . "\n";

// Stdin stays blocking for writes
$written = fwrite($pipes[0], $taskLine);
fflush($pipes[0]);
debug("Wrote {$written} bytes to stdin");

debug("Polling for RESULT + READY with fread (non-blocking)...");

$buffer = "";
$deadline = microtime(true) + 10.0;
$resultLine = null;
$gotReady2 = false;
$pollCount = 0;

while (microtime(true) < $deadline) {
    $pollCount++;

    $chunk = @fread($pipes[1], 65536);
    if ($chunk !== false && $chunk !== "") {
        debug("  poll #{$pollCount}: got " . strlen($chunk) . " bytes");
        $buffer .= $chunk;
    }

    // Extract complete lines
    while (($nlPos = strpos($buffer, "\n")) !== false) {
        $line = rtrim(substr($buffer, 0, $nlPos), "\r");
        $buffer = substr($buffer, $nlPos + 1);

        if ($line === "") {
            continue;
        }

        debug("  Parsed line: " . json_encode(substr($line, 0, 80)) . (strlen($line) > 80 ? "..." : ""));

        if (str_starts_with($line, "RESULT:")) {
            $resultLine = $line;
        } elseif ($line === "READY") {
            $gotReady2 = true;
        }
    }

    if ($resultLine !== null && $gotReady2) {
        break;
    }

    $status = proc_get_status($process);
    if (!$status["running"]) {
        debug("  Child died!");
        break;
    }

    usleep(1000); // 1ms
}

debug("Poll result: gotResult=" . ($resultLine !== null ? "YES" : "NO") . " gotReady=" . ($gotReady2 ? "YES" : "NO") . " polls={$pollCount}");

if ($resultLine !== null) {
    $payload = substr($resultLine, 7);
    $decoded = base64_decode($payload, true);
    $result = $decoded !== false ? unserialize($decoded) : null;
    debug("Task result: " . var_export($result, true));
    if ($result === 42) {
        debug("PASS: Got 42!");
    } else {
        debug("FAIL: Expected 42");
    }
} else {
    debug("FAIL: No result");
}

// ── Cleanup ──────────────────────────────────────────────────────────
debug("");
debug("=== Sending SHUTDOWN ===");
fwrite($pipes[0], "SHUTDOWN\n");
fflush($pipes[0]);

$deadline = microtime(true) + 3.0;
while (microtime(true) < $deadline) {
    $status = proc_get_status($process);
    if (!$status["running"]) {
        debug("Worker exited (code={$status["exitcode"]})");
        break;
    }
    usleep(10000);
}

@fclose($pipes[0]);
@fclose($pipes[1]);
@fclose($pipes[2]);
@proc_close($process);

debug("Done.");
