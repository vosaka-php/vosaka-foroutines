<?php

/**
 * Test: does stream_set_timeout() work on Windows proc_open pipes?
 *
 * This is critical for the WorkerPool polling strategy. If
 * stream_set_timeout works, we can use fgets() with a short timeout
 * as a non-blocking poll. If not, we need a different approach.
 */

echo "PHP_OS: " . PHP_OS . "\n";
echo "PHP_VERSION: " . PHP_VERSION . "\n\n";

$descriptors = [
    0 => ["pipe", "r"], // stdin  – child reads
    1 => ["pipe", "w"], // stdout – child writes
    2 => ["pipe", "w"], // stderr – child writes
];

// ═══════════════════════════════════════════════════════════════════════
// Test 1: stream_set_timeout on a pipe where child sends after delay
// ═══════════════════════════════════════════════════════════════════════

echo "=== Test 1: stream_set_timeout with fgets on empty pipe ===\n";

// Child waits 2 seconds then prints hello
$childCode = 'sleep(2); echo "hello\n"; fflush(STDOUT); sleep(10);';
$process = proc_open([PHP_BINARY, "-r", $childCode], $descriptors, $pipes);

if (!is_resource($process)) {
    echo "FATAL: proc_open failed\n";
    exit(1);
}

echo "Child spawned (will send 'hello' after 2s)\n\n";

// 1a: fgets with 0s timeout (should return immediately)
echo "--- 1a: stream_set_timeout(0, 1000) = 1ms timeout ---\n";
stream_set_timeout($pipes[1], 0, 1000); // 1ms timeout
$t0 = microtime(true);
$line = @fgets($pipes[1]);
$elapsed = round((microtime(true) - $t0) * 1000, 1);
echo "fgets returned: " . json_encode($line) . " (took {$elapsed}ms)\n";
$meta = stream_get_meta_data($pipes[1]);
echo "timed_out: " . ($meta["timed_out"] ? "true" : "false") . "\n";
echo "Expected: false (no data), timed_out=true, elapsed ~1ms\n\n";

// 1b: fgets with 10ms timeout
echo "--- 1b: stream_set_timeout(0, 10000) = 10ms timeout ---\n";
stream_set_timeout($pipes[1], 0, 10000); // 10ms timeout
$t0 = microtime(true);
$line = @fgets($pipes[1]);
$elapsed = round((microtime(true) - $t0) * 1000, 1);
echo "fgets returned: " . json_encode($line) . " (took {$elapsed}ms)\n";
$meta = stream_get_meta_data($pipes[1]);
echo "timed_out: " . ($meta["timed_out"] ? "true" : "false") . "\n";
echo "Expected: false (no data), timed_out=true, elapsed ~10ms\n\n";

// 1c: fgets with 100ms timeout
echo "--- 1c: stream_set_timeout(0, 100000) = 100ms timeout ---\n";
stream_set_timeout($pipes[1], 0, 100000); // 100ms timeout
$t0 = microtime(true);
$line = @fgets($pipes[1]);
$elapsed = round((microtime(true) - $t0) * 1000, 1);
echo "fgets returned: " . json_encode($line) . " (took {$elapsed}ms)\n";
$meta = stream_get_meta_data($pipes[1]);
echo "timed_out: " . ($meta["timed_out"] ? "true" : "false") . "\n";
echo "Expected: false (no data), timed_out=true, elapsed ~100ms\n\n";

// 1d: After timeout, can we still read? Wait for child to send data.
echo "--- 1d: fgets with 3s timeout (child sends at ~2s) ---\n";
stream_set_timeout($pipes[1], 3, 0); // 3s timeout
$t0 = microtime(true);
$line = @fgets($pipes[1]);
$elapsed = round((microtime(true) - $t0) * 1000, 1);
echo "fgets returned: " . json_encode($line) . " (took {$elapsed}ms)\n";
$meta = stream_get_meta_data($pipes[1]);
echo "timed_out: " . ($meta["timed_out"] ? "true" : "false") . "\n";
if ($line !== false) {
    $trimmed = rtrim($line, "\r\n");
    echo "Got line: " . json_encode($trimmed) . "\n";
    echo "Correct: " . ($trimmed === "hello" ? "YES" : "NO") . "\n";
} else {
    echo "Got nothing (timeout or error)\n";
}
echo "Expected: 'hello', timed_out=false, elapsed ~2s\n\n";

// Cleanup test 1
@fclose($pipes[0]);
@fclose($pipes[1]);
@fclose($pipes[2]);
proc_terminate($process);
@proc_close($process);

// ═══════════════════════════════════════════════════════════════════════
// Test 2: Repeated fgets with short timeout as polling loop
// ═══════════════════════════════════════════════════════════════════════

echo "=== Test 2: Polling loop with fgets + stream_set_timeout ===\n";

// Child waits 500ms, sends "line1\n", waits 500ms, sends "line2\n"
$childCode = <<<'PHP'
usleep(500000);
echo "line1\n";
fflush(STDOUT);
usleep(500000);
echo "line2\n";
fflush(STDOUT);
sleep(10);
PHP;

$process2 = proc_open([PHP_BINARY, "-r", $childCode], [
    0 => ["pipe", "r"],
    1 => ["pipe", "w"],
    2 => ["pipe", "w"],
], $pipes2);

if (!is_resource($process2)) {
    echo "FATAL: proc_open failed\n";
    exit(1);
}

echo "Child spawned (sends line1 at ~500ms, line2 at ~1000ms)\n";

// Poll with 50ms timeout
stream_set_timeout($pipes2[1], 0, 50000); // 50ms

$lines = [];
$startTime = microtime(true);
$pollCount = 0;
$deadline = $startTime + 3.0; // max 3s

while (microtime(true) < $deadline && count($lines) < 2) {
    $pollCount++;

    $line = @fgets($pipes2[1]);
    $meta = stream_get_meta_data($pipes2[1]);

    if ($line !== false) {
        $trimmed = rtrim($line, "\r\n");
        $elapsed = round((microtime(true) - $startTime) * 1000);
        echo "  poll #{$pollCount} ({$elapsed}ms): got line: " . json_encode($trimmed) . "\n";
        $lines[] = $trimmed;

        // IMPORTANT: After a timeout, the timed_out flag stays set.
        // We need to reset it by calling stream_set_timeout again.
        stream_set_timeout($pipes2[1], 0, 50000);
    } else {
        if ($meta["timed_out"]) {
            // Reset timeout flag for next poll
            stream_set_timeout($pipes2[1], 0, 50000);
        } elseif ($meta["eof"]) {
            echo "  poll #{$pollCount}: EOF\n";
            break;
        }

        if ($pollCount <= 3 || $pollCount % 10 === 0) {
            $elapsed = round((microtime(true) - $startTime) * 1000);
            echo "  poll #{$pollCount} ({$elapsed}ms): no data (timed_out={$meta['timed_out']}, eof={$meta['eof']})\n";
        }
    }
}

echo "\nTotal polls: {$pollCount}\n";
echo "Lines received: " . count($lines) . "\n";
foreach ($lines as $i => $l) {
    echo "  line[{$i}]: " . json_encode($l) . "\n";
}
echo "Expected: line1, line2\n";
$ok = count($lines) === 2 && $lines[0] === "line1" && $lines[1] === "line2";
echo "PASS: " . ($ok ? "YES" : "NO") . "\n\n";

// Cleanup test 2
@fclose($pipes2[0]);
@fclose($pipes2[1]);
@fclose($pipes2[2]);
proc_terminate($process2);
@proc_close($process2);

// ═══════════════════════════════════════════════════════════════════════
// Test 3: Does timed_out flag persist? Can we read after a timeout?
// ═══════════════════════════════════════════════════════════════════════

echo "=== Test 3: Read after timeout (timed_out flag behavior) ===\n";

// Child sends immediately, we try to read after a previous timeout
$childCode = <<<'PHP'
usleep(200000);
echo "delayed_data\n";
fflush(STDOUT);
sleep(10);
PHP;

$process3 = proc_open([PHP_BINARY, "-r", $childCode], [
    0 => ["pipe", "r"],
    1 => ["pipe", "w"],
    2 => ["pipe", "w"],
], $pipes3);

if (!is_resource($process3)) {
    echo "FATAL: proc_open failed\n";
    exit(1);
}

// First: trigger a timeout
echo "--- Triggering timeout with 10ms fgets ---\n";
stream_set_timeout($pipes3[1], 0, 10000); // 10ms
$t0 = microtime(true);
$line = @fgets($pipes3[1]);
$elapsed = round((microtime(true) - $t0) * 1000, 1);
$meta = stream_get_meta_data($pipes3[1]);
echo "fgets: " . json_encode($line) . " ({$elapsed}ms, timed_out={$meta['timed_out']})\n";

// Now try to read again AFTER the timeout was hit
echo "\n--- Trying fgets again with 1s timeout ---\n";
stream_set_timeout($pipes3[1], 1, 0); // 1s timeout
$t0 = microtime(true);
$line = @fgets($pipes3[1]);
$elapsed = round((microtime(true) - $t0) * 1000, 1);
$meta = stream_get_meta_data($pipes3[1]);
echo "fgets: " . json_encode($line) . " ({$elapsed}ms, timed_out={$meta['timed_out']})\n";

if ($line !== false) {
    $trimmed = rtrim($line, "\r\n");
    echo "Got: " . json_encode($trimmed) . "\n";
    echo "Correct: " . ($trimmed === "delayed_data" ? "YES" : "NO") . "\n";
} else {
    echo "Got nothing — stream may be broken after timeout\n";
    echo "This means fgets after timeout does NOT work on this platform\n";
}
echo "\n";

// Cleanup test 3
@fclose($pipes3[0]);
@fclose($pipes3[1]);
@fclose($pipes3[2]);
proc_terminate($process3);
@proc_close($process3);

// ═══════════════════════════════════════════════════════════════════════
// Summary
// ═══════════════════════════════════════════════════════════════════════

echo "=== Summary ===\n";
echo "stream_set_blocking(false) on proc_open pipes: " . ($nbOk ?? false ? "WORKS" : "BROKEN") . "\n";
echo "stream_set_timeout() + fgets(): see results above\n";
echo "stream_select() on proc_open pipes: always returns 1 on Windows (BROKEN)\n";
echo "\nDone.\n";
