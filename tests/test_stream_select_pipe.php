<?php

/**
 * Quick test: does stream_select() work on Windows proc_open pipes?
 *
 * This spawns a simple PHP child that echoes "hello\n" after 100ms,
 * then we poll with stream_select() to see if it correctly reports
 * readability.
 */

$descriptors = [
    0 => ["pipe", "r"], // stdin
    1 => ["pipe", "w"], // stdout
    2 => ["pipe", "w"], // stderr
];

// Child script: wait 100ms then print hello
$childCode = 'usleep(100000); echo "hello\n"; fflush(STDOUT);';
$command = [PHP_BINARY, "-r", $childCode];

$process = proc_open($command, $descriptors, $pipes);
if (!is_resource($process)) {
    echo "FATAL: proc_open failed\n";
    exit(1);
}

echo "proc_open OK\n";
echo "PHP_OS: " . PHP_OS . "\n";
echo "PHP_VERSION: " . PHP_VERSION . "\n";
echo "\n";

// ── Test 1: stream_set_blocking ──────────────────────────────────────
echo "=== Test 1: stream_set_blocking(false) ===\n";
$nbResult = @stream_set_blocking($pipes[1], false);
echo "stream_set_blocking(false) returned: " . ($nbResult ? "true" : "false") . "\n";

$meta = stream_get_meta_data($pipes[1]);
echo "stream_get_meta_data blocked: " . ($meta["blocked"] ? "true" : "false") . "\n";
echo "\n";

// Restore blocking for the next tests
stream_set_blocking($pipes[1], true);

// ── Test 2: stream_select with 0 timeout (immediate) ────────────────
echo "=== Test 2: stream_select(0,0) immediately (child hasn't sent yet) ===\n";
$read = [$pipes[1]];
$write = null;
$except = null;
$ready = @stream_select($read, $write, $except, 0, 0);
echo "stream_select returned: " . var_export($ready, true) . "\n";
echo "Expected: 0 (no data yet)\n";
echo "\n";

// ── Test 3: stream_select with 1s timeout (child sends at ~100ms) ───
echo "=== Test 3: stream_select(1,0) - wait up to 1s for child ===\n";
$read = [$pipes[1]];
$write = null;
$except = null;
$t0 = microtime(true);
$ready = @stream_select($read, $write, $except, 1, 0);
$elapsed = round((microtime(true) - $t0) * 1000);
echo "stream_select returned: " . var_export($ready, true) . " (after {$elapsed}ms)\n";
echo "Expected: 1 (data available after ~100ms)\n";
echo "\n";

// ── Test 4: fread after stream_select says data is available ────────
if ($ready > 0) {
    echo "=== Test 4: fread() after stream_select says ready ===\n";
    $data = fread($pipes[1], 65536);
    echo "fread returned: " . json_encode($data) . "\n";
    echo "Expected: \"hello\\n\"\n";
    echo "\n";
}

// ── Test 5: stream_select on empty pipe after data consumed ─────────
echo "=== Test 5: stream_select(0,0) after data consumed ===\n";
$read = [$pipes[1]];
$write = null;
$except = null;
$ready = @stream_select($read, $write, $except, 0, 0);
echo "stream_select returned: " . var_export($ready, true) . "\n";
echo "Expected: 0 (no more data)\n";
echo "\n";

// ── Cleanup ──────────────────────────────────────────────────────────
@fclose($pipes[0]);
@fclose($pipes[1]);
@fclose($pipes[2]);
@proc_close($process);

// ═══════════════════════════════════════════════════════════════════════
// Test with worker_loop.php
// ═══════════════════════════════════════════════════════════════════════
echo "=== Test 6: stream_select on worker_loop.php pipes ===\n";

$workerScript = dirname(__DIR__) . DIRECTORY_SEPARATOR . "src" .
    DIRECTORY_SEPARATOR . "vosaka" . DIRECTORY_SEPARATOR . "foroutines" .
    DIRECTORY_SEPARATOR . "worker_loop.php";

if (!is_file($workerScript)) {
    echo "SKIP: worker_loop.php not found at {$workerScript}\n";
    exit(0);
}

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . "vendor" .
    DIRECTORY_SEPARATOR . "autoload.php";

use Laravel\SerializableClosure\SerializableClosure;

$process2 = proc_open(
    [PHP_BINARY, $workerScript],
    [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]],
    $pipes2
);

if (!is_resource($process2)) {
    echo "FATAL: proc_open for worker_loop.php failed\n";
    exit(1);
}

echo "worker_loop.php spawned\n";

// 6a: Wait for READY using blocking fgets
echo "\n--- 6a: blocking fgets for READY ---\n";
stream_set_timeout($pipes2[1], 5);
$readyLine = fgets($pipes2[1]);
echo "fgets returned: " . json_encode($readyLine) . "\n";
$readyLine = $readyLine !== false ? rtrim($readyLine, "\r\n") : "";
echo "Got READY: " . ($readyLine === "READY" ? "YES" : "NO") . "\n";

if ($readyLine !== "READY") {
    echo "FATAL: Did not get READY\n";
    @fclose($pipes2[0]); @fclose($pipes2[1]); @fclose($pipes2[2]);
    @proc_terminate($process2); @proc_close($process2);
    exit(1);
}

// 6b: Switch to non-blocking and check stream_select
echo "\n--- 6b: switch to non-blocking, stream_select check ---\n";
$nbOk = @stream_set_blocking($pipes2[1], false);
echo "stream_set_blocking(false) returned: " . ($nbOk ? "true" : "false") . "\n";

$meta = stream_get_meta_data($pipes2[1]);
echo "blocked after set_blocking(false): " . ($meta["blocked"] ? "true" : "false") . "\n";

$read = [$pipes2[1]];
$write = null;
$except = null;
$ready = @stream_select($read, $write, $except, 0, 0);
echo "stream_select(0,0) on idle pipe: " . var_export($ready, true) . "\n";
echo "Expected: 0 (worker idle, no data)\n";

// 6c: Send a task and poll with stream_select
echo "\n--- 6c: send task and poll with stream_select ---\n";

$closure = function () { return 42; };
$serialized = serialize(new SerializableClosure($closure));
$encoded = base64_encode($serialized);
$taskLine = "TASK:" . $encoded . "\n";

// Stdin is blocking — write the task
fwrite($pipes2[0], $taskLine);
fflush($pipes2[0]);
echo "Task sent (" . strlen($taskLine) . " bytes)\n";

// Poll with stream_select for up to 5 seconds
$buffer = "";
$deadline = microtime(true) + 5.0;
$resultLine = null;
$gotReady = false;
$pollCount = 0;
$selectReturns = [];

while (microtime(true) < $deadline) {
    $pollCount++;

    $read = [$pipes2[1]];
    $write = null;
    $except = null;
    $ready = @stream_select($read, $write, $except, 0, 50000); // 50ms timeout

    if ($pollCount <= 5 || ($ready !== false && $ready > 0)) {
        $selectReturns[] = "poll#{$pollCount}: ready={$ready}";
    }

    if ($ready !== false && $ready > 0) {
        $chunk = @fread($pipes2[1], 65536);
        if ($chunk !== false && $chunk !== "") {
            $buffer .= $chunk;
            echo "  poll #{$pollCount}: read " . strlen($chunk) . " bytes\n";
        }
    }

    // Extract complete lines from buffer
    while (($nlPos = strpos($buffer, "\n")) !== false) {
        $line = rtrim(substr($buffer, 0, $nlPos), "\r");
        $buffer = substr($buffer, $nlPos + 1);

        if ($line === "") continue;

        echo "  Line: " . json_encode(substr($line, 0, 80)) .
            (strlen($line) > 80 ? "..." : "") . "\n";

        if (str_starts_with($line, "RESULT:")) {
            $resultLine = $line;
        } elseif ($line === "READY") {
            $gotReady = true;
        }
    }

    if ($resultLine !== null && $gotReady) {
        break;
    }

    // Check if child died
    $status = proc_get_status($process2);
    if (!$status["running"]) {
        echo "  Child died! exit={$status["exitcode"]}\n";
        break;
    }
}

echo "\nstream_select returns log: " . implode(", ", array_slice($selectReturns, 0, 10)) . "\n";
echo "Total polls: {$pollCount}\n";
echo "Got RESULT: " . ($resultLine !== null ? "YES" : "NO") . "\n";
echo "Got READY: " . ($gotReady ? "YES" : "NO") . "\n";

if ($resultLine !== null && str_starts_with($resultLine, "RESULT:")) {
    $payload = substr($resultLine, 7);
    $decoded = base64_decode($payload, true);
    if ($decoded !== false) {
        $result = unserialize($decoded);
        echo "Result value: " . var_export($result, true) . "\n";
        echo "Correct: " . ($result === 42 ? "YES" : "NO") . "\n";
    }
}

// 6d: Shutdown
echo "\n--- 6d: shutdown ---\n";
fwrite($pipes2[0], "SHUTDOWN\n");
fflush($pipes2[0]);

$deadline = microtime(true) + 3.0;
while (microtime(true) < $deadline) {
    $status = proc_get_status($process2);
    if (!$status["running"]) {
        echo "Worker exited (code={$status["exitcode"]})\n";
        break;
    }
    usleep(10000);
}

@fclose($pipes2[0]);
@fclose($pipes2[1]);
@fclose($pipes2[2]);
@proc_close($process2);

echo "\nAll tests complete.\n";
