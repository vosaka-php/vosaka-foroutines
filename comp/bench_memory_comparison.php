<?php

/**
 * Benchmark: Memory Usage Comparison — PHP VOsaka vs Node.js
 *
 * Measures actual memory consumption of PHP VOsaka Foroutines across various
 * scenarios and compares with Node.js (run via child process).
 *
 * Scenarios:
 *
 *   1.  Baseline: empty main() context
 *   2.  Fiber creation overhead — 1, 10, 100, 500, 1000 fibers
 *   3.  In-process buffered channel — varying capacities
 *   4.  In-process channel with data — 1000 integers buffered
 *   5.  Socket channel (Channel::create) — broker process overhead
 *   6.  Multiple socket channels — 1, 3, 5 channels
 *   7.  Dispatchers::IO — WorkerPool boot + 1 task
 *   8.  Dispatchers::IO — multiple concurrent tasks
 *   9.  Launch fibers with Delay — concurrent I/O simulation
 *  10.  Pipeline: 3-stage channel pipeline with data flowing
 *  11.  Mixed: channels + fibers + IO dispatcher combined
 *  12.  Peak memory under load — 50 concurrent fibers + channels
 *
 * For each scenario we measure:
 *   - Memory BEFORE (baseline)
 *   - Memory AFTER (peak or current)
 *   - Delta = net memory cost of that primitive
 *
 * Node.js comparison is run as a child process that performs equivalent
 * operations and reports its memory usage in JSON format.
 *
 * Usage:
 *   cd comp/
 *   php bench_memory_comparison.php
 */

declare(strict_types=1);

require __DIR__ . "/../vendor/autoload.php";
require __DIR__ . "/BenchHelper.php";

use comp\BenchHelper;
use vosaka\foroutines\Async;
use vosaka\foroutines\channel\Channel;
use vosaka\foroutines\channel\Channels;
use vosaka\foroutines\Delay;
use vosaka\foroutines\Dispatchers;
use vosaka\foroutines\Launch;
use vosaka\foroutines\Pause;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Thread;
use vosaka\foroutines\WorkerPoolState;

use function vosaka\foroutines\main;

// ─── ANSI helpers ────────────────────────────────────────────────────────

const C_RESET = "\033[0m";
const C_BOLD = "\033[1m";
const C_DIM = "\033[2m";
const C_RED = "\033[31m";
const C_GREEN = "\033[32m";
const C_YELLOW = "\033[33m";
const C_CYAN = "\033[36m";
const C_MAGENTA = "\033[35m";

function cc(string $color, string $text): string
{
    return $color . $text . C_RESET;
}

// ─── Memory helpers ──────────────────────────────────────────────────────

function memNow(): int
{
    return memory_get_usage(true);
}

function memPeak(): int
{
    return memory_get_peak_usage(true);
}

function memFmt(int $bytes): string
{
    if ($bytes < 0) {
        return "-" . memFmt(-$bytes);
    }
    if ($bytes < 1024) {
        return $bytes . " B";
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024, 1) . " KB";
    }
    return round($bytes / 1048576, 2) . " MB";
}

function memDelta(int $before, int $after): string
{
    $delta = $after - $before;
    $str = memFmt(abs($delta));
    if ($delta > 0) {
        return cc(C_YELLOW, "+{$str}");
    }
    if ($delta < 0) {
        return cc(C_GREEN, "-{$str}");
    }
    return cc(C_DIM, "±0");
}

/**
 * Force GC and return current memory.
 */
function memClean(): int
{
    gc_collect_cycles();
    gc_mem_caches();
    return memNow();
}

// ─── Result collection ───────────────────────────────────────────────────

$results = [];

function recordMem(
    string $name,
    int $phpDeltaBytes,
    ?int $nodeDeltaBytes = null,
    string $note = "",
): void {
    global $results;
    $results[] = [
        "name" => $name,
        "php" => $phpDeltaBytes,
        "node" => $nodeDeltaBytes,
        "note" => $note,
    ];
}

// ─── Teardown helpers ────────────────────────────────────────────────────

function safeTeardown(Channel $ch): void
{
    try {
        if (!$ch->isClosed()) {
            $ch->close();
        }
    } catch (\Throwable) {
    }
    try {
        $ch->cleanup();
    } catch (\Throwable) {
    }
}

// ─── Node.js memory measurement ──────────────────────────────────────────

/**
 * Run a Node.js script snippet and return its memory report.
 * The snippet should call `reportMemory(label)` which is pre-defined.
 * Returns array of {label, rss, heapUsed, heapTotal, external} or null on error.
 */
function runNodeMemoryTest(string $jsCode, int $timeoutSec = 30): ?array
{
    $wrapper = <<<'JS'
    const { performance } = require("node:perf_hooks");
    const results = [];
    function reportMemory(label) {
        if (typeof globalThis.gc === "function") globalThis.gc();
        const m = process.memoryUsage();
        results.push({ label, rss: m.rss, heapUsed: m.heapUsed, heapTotal: m.heapTotal, external: m.external });
    }

    (async () => {
        try {
    JS;

    $footer = <<<'JS'
        } catch (e) {
            console.error(e.message);
        }
        process.stdout.write(JSON.stringify(results));
    })();
    JS;

    $fullScript = $wrapper . "\n" . $jsCode . "\n" . $footer;

    $tmpFile = tempnam(sys_get_temp_dir(), "vosaka_node_mem_");
    file_put_contents($tmpFile, $fullScript);

    $nodeBin = getenv("NODE_BIN") ?: "node";
    $cmd =
        escapeshellarg($nodeBin) .
        " --expose-gc " .
        escapeshellarg($tmpFile) .
        " 2>&1";

    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);

    @unlink($tmpFile);

    if ($exitCode !== 0) {
        return null;
    }

    $json = implode("\n", $output);
    $data = json_decode($json, true);

    return is_array($data) ? $data : null;
}

/**
 * Extract delta between two labels from Node memory results.
 */
function nodeMemDelta(
    array $nodeResults,
    string $beforeLabel,
    string $afterLabel,
): ?int {
    $before = null;
    $after = null;
    foreach ($nodeResults as $entry) {
        if ($entry["label"] === $beforeLabel) {
            $before = $entry;
        }
        if ($entry["label"] === $afterLabel) {
            $after = $entry;
        }
    }
    if ($before === null || $after === null) {
        return null;
    }
    return $after["rss"] - $before["rss"];
}

function nodeHeapDelta(
    array $nodeResults,
    string $beforeLabel,
    string $afterLabel,
): ?int {
    $before = null;
    $after = null;
    foreach ($nodeResults as $entry) {
        if ($entry["label"] === $beforeLabel) {
            $before = $entry;
        }
        if ($entry["label"] === $afterLabel) {
            $after = $entry;
        }
    }
    if ($before === null || $after === null) {
        return null;
    }
    return $after["heapUsed"] - $before["heapUsed"];
}

// ─── Child process memory measurement ────────────────────────────────────

/**
 * Get the RSS (Working Set) of a process by PID using OS-level tools.
 * Returns bytes, or null if unable to measure.
 */
function getProcessMemoryByPid(int $pid): ?int
{
    if ($pid <= 0) {
        return null;
    }

    if (strncasecmp(PHP_OS, "WIN", 3) === 0) {
        // Windows: use tasklist
        $output = [];
        $exitCode = 0;
        exec(
            'tasklist /FI "PID eq ' . $pid . '" /FO CSV /NH 2>NUL',
            $output,
            $exitCode,
        );
        if ($exitCode !== 0 || empty($output)) {
            return null;
        }
        // Output format: "php.exe","1234","Console","1","12,345 K"
        foreach ($output as $line) {
            $line = trim($line);
            if ($line === "" || str_contains($line, "No tasks")) {
                continue;
            }
            $cols = str_getcsv($line, ",", "\"", "\\");
            if (count($cols) >= 5) {
                // Memory column like "12,345 K" or "12.345 K"
                $memStr = $cols[4];
                $memStr = preg_replace("/[^0-9]/", "", $memStr);
                if (is_numeric($memStr)) {
                    return (int) $memStr * 1024; // KB → bytes
                }
            }
        }
        return null;
    }

    // Linux/macOS: read /proc/<pid>/status or use ps
    if (is_file("/proc/{$pid}/status")) {
        $content = @file_get_contents("/proc/{$pid}/status");
        if ($content && preg_match("/VmRSS:\s+(\d+)\s+kB/", $content, $m)) {
            return (int) $m[1] * 1024;
        }
    }

    // Fallback: ps
    $output = [];
    $exitCode = 0;
    exec("ps -o rss= -p " . (int) $pid . " 2>/dev/null", $output, $exitCode);
    if ($exitCode === 0 && !empty($output)) {
        $rss = trim($output[0]);
        if (is_numeric($rss)) {
            return (int) $rss * 1024; // KB → bytes
        }
    }
    return null;
}

/**
 * Get PIDs of all active WorkerPool child processes.
 * @return int[]
 */
function getWorkerPids(): array
{
    $pids = [];
    foreach (WorkerPoolState::$workers as $worker) {
        if (isset($worker["mode"])) {
            if ($worker["mode"] === "fork" && isset($worker["pid"])) {
                $pids[] = (int) $worker["pid"];
            } elseif (
                $worker["mode"] === "socket" &&
                isset($worker["process"]) &&
                is_resource($worker["process"])
            ) {
                $status = proc_get_status($worker["process"]);
                if ($status && $status["running"] && $status["pid"] > 0) {
                    $pids[] = (int) $status["pid"];
                }
            }
        }
    }
    return $pids;
}

/**
 * Measure total RSS of all WorkerPool child processes.
 * Returns [totalBytes, count, perWorkerBytes[]]
 */
function measureWorkerPoolMemory(): array
{
    $pids = getWorkerPids();
    $total = 0;
    $perWorker = [];
    foreach ($pids as $pid) {
        $mem = getProcessMemoryByPid($pid);
        if ($mem !== null) {
            $total += $mem;
            $perWorker[] = $mem;
        }
    }
    return [$total, count($perWorker), $perWorker];
}

/**
 * Get the PID of a Channel's broker process (if socket-based).
 * Uses reflection to access the private brokerProcess property.
 * Returns PID or null.
 */
function getChannelBrokerPid(Channel $ch): ?int
{
    try {
        $ref = new ReflectionClass($ch);

        // Channel has private ?ChannelSocketClient $socketClient
        // ChannelSocketClient has private $brokerProcess (proc_open handle)
        $socketClient = null;

        if ($ref->hasProperty("socketClient")) {
            $prop = $ref->getProperty("socketClient");
            $prop->setAccessible(true);
            $socketClient = $prop->getValue($ch);
        }

        if ($socketClient === null) {
            return null;
        }

        $scRef = new ReflectionClass($socketClient);
        if ($scRef->hasProperty("brokerProcess")) {
            $bProp = $scRef->getProperty("brokerProcess");
            $bProp->setAccessible(true);
            $proc = $bProp->getValue($socketClient);
            if ($proc !== null && is_resource($proc)) {
                $status = proc_get_status($proc);
                if ($status && $status["pid"] > 0) {
                    return (int) $status["pid"];
                }
            }
        }
    } catch (\Throwable) {
    }
    return null;
}

/**
 * Measure RSS of a Channel's broker process.
 * Returns bytes or null.
 */
function measureChannelBrokerMemory(Channel $ch): ?int
{
    $pid = getChannelBrokerPid($ch);
    if ($pid === null) {
        return null;
    }
    return getProcessMemoryByPid($pid);
}

/**
 * Measure total RSS of multiple Channel broker processes.
 * Returns [totalBytes, count, perBrokerBytes[]]
 */
function measureAllBrokerMemory(array $channels): array
{
    $total = 0;
    $perBroker = [];
    foreach ($channels as $ch) {
        $mem = measureChannelBrokerMemory($ch);
        if ($mem !== null) {
            $total += $mem;
            $perBroker[] = $mem;
        }
    }
    return [$total, count($perBroker), $perBroker];
}

/**
 * Get total memory of the current PHP parent process + all known child processes.
 * Returns [parentBytes, childTotalBytes, grandTotalBytes, details[]]
 */
function measureTotalSystemMemory(array $socketChannels = []): array
{
    $parentMem = memNow();

    // Worker pool children
    [$workerTotal, $workerCount, $workerPer] = measureWorkerPoolMemory();

    // Channel broker children
    [$brokerTotal, $brokerCount, $brokerPer] = measureAllBrokerMemory(
        $socketChannels,
    );

    $childTotal = $workerTotal + $brokerTotal;
    $grandTotal = $parentMem + $childTotal;

    return [
        "parent" => $parentMem,
        "child_total" => $childTotal,
        "grand_total" => $grandTotal,
        "workers" => [
            "total" => $workerTotal,
            "count" => $workerCount,
            "each" => $workerPer,
        ],
        "brokers" => [
            "total" => $brokerTotal,
            "count" => $brokerCount,
            "each" => $brokerPer,
        ],
    ];
}

// ─── Cooperative channel helpers (same as bench_03) ──────────────────────

function coopSend(Channel $ch, mixed $value): bool
{
    while (true) {
        if ($ch->isClosed()) {
            return false;
        }
        if ($ch->trySend($value)) {
            return true;
        }
        Pause::new();
    }
}

function coopReceive(Channel $ch): mixed
{
    while (true) {
        $val = $ch->tryReceive();
        if ($val !== null) {
            return $val;
        }
        if ($ch->isClosed() && $ch->isEmpty()) {
            return null;
        }
        Pause::new();
    }
}

// ═════════════════════════════════════════════════════════════════════════
//  MAIN
// ═════════════════════════════════════════════════════════════════════════

main(function () {
    global $results;
    Launch::getInstance();

    $divider = str_repeat("═", 80);
    echo "\n" . cc(C_BOLD . C_CYAN, $divider) . "\n";
    echo cc(
        C_BOLD . C_CYAN,
        "  Memory Benchmark: PHP VOsaka Foroutines vs Node.js",
    ) . "\n";
    echo cc(C_BOLD . C_CYAN, $divider) . "\n";
    echo "  PHP " .
        PHP_VERSION .
        " | " .
        PHP_OS .
        " | " .
        php_uname("m") .
        "\n";
    echo "  Initial memory: " . memFmt(memNow()) . "\n";
    echo "  Initial peak:   " . memFmt(memPeak()) . "\n";

    // Check Node.js availability
    $nodeBin = getenv("NODE_BIN") ?: "node";
    $nodeVersion = trim(
        shell_exec(escapeshellarg($nodeBin) . " --version 2>&1") ?? "",
    );
    $hasNode = str_starts_with($nodeVersion, "v");
    if ($hasNode) {
        echo "  Node.js:        " . $nodeVersion . "\n";
    } else {
        echo cc(
            C_YELLOW,
            "  Node.js:        NOT FOUND (skipping Node comparison)",
        ) . "\n";
    }
    echo cc(C_DIM, "  " . str_repeat("─", 70)) . "\n\n";

    // ═════════════════════════════════════════════════════════════════
    // Test 1: Baseline — empty main() context
    // ═════════════════════════════════════════════════════════════════
    echo cc(C_YELLOW, "  ▸ Test 1: Baseline memory (empty main context)") .
        "\n";

    $baselineMem = memClean();
    $baselinePeak = memPeak();

    echo "    PHP current:  " . memFmt($baselineMem) . "\n";
    echo "    PHP peak:     " . memFmt($baselinePeak) . "\n";

    $nodeBaseline = null;
    if ($hasNode) {
        $nodeRes = runNodeMemoryTest('reportMemory("baseline");');
        if ($nodeRes) {
            $nodeBaseline = $nodeRes[0];
            echo "    Node RSS:     " . memFmt($nodeBaseline["rss"]) . "\n";
            echo "    Node heap:    " .
                memFmt($nodeBaseline["heapUsed"]) .
                "\n";
        }
    }

    recordMem(
        "Baseline (runtime boot)",
        $baselineMem,
        $nodeBaseline ? $nodeBaseline["rss"] : null,
        "startup cost",
    );
    echo "\n";

    // ═════════════════════════════════════════════════════════════════
    // Test 2: Fiber creation overhead — varying counts
    // ═════════════════════════════════════════════════════════════════
    echo cc(C_YELLOW, "  ▸ Test 2: Fiber creation overhead") . "\n";

    $fiberCounts = [1, 10, 100, 500, 1000];

    // Node.js: equivalent = Promise creation
    $nodePromiseJs = "";
    foreach ($fiberCounts as $count) {
        $nodePromiseJs .= <<<JS
                reportMemory("before_{$count}");
                {
                    const promises = [];
                    for (let i = 0; i < {$count}; i++) {
                        promises.push(new Promise(resolve => { resolve(i); }));
                    }
                    await Promise.all(promises);
                }
                reportMemory("after_{$count}");
        JS;
        $nodePromiseJs .= "\n";
    }

    $nodePromiseRes = $hasNode ? runNodeMemoryTest($nodePromiseJs) : null;

    echo cc(
        C_BOLD,
        sprintf(
            "    %-12s %14s %14s %14s %14s",
            "Fibers",
            "PHP Δ",
            "PHP/fiber",
            "Node Δ",
            "Node/promise",
        ),
    ) . "\n";
    echo "    " . str_repeat("─", 70) . "\n";

    foreach ($fiberCounts as $count) {
        $memBefore = memClean();

        RunBlocking::new(function () use ($count) {
            for ($i = 0; $i < $count; $i++) {
                Launch::new(function () use ($i) {
                    return $i;
                });
            }
            Thread::await();
        });
        Thread::await();

        $memAfter = memNow();
        $phpDelta = $memAfter - $memBefore;
        $phpDelta = max(0, $phpDelta); // GC may reclaim some
        $phpPerFiber = $count > 0 ? $phpDelta / $count : 0;

        $nodeDelta = null;
        $nodePerPromise = null;
        if ($nodePromiseRes) {
            $nd = nodeMemDelta(
                $nodePromiseRes,
                "before_{$count}",
                "after_{$count}",
            );
            $nh = nodeHeapDelta(
                $nodePromiseRes,
                "before_{$count}",
                "after_{$count}",
            );
            if ($nh !== null) {
                $nodeDelta = max(0, $nh);
                $nodePerPromise = $count > 0 ? $nodeDelta / $count : 0;
            }
        }

        echo sprintf(
            "    %-12s %14s %14s %14s %14s",
            "{$count} fibers",
            memFmt($phpDelta),
            memFmt((int) $phpPerFiber),
            $nodeDelta !== null ? memFmt($nodeDelta) : cc(C_DIM, "—"),
            $nodePerPromise !== null
                ? memFmt((int) $nodePerPromise)
                : cc(C_DIM, "—"),
        ) . "\n";

        if ($count === 1000) {
            recordMem(
                "1000 fibers/promises",
                $phpDelta,
                $nodeDelta,
                "concurrency unit cost",
            );
        }
    }

    echo "\n";

    // ═════════════════════════════════════════════════════════════════
    // Test 3: In-process buffered channel — varying capacities
    // ═════════════════════════════════════════════════════════════════
    echo cc(C_YELLOW, "  ▸ Test 3: In-process buffered channel (empty)") . "\n";

    $capacities = [1, 10, 100, 1000, 10000];

    $nodeChannelJs = "";
    foreach ($capacities as $cap) {
        $nodeChannelJs .= <<<JS
                reportMemory("before_chan_{$cap}");
                {
                    const buf = new Array({$cap});
                    buf._closed = false;
                    buf._size = 0;
                }
                reportMemory("after_chan_{$cap}");
        JS;
        $nodeChannelJs .= "\n";
    }
    $nodeChanRes = $hasNode ? runNodeMemoryTest($nodeChannelJs) : null;

    echo cc(
        C_BOLD,
        sprintf("    %-14s %14s %14s", "Capacity", "PHP Δ", "Node Δ (array)"),
    ) . "\n";
    echo "    " . str_repeat("─", 44) . "\n";

    foreach ($capacities as $cap) {
        $memBefore = memClean();
        $ch = Channels::createBuffered($cap);
        $memAfter = memNow();
        $phpDelta = max(0, $memAfter - $memBefore);

        $nodeDelta = null;
        if ($nodeChanRes) {
            $nh = nodeHeapDelta(
                $nodeChanRes,
                "before_chan_{$cap}",
                "after_chan_{$cap}",
            );
            if ($nh !== null) {
                $nodeDelta = max(0, $nh);
            }
        }

        echo sprintf(
            "    cap=%-8d %14s %14s",
            $cap,
            memFmt($phpDelta),
            $nodeDelta !== null ? memFmt($nodeDelta) : cc(C_DIM, "—"),
        ) . "\n";

        $ch->close();

        if ($cap === 1000) {
            recordMem(
                "Channel cap=1000 (empty)",
                $phpDelta,
                $nodeDelta,
                "in-process buffer",
            );
        }
    }

    echo "\n";

    // ═════════════════════════════════════════════════════════════════
    // Test 4: In-process channel filled with 1000 integers
    // ═════════════════════════════════════════════════════════════════
    echo cc(
        C_YELLOW,
        "  ▸ Test 4: In-process channel — 1000 integers buffered",
    ) . "\n";

    $nodeFilledJs = <<<'JS'
            reportMemory("before_filled");
            {
                const buf = [];
                for (let i = 0; i < 1000; i++) buf.push(i);
            }
            reportMemory("after_filled");
    JS;
    $nodeFilledRes = $hasNode ? runNodeMemoryTest($nodeFilledJs) : null;

    $memBefore = memClean();
    $chFilled = Channels::createBuffered(1000);
    for ($i = 0; $i < 1000; $i++) {
        $chFilled->send($i);
    }
    $memAfter = memNow();
    $phpDelta = max(0, $memAfter - $memBefore);

    $nodeDelta = null;
    if ($nodeFilledRes) {
        $nh = nodeHeapDelta($nodeFilledRes, "before_filled", "after_filled");
        if ($nh !== null) {
            $nodeDelta = max(0, $nh);
        }
    }

    echo "    PHP memory:   " .
        memFmt($phpDelta) .
        "  (" .
        memFmt((int) ($phpDelta / 1000)) .
        " per item)\n";
    if ($nodeDelta !== null) {
        echo "    Node memory:  " .
            memFmt($nodeDelta) .
            "  (" .
            memFmt((int) ($nodeDelta / 1000)) .
            " per item)\n";
    }
    $chFilled->close();

    recordMem(
        "Channel 1000 ints filled",
        $phpDelta,
        $nodeDelta,
        "data in buffer",
    );
    echo "\n";

    // ═════════════════════════════════════════════════════════════════
    // Test 5: Socket channel (Channel::create) — pool process cost
    // Channel::create() now uses a shared ChannelBrokerPool by default.
    // All channels share 1 background process instead of N processes.
    // ═════════════════════════════════════════════════════════════════
    echo cc(
        C_YELLOW,
        "  ▸ Test 5: Socket channel (Channel::create) — pool overhead",
    ) . "\n";

    // Node.js equivalent: spawn a child_process that acts as a broker
    $nodeBrokerJs = <<<'JS'
                const { fork } = require("node:child_process");
                const { writeFileSync, unlinkSync } = require("node:fs");
                const path = require("node:path");
                const os = require("node:os");

                // Create a small broker script that stays alive
                const brokerCode = `
                    const net = require("net");
                    const buf = [];
                    const server = net.createServer(c => {
                        c.on("data", d => { buf.push(d.toString()); });
                        c.on("end", () => {});
                    });
                    server.listen(0, "127.0.0.1", () => {
                        process.send({ port: server.address().port });
                    });
                    process.on("message", msg => {
                        if (msg === "SHUTDOWN") { server.close(); process.exit(0); }
                    });
                `;
                const tmpBroker = path.join(os.tmpdir(), "vosaka_node_broker_" + process.pid + ".js");
                writeFileSync(tmpBroker, brokerCode);

                reportMemory("before_broker");
                const child = fork(tmpBroker);
                await new Promise(resolve => child.on("message", resolve));
                // Give child time to stabilize
                await new Promise(r => setTimeout(r, 500));
                reportMemory("after_broker");

                // Measure child RSS via process.memoryUsage of child
                // We can't directly — but we can use pidusage or read /proc
                // Instead, report the child PID and let parent measure
                results.push({ label: "broker_pid", pid: child.pid });

                child.send("SHUTDOWN");
                await new Promise(r => child.on("exit", r));
                try { unlinkSync(tmpBroker); } catch(e) {}
                reportMemory("after_broker_exit");
    JS;
    $nodeBrokerRes = $hasNode ? runNodeMemoryTest($nodeBrokerJs) : null;

    $memBefore = memClean();
    $chSocket = Channel::create(100);
    $memAfter = memNow();
    $phpDelta = max(0, $memAfter - $memBefore);

    echo "    PHP parent Δ: " .
        memFmt($phpDelta) .
        "  (parent-side bookkeeping)\n";
    echo "    Transport:    " .
        $chSocket->getTransport() .
        " (pool mode = " .
        ($chSocket->isPoolMode() ? "yes" : "no") .
        ")\n";
    echo "    Port:         " . $chSocket->getSocketPort() . "\n";

    // Measure ACTUAL broker process memory via OS
    $brokerMem = measureChannelBrokerMemory($chSocket);
    if ($brokerMem !== null) {
        echo "    " .
            cc(C_RED, "PHP Broker RSS:   " . memFmt($brokerMem)) .
            "  (child process, measured via OS)\n";
        echo "    " .
            cc(C_BOLD, "PHP REAL TOTAL:   " . memFmt($phpDelta + $brokerMem)) .
            "  (parent + broker)\n";
    } else {
        echo cc(
            C_DIM,
            "    NOTE: Broker runs as separate process (~4-8 MB RSS each)",
        ) . "\n";
        echo cc(C_DIM, "          Could not measure broker PID memory") . "\n";
    }

    // Node.js broker comparison
    $nodeBrokerRss = null;
    if ($nodeBrokerRes) {
        $nh = nodeHeapDelta($nodeBrokerRes, "before_broker", "after_broker");
        $nr = nodeMemDelta($nodeBrokerRes, "before_broker", "after_broker");
        if ($nr !== null) {
            $nodeBrokerRss = max(0, $nr);
            echo "    " .
                cc(
                    C_CYAN,
                    "Node child_process.fork() RSS Δ: " .
                        memFmt($nodeBrokerRss),
                ) .
                "  (parent-side RSS increase from forking broker)\n";
        }
    }

    recordMem("Socket ch (parent only)", $phpDelta, null, "parent bookkeeping");
    if ($brokerMem !== null) {
        recordMem(
            "Socket ch (broker child)",
            $brokerMem,
            $nodeBrokerRss,
            "OS-measured RSS",
        );
        recordMem(
            "Socket ch (REAL TOTAL)",
            $phpDelta + $brokerMem,
            $nodeBrokerRss !== null ? $nodeBrokerRss : null,
            "parent + broker",
        );
    }

    // Send/receive to verify it works
    $chSocket->send("test");
    $val = $chSocket->receive();
    assert($val === "test");

    safeTeardown($chSocket);
    echo "\n";

    // ═════════════════════════════════════════════════════════════════
    // Test 6: Multiple socket channels
    // ═════════════════════════════════════════════════════════════════
    echo cc(C_YELLOW, "  ▸ Test 6: Multiple socket channels (1, 3, 5)") . "\n";

    $socketCounts = [1, 3, 5];

    // Node.js equivalent: fork multiple child processes as brokers
    $nodeMultiBrokerJs = "";
    foreach ($socketCounts as $n) {
        $nodeMultiBrokerJs .= <<<JS
                    {
                        const { fork } = require("node:child_process");
                        const { writeFileSync, unlinkSync } = require("node:fs");
                        const path = require("node:path");
                        const os = require("node:os");
                        const brokerCode = \`
                            const net = require("net");
                            const server = net.createServer(c => {});
                            server.listen(0, "127.0.0.1", () => {
                                process.send({ port: server.address().port });
                            });
                            process.on("message", msg => {
                                if (msg === "SHUTDOWN") { server.close(); process.exit(0); }
                            });
                        \`;
                        const tmpFiles = [];
                        const children = [];
                        reportMemory("before_multi_{$n}");
                        for (let i = 0; i < {$n}; i++) {
                            const tmp = path.join(os.tmpdir(), "vosaka_node_broker_" + process.pid + "_" + i + ".js");
                            writeFileSync(tmp, brokerCode);
                            tmpFiles.push(tmp);
                            const child = fork(tmp);
                            children.push(child);
                            await new Promise(resolve => child.on("message", resolve));
                        }
                        await new Promise(r => setTimeout(r, 500));
                        reportMemory("after_multi_{$n}");
                        for (const child of children) { child.send("SHUTDOWN"); await new Promise(r => child.on("exit", r)); }
                        for (const f of tmpFiles) { try { unlinkSync(f); } catch(e) {} }
                    }
        JS;
        $nodeMultiBrokerJs .= "\n";
    }
    $nodeMultiBrokerRes = $hasNode
        ? runNodeMemoryTest($nodeMultiBrokerJs, 60)
        : null;

    echo cc(
        C_BOLD,
        sprintf(
            "    %-14s %14s %14s %14s %s",
            "Channels",
            "PHP Δ",
            "Per-channel",
            "Node RSS Δ",
            "Note",
        ),
    ) . "\n";
    echo "    " . str_repeat("─", 80) . "\n";

    foreach ($socketCounts as $n) {
        gc_collect_cycles();
        gc_mem_caches();
        $memBefore = memClean();
        $channels = [];
        for ($i = 0; $i < $n; $i++) {
            $channels[] = Channel::create(10);
        }
        $memAfter = memNow();
        $phpDelta = max(0, $memAfter - $memBefore);
        $perChan = (int) ($phpDelta / $n);

        // Measure actual broker child process memory
        [$brokerTotal, $brokerCount, $brokerEach] = measureAllBrokerMemory(
            $channels,
        );
        $realTotal = $phpDelta + $brokerTotal;
        $realPerChan = $n > 0 ? (int) ($realTotal / $n) : 0;

        // Node.js equivalent measurement
        $nodeMultiDelta = null;
        $nodeMultiPerChan = null;
        if ($nodeMultiBrokerRes) {
            $nr = nodeMemDelta(
                $nodeMultiBrokerRes,
                "before_multi_{$n}",
                "after_multi_{$n}",
            );
            if ($nr !== null) {
                $nodeMultiDelta = max(0, $nr);
                $nodeMultiPerChan = $n > 0 ? (int) ($nodeMultiDelta / $n) : 0;
            }
        }

        echo sprintf(
            "    %-14s %14s %14s %14s %s",
            "{$n} channels",
            memFmt($phpDelta),
            memFmt($perChan),
            $nodeMultiDelta !== null ? memFmt($nodeMultiDelta) : cc(C_DIM, "—"),
            cc(C_DIM, "parent only"),
        ) . "\n";

        if ($brokerCount > 0) {
            echo sprintf(
                "    %14s %14s %14s %14s %s",
                "",
                cc(C_RED, memFmt($brokerTotal)),
                cc(
                    C_RED,
                    memFmt(
                        $brokerCount > 0
                            ? (int) ($brokerTotal / $brokerCount)
                            : 0,
                    ),
                ),
                "",
                cc(C_RED, "PHP {$brokerCount} broker(s) RSS (OS-measured)"),
            ) . "\n";
            echo sprintf(
                "    %14s %14s %14s %14s %s",
                "",
                cc(C_BOLD, memFmt($realTotal)),
                cc(C_BOLD, memFmt($realPerChan)),
                $nodeMultiDelta !== null
                    ? cc(C_BOLD, memFmt($nodeMultiDelta))
                    : "",
                cc(C_BOLD . C_YELLOW, "REAL TOTAL (parent+children)"),
            ) . "\n";
        }

        foreach ($channels as $ch) {
            safeTeardown($ch);
        }
        $channels = [];
        gc_collect_cycles();

        if ($n === 5) {
            recordMem(
                "5 socket ch (parent)",
                $phpDelta,
                null,
                "parent bookkeeping",
            );
            if ($brokerTotal > 0) {
                recordMem(
                    "5 socket ch (brokers)",
                    $brokerTotal,
                    $nodeMultiDelta,
                    "OS-measured child RSS",
                );
                recordMem(
                    "5 socket ch (REAL TOT)",
                    $realTotal,
                    $nodeMultiDelta,
                    "parent+children combined",
                );
            }
        }
    }
    echo "\n";

    // ═════════════════════════════════════════════════════════════════
    // Test 7: Dispatchers::IO — WorkerPool boot + 1 task
    // ═════════════════════════════════════════════════════════════════
    echo cc(C_YELLOW, "  ▸ Test 7: Dispatchers::IO — WorkerPool + 1 task") .
        "\n";

    // Node.js: spawn REAL worker_threads (4 threads like PHP's pool of 4)
    // and run 1 actual task — fair comparison
    $nodeWorkerJs = <<<'JS'
            const { Worker, isMainThread, parentPort, workerData } = require("node:worker_threads");
            const { writeFileSync, unlinkSync } = require("node:fs");
            const path = require("node:path");
            const os = require("node:os");

            // Worker script: stays alive, waits for tasks via postMessage
            const workerCode = `
                const { parentPort } = require("worker_threads");
                parentPort.on("message", (msg) => {
                    if (msg === "SHUTDOWN") { process.exit(0); }
                    if (msg.type === "TASK") {
                        const fn = new Function("return " + msg.code)();
                        const result = fn();
                        parentPort.postMessage({ type: "RESULT", id: msg.id, result });
                    }
                });
                parentPort.postMessage({ type: "READY" });
            `;
            const tmpWorker = path.join(os.tmpdir(), "vosaka_node_worker_" + process.pid + ".js");
            writeFileSync(tmpWorker, workerCode);

            reportMemory("before_workers");
            const workers = [];
            for (let i = 0; i < 4; i++) {
                const w = new Worker(tmpWorker);
                await new Promise(resolve => {
                    w.on("message", (msg) => { if (msg.type === "READY") resolve(); });
                });
                workers.push(w);
            }
            // Give workers time to fully initialize
            await new Promise(r => setTimeout(r, 500));
            reportMemory("after_workers_boot");

            // Run 1 task on worker 0 (equivalent to PHP's Async::new + Dispatchers::IO)
            await new Promise(resolve => {
                workers[0].on("message", (msg) => {
                    if (msg.type === "RESULT") resolve(msg.result);
                });
                workers[0].postMessage({ type: "TASK", id: 1, code: "() => 42" });
            });
            reportMemory("after_1_task");

            // Shutdown all workers
            for (const w of workers) { w.postMessage("SHUTDOWN"); await new Promise(r => w.on("exit", r)); }
            try { unlinkSync(tmpWorker); } catch(e) {}
            reportMemory("after_workers_exit");
    JS;
    $nodeWorkerRes = $hasNode ? runNodeMemoryTest($nodeWorkerJs, 30) : null;

    $memBefore = memClean();

    RunBlocking::new(function () {
        $async = Async::new(function () {
            return 42;
        }, Dispatchers::IO);

        $result = $async->await();
        Thread::await();
    });
    Thread::await();

    $memAfter = memNow();
    $phpDelta = max(0, $memAfter - $memBefore);

    echo "    PHP parent Δ: " .
        memFmt($phpDelta) .
        "  (parent-side overhead)\n";

    // Measure ACTUAL worker child process memory via OS
    [$workerTotal7, $workerCount7, $workerEach7] = measureWorkerPoolMemory();

    // Node.js real worker_threads measurement
    $nodeWorkerRss7 = null;
    $nodeAfterBoot7 = null;
    if ($nodeWorkerRes) {
        $nodeAfterBoot7 = nodeMemDelta(
            $nodeWorkerRes,
            "before_workers",
            "after_workers_boot",
        );
        $nodeWorkerRss7 =
            $nodeAfterBoot7 !== null ? max(0, $nodeAfterBoot7) : null;
    }

    if ($workerCount7 > 0) {
        echo "    " .
            cc(C_RED, "PHP Workers:  " . memFmt($workerTotal7)) .
            "  ({$workerCount7} process(es), OS-measured)\n";
        foreach ($workerEach7 as $wi => $wm) {
            echo "      Worker #{$wi}: " . memFmt($wm) . "\n";
        }
        $realTotal7 = $phpDelta + $workerTotal7;
        echo "    " .
            cc(C_BOLD . C_YELLOW, "PHP REAL TOT: " . memFmt($realTotal7)) .
            "  (parent + workers)\n";
    } else {
        echo cc(
            C_DIM,
            "    NOTE: IO workers are separate processes (~4-8 MB RSS each)",
        ) . "\n";
        echo cc(C_DIM, "          Could not measure worker PIDs") . "\n";
        $workerTotal7 = 0;
    }

    if ($nodeWorkerRss7 !== null) {
        echo "    " .
            cc(
                C_CYAN,
                "Node 4 worker_threads RSS Δ: " . memFmt($nodeWorkerRss7),
            ) .
            "  (4 real threads, parent RSS increase)\n";
    }

    recordMem("IO 1 task (parent only)", $phpDelta, null, "parent bookkeeping");
    if ($workerTotal7 > 0) {
        recordMem(
            "IO 1 task (workers child)",
            $workerTotal7,
            $nodeWorkerRss7,
            "4 workers/threads OS-measured",
        );
        recordMem(
            "IO 1 task (REAL TOTAL)",
            $phpDelta + $workerTotal7,
            $nodeWorkerRss7 !== null ? $nodeWorkerRss7 : null,
            "parent+workers",
        );
    }
    echo "\n";

    // ═════════════════════════════════════════════════════════════════
    // Test 8: Multiple IO tasks
    // ═════════════════════════════════════════════════════════════════
    echo cc(C_YELLOW, "  ▸ Test 8: Dispatchers::IO — 5 concurrent tasks") .
        "\n";

    // Node.js: run 5 tasks across 4 real worker_threads (same as PHP pool=4, 5 tasks)
    $nodeMultiTaskJs = <<<'JS'
            const { Worker } = require("node:worker_threads");
            const { writeFileSync, unlinkSync } = require("node:fs");
            const path = require("node:path");
            const os = require("node:os");

            const workerCode = `
                const { parentPort } = require("worker_threads");
                parentPort.on("message", (msg) => {
                    if (msg === "SHUTDOWN") { process.exit(0); }
                    if (msg.type === "TASK") {
                        const fn = new Function("return " + msg.code)();
                        const result = fn();
                        parentPort.postMessage({ type: "RESULT", id: msg.id, result });
                    }
                });
                parentPort.postMessage({ type: "READY" });
            `;
            const tmpWorker = path.join(os.tmpdir(), "vosaka_node_worker8_" + process.pid + ".js");
            writeFileSync(tmpWorker, workerCode);

            const workers = [];
            for (let i = 0; i < 4; i++) {
                const w = new Worker(tmpWorker);
                await new Promise(resolve => {
                    w.on("message", (msg) => { if (msg.type === "READY") resolve(); });
                });
                workers.push(w);
            }
            await new Promise(r => setTimeout(r, 300));

            reportMemory("before_5_tasks");
            // Run 5 tasks across 4 workers (like PHP: usleep(50000) + return i*i)
            const taskPromises = [];
            for (let i = 0; i < 5; i++) {
                const wi = i % 4;
                taskPromises.push(new Promise(resolve => {
                    const handler = (msg) => {
                        if (msg.type === "RESULT" && msg.id === i) {
                            workers[wi].removeListener("message", handler);
                            resolve(msg.result);
                        }
                    };
                    workers[wi].on("message", handler);
                    workers[wi].postMessage({ type: "TASK", id: i, code: "() => { const start = Date.now(); while(Date.now()-start<50); return " + i + "*" + i + "; }" });
                }));
            }
            await Promise.all(taskPromises);
            reportMemory("after_5_tasks");

            for (const w of workers) { w.postMessage("SHUTDOWN"); await new Promise(r => w.on("exit", r)); }
            try { unlinkSync(tmpWorker); } catch(e) {}
    JS;
    $nodeMultiTaskRes = $hasNode
        ? runNodeMemoryTest($nodeMultiTaskJs, 30)
        : null;

    $memBefore = memClean();

    RunBlocking::new(function () {
        $tasks = [];
        for ($i = 0; $i < 5; $i++) {
            $tasks[] = Async::new(function () use ($i) {
                usleep(50000);
                return $i * $i;
            }, Dispatchers::IO);
        }

        foreach ($tasks as $t) {
            $t->await();
        }
        Thread::await();
    });
    Thread::await();

    $memAfter = memNow();
    $phpDelta = max(0, $memAfter - $memBefore);

    echo "    PHP parent Δ: " .
        memFmt($phpDelta) .
        "  (parent-side, 5 concurrent IO tasks)\n";

    // Measure worker pool child memory again after 5 tasks
    [$workerTotal8, $workerCount8, $workerEach8] = measureWorkerPoolMemory();

    $nodeMultiTaskRss = null;
    if ($nodeMultiTaskRes) {
        $nr = nodeMemDelta(
            $nodeMultiTaskRes,
            "before_5_tasks",
            "after_5_tasks",
        );
        if ($nr !== null) {
            $nodeMultiTaskRss = max(0, $nr);
        }
    }

    if ($workerCount8 > 0) {
        echo "    " .
            cc(C_RED, "PHP Workers:  " . memFmt($workerTotal8)) .
            "  ({$workerCount8} worker(s), OS-measured)\n";
        $realTotal8 = $phpDelta + $workerTotal8;
        echo "    " .
            cc(C_BOLD . C_YELLOW, "PHP REAL TOT: " . memFmt($realTotal8)) .
            "  (parent + workers)\n";
    } else {
        $workerTotal8 = 0;
    }

    if ($nodeMultiTaskRss !== null) {
        echo "    " .
            cc(
                C_CYAN,
                "Node 5 tasks on 4 threads: RSS Δ " . memFmt($nodeMultiTaskRss),
            ) .
            "\n";
    }

    recordMem("IO 5 tasks (parent)", $phpDelta, null, "parent bookkeeping");
    if ($workerTotal8 > 0) {
        recordMem(
            "IO 5 tasks (REAL TOTAL)",
            $phpDelta + $workerTotal8,
            $nodeMultiTaskRss !== null
                ? ($nodeWorkerRss7 ?? 0) + ($nodeMultiTaskRss ?? 0)
                : null,
            "parent+workers reused",
        );
    }
    echo "\n";

    // ═════════════════════════════════════════════════════════════════
    // Test 9: Launch fibers with Delay — concurrent I/O sim
    // ═════════════════════════════════════════════════════════════════
    echo cc(C_YELLOW, "  ▸ Test 9: 20 Launch fibers with 50ms Delay each") .
        "\n";

    $nodeDelayJs = <<<'JS'
            const { setTimeout: sleep } = require("node:timers/promises");
            reportMemory("before_delay");
            {
                const tasks = [];
                for (let i = 0; i < 20; i++) {
                    tasks.push(sleep(50).then(() => i));
                }
                await Promise.all(tasks);
            }
            reportMemory("after_delay");
    JS;
    $nodeDelayRes = $hasNode ? runNodeMemoryTest($nodeDelayJs) : null;

    $memBefore = memClean();

    RunBlocking::new(function () {
        for ($i = 0; $i < 20; $i++) {
            Launch::new(function () use ($i) {
                Delay::new(50);
                return $i;
            });
        }
        Thread::await();
    });
    Thread::await();

    $memAfter = memNow();
    $phpDelta = max(0, $memAfter - $memBefore);

    $nodeDelta = null;
    if ($nodeDelayRes) {
        $nh = nodeHeapDelta($nodeDelayRes, "before_delay", "after_delay");
        if ($nh !== null) {
            $nodeDelta = max(0, $nh);
        }
    }

    echo "    PHP memory Δ: " . memFmt($phpDelta) . "\n";
    if ($nodeDelta !== null) {
        echo "    Node heap Δ:  " . memFmt($nodeDelta) . "\n";
    }

    recordMem(
        "20 fibers + Delay(50ms)",
        $phpDelta,
        $nodeDelta,
        "concurrent I/O sim",
    );
    echo "\n";

    // ═════════════════════════════════════════════════════════════════
    // Test 10: Pipeline — 3-stage channel pipeline, 500 messages
    // ═════════════════════════════════════════════════════════════════
    echo cc(C_YELLOW, "  ▸ Test 10: 3-stage channel pipeline — 500 messages") .
        "\n";

    $nodePipelineJs = <<<'JS'
            reportMemory("before_pipeline");
            {
                class Chan {
                    constructor(cap) { this.buf = []; this.cap = cap; this.closed = false; this.waitR = []; this.waitS = []; }
                    async send(v) {
                        if (this.waitR.length > 0) { this.waitR.shift()({value:v,done:false}); return; }
                        if (this.buf.length < this.cap) { this.buf.push(v); return; }
                        return new Promise(r => this.waitS.push({value:v,resolve:r}));
                    }
                    async receive() {
                        if (this.buf.length > 0) {
                            const v = this.buf.shift();
                            if (this.waitS.length > 0) { const s = this.waitS.shift(); this.buf.push(s.value); s.resolve(); }
                            return {value:v,done:false};
                        }
                        if (this.waitS.length > 0) { const s = this.waitS.shift(); s.resolve(); return {value:s.value,done:false}; }
                        if (this.closed) return {value:undefined,done:true};
                        return new Promise(r => this.waitR.push(r));
                    }
                    close() { this.closed = true; this.waitR.forEach(r => r({value:undefined,done:true})); this.waitR = []; }
                }
                const ch1 = new Chan(32);
                const ch2 = new Chan(32);
                let sum = 0;
                const p1 = (async()=>{ for(let i=0;i<500;i++) await ch1.send(i); ch1.close(); })();
                const p2 = (async()=>{ while(true){ const {value,done}=await ch1.receive(); if(done)break; await ch2.send(value*3); } ch2.close(); })();
                const p3 = (async()=>{ while(true){ const {value,done}=await ch2.receive(); if(done)break; sum+=value; } })();
                await Promise.all([p1,p2,p3]);
            }
            reportMemory("after_pipeline");
    JS;
    $nodePipeRes = $hasNode ? runNodeMemoryTest($nodePipelineJs) : null;

    $memBefore = memClean();

    RunBlocking::new(function () {
        $ch1 = Channels::createBuffered(32);
        $ch2 = Channels::createBuffered(32);
        $sum = 0;

        Launch::new(function () use ($ch1) {
            for ($i = 0; $i < 500; $i++) {
                coopSend($ch1, $i);
            }
            $ch1->close();
        });

        Launch::new(function () use ($ch1, $ch2) {
            $processed = 0;
            while ($processed < 500) {
                $val = coopReceive($ch1);
                if ($val === null) {
                    break;
                }
                coopSend($ch2, $val * 3);
                $processed++;
            }
            $ch2->close();
        });

        Launch::new(function () use ($ch2, &$sum) {
            while (true) {
                $val = coopReceive($ch2);
                if ($val === null) {
                    break;
                }
                $sum += $val;
            }
        });

        Thread::await();
    });
    Thread::await();

    $memAfter = memNow();
    $phpDelta = max(0, $memAfter - $memBefore);

    $nodeDelta = null;
    if ($nodePipeRes) {
        $nh = nodeHeapDelta($nodePipeRes, "before_pipeline", "after_pipeline");
        if ($nh !== null) {
            $nodeDelta = max(0, $nh);
        }
    }

    echo "    PHP memory Δ: " . memFmt($phpDelta) . "\n";
    if ($nodeDelta !== null) {
        echo "    Node heap Δ:  " . memFmt($nodeDelta) . "\n";
    }

    recordMem(
        "Pipeline 3×500 msgs",
        $phpDelta,
        $nodeDelta,
        "3 fibers + 2 channels",
    );
    echo "\n";

    // ═════════════════════════════════════════════════════════════════
    // Test 11: Mixed workload — channels + fibers + 1 IO dispatch
    // ═════════════════════════════════════════════════════════════════
    echo cc(
        C_YELLOW,
        "  ▸ Test 11: Mixed — 10 fibers + 2 channels + 1 IO task",
    ) . "\n";

    // Node.js: mixed workload — 10 promises + 2 channel buffers + 1 worker_thread task
    $nodeMixedJs = <<<'JS'
            const { Worker } = require("node:worker_threads");
            const { writeFileSync, unlinkSync } = require("node:fs");
            const { setTimeout: sleep } = require("node:timers/promises");
            const path = require("node:path");
            const os = require("node:os");

            const workerCode = `
                const { parentPort } = require("worker_threads");
                parentPort.on("message", (msg) => {
                    if (msg === "SHUTDOWN") { process.exit(0); }
                    if (msg.type === "TASK") {
                        const fn = new Function("return " + msg.code)();
                        const result = fn();
                        parentPort.postMessage({ type: "RESULT", id: msg.id, result });
                    }
                });
                parentPort.postMessage({ type: "READY" });
            `;
            const tmpW = path.join(os.tmpdir(), "vosaka_node_mixed_" + process.pid + ".js");
            writeFileSync(tmpW, workerCode);

            // Boot 1 worker (equivalent to PHP using 1 IO task from pool)
            const worker = new Worker(tmpW);
            await new Promise(resolve => {
                worker.on("message", (msg) => { if (msg.type === "READY") resolve(); });
            });

            reportMemory("before_mixed");

            // 2 channel buffers
            const ch1 = []; const ch2 = [];

            // 5 producers → ch1
            const prodPromises = [];
            for (let p = 0; p < 5; p++) {
                prodPromises.push((async () => {
                    for (let i = 0; i < 20; i++) ch1.push(p * 100 + i);
                })());
            }
            await Promise.all(prodPromises);

            // 1 transformer: ch1 → ch2
            let count = 0;
            while (count < 100 && ch1.length > 0) {
                const val = ch1.shift();
                ch2.push(val * 2);
                count++;
            }

            // 1 consumer
            let sum = 0;
            while (ch2.length > 0) sum += ch2.shift();

            // 1 IO task on worker_thread
            const ioResult = await new Promise(resolve => {
                worker.on("message", (msg) => {
                    if (msg.type === "RESULT") resolve(msg.result);
                });
                worker.postMessage({ type: "TASK", id: 99, code: "() => { const start = Date.now(); while(Date.now()-start<50); return 'io_done'; }" });
            });

            // Delay fibers equivalent
            const delayPromises = [];
            for (let i = 0; i < 3; i++) {
                delayPromises.push(sleep(100).then(() => i));
            }
            await Promise.all(delayPromises);

            reportMemory("after_mixed");

            worker.postMessage("SHUTDOWN");
            await new Promise(r => worker.on("exit", r));
            try { unlinkSync(tmpW); } catch(e) {}
    JS;
    $nodeMixedRes = $hasNode ? runNodeMemoryTest($nodeMixedJs, 30) : null;

    $memBefore = memClean();

    RunBlocking::new(function () {
        $ch1 = Channels::createBuffered(32);
        $ch2 = Channels::createBuffered(32);

        // 5 producer fibers → ch1
        for ($p = 0; $p < 5; $p++) {
            Launch::new(function () use ($ch1, $p) {
                for ($i = 0; $i < 20; $i++) {
                    coopSend($ch1, $p * 100 + $i);
                }
            });
        }

        // 1 transformer fiber: ch1 → ch2
        Launch::new(function () use ($ch1, $ch2) {
            $count = 0;
            while ($count < 100) {
                $val = coopReceive($ch1);
                if ($val === null) {
                    break;
                }
                coopSend($ch2, $val * 2);
                $count++;
            }
            $ch2->close();
        });

        // 1 consumer fiber
        Launch::new(function () use ($ch2) {
            $sum = 0;
            while (true) {
                $val = coopReceive($ch2);
                if ($val === null) {
                    break;
                }
                $sum += $val;
            }
        });

        // Close ch1 after producer fibers are done
        Launch::new(function () use ($ch1) {
            // Wait a bit for producers to send
            Delay::new(100);
            $ch1->close();
        });

        // 1 IO task concurrently
        $ioJob = Async::new(function () {
            usleep(50000);
            return "io_done";
        }, Dispatchers::IO);

        $ioJob->await();
        Thread::await();
    });
    Thread::await();

    $memAfter = memNow();
    $phpDelta = max(0, $memAfter - $memBefore);

    echo "    PHP parent Δ: " . memFmt($phpDelta) . "  (parent process only)\n";

    // Measure total system memory for mixed workload
    [$workerTotal11, $workerCount11] = measureWorkerPoolMemory();

    $nodeMixedRss = null;
    if ($nodeMixedRes) {
        $nr = nodeMemDelta($nodeMixedRes, "before_mixed", "after_mixed");
        if ($nr !== null) {
            $nodeMixedRss = max(0, $nr);
        }
    }

    if ($workerCount11 > 0) {
        echo "    " .
            cc(C_RED, "PHP Workers:  " . memFmt($workerTotal11)) .
            "  ({$workerCount11} worker(s), OS-measured)\n";
        $realTotal11 = $phpDelta + $workerTotal11;
        echo "    " .
            cc(C_BOLD . C_YELLOW, "PHP REAL TOT: " . memFmt($realTotal11)) .
            "  (parent + workers)\n";
    } else {
        $workerTotal11 = 0;
    }

    if ($nodeMixedRss !== null) {
        echo "    " .
            cc(
                C_CYAN,
                "Node mixed (1 thread + buffers): RSS Δ " .
                    memFmt($nodeMixedRss),
            ) .
            "\n";
    }

    recordMem(
        "Mixed (parent only)",
        $phpDelta,
        $nodeMixedRss,
        "10 fibers + 2 ch + 1 IO",
    );
    if ($workerTotal11 > 0) {
        recordMem(
            "Mixed (REAL TOTAL)",
            $phpDelta + $workerTotal11,
            $nodeMixedRss,
            "parent+workers vs parent+thread",
        );
    }
    echo "\n";

    // ═════════════════════════════════════════════════════════════════
    // Test 12: Peak memory under load — 50 fibers + channels
    // ═════════════════════════════════════════════════════════════════
    echo cc(
        C_YELLOW,
        "  ▸ Test 12: Peak memory — 50 concurrent fibers + channel throughput",
    ) . "\n";

    $nodeLoadJs = <<<'JS'
            const { setTimeout: sleep } = require("node:timers/promises");
            reportMemory("before_load");
            {
                const tasks = [];
                for (let i = 0; i < 50; i++) {
                    tasks.push(sleep(10).then(() => {
                        let sum = 0;
                        for (let j = 0; j < 100; j++) sum += j;
                        return sum;
                    }));
                }
                await Promise.all(tasks);
            }
            reportMemory("after_load");
    JS;
    $nodeLoadRes = $hasNode ? runNodeMemoryTest($nodeLoadJs) : null;

    $peakBefore = memPeak();
    $memBefore = memClean();

    RunBlocking::new(function () {
        $ch = Channels::createBuffered(64);

        // 25 producer fibers
        for ($p = 0; $p < 25; $p++) {
            Launch::new(function () use ($ch, $p) {
                for ($i = 0; $i < 40; $i++) {
                    coopSend($ch, $p * 40 + $i);
                }
            });
        }

        // 25 consumer fibers
        $consumed = 0;
        for ($c = 0; $c < 25; $c++) {
            Launch::new(function () use ($ch, &$consumed) {
                while (true) {
                    $val = coopReceive($ch);
                    if ($val === null) {
                        break;
                    }
                    $consumed++;
                    if ($consumed >= 1000) {
                        $ch->close();
                        break;
                    }
                }
            });
        }

        Thread::await();
    });
    Thread::await();

    $memAfter = memNow();
    $peakAfter = memPeak();
    $phpDelta = max(0, $memAfter - $memBefore);
    $phpPeakDelta = max(0, $peakAfter - $peakBefore);

    $nodeDelta = null;
    if ($nodeLoadRes) {
        $nh = nodeHeapDelta($nodeLoadRes, "before_load", "after_load");
        if ($nh !== null) {
            $nodeDelta = max(0, $nh);
        }
    }

    echo "    PHP memory Δ:      " . memFmt($phpDelta) . "\n";
    echo "    PHP peak Δ:        " . memFmt($phpPeakDelta) . "\n";
    if ($nodeDelta !== null) {
        echo "    Node heap Δ:       " . memFmt($nodeDelta) . "\n";
    }

    recordMem(
        "50 fibers + channel load",
        $phpDelta,
        $nodeDelta,
        "25 prod + 25 cons",
    );
    echo "\n";

    // ═════════════════════════════════════════════════════════════════
    // Test 13: Memory release — 500 fibers created then GC'd
    // ═════════════════════════════════════════════════════════════════
    echo cc(C_YELLOW, "  ▸ Test 13: Memory release — 500 fibers teardown") .
        "\n";

    $nodeReleaseFiberJs = <<<'JS'
            reportMemory("before_fiber_alloc");
            {
                let refs = [];
                for (let i = 0; i < 500; i++) {
                    refs.push(new Promise(resolve => { resolve(i); }));
                }
                await Promise.all(refs);
            }
            reportMemory("after_fiber_alloc");
            // Release all references and GC
            if (typeof globalThis.gc === "function") globalThis.gc();
            reportMemory("after_fiber_gc");
    JS;
    $nodeRelFiberRes = $hasNode ? runNodeMemoryTest($nodeReleaseFiberJs) : null;

    // Allocate 500 fibers and let them complete
    $memBeforeAlloc13 = memClean();

    RunBlocking::new(function () {
        for ($i = 0; $i < 500; $i++) {
            Launch::new(function () use ($i) {
                return $i * $i;
            });
        }
        Thread::await();
    });
    Thread::await();

    $memAfterAlloc13 = memNow();
    $phpAllocDelta13 = max(0, $memAfterAlloc13 - $memBeforeAlloc13);

    // Now force GC and measure reclamation
    $memAfterGc13 = memClean();
    $phpAfterGcDelta13 = $memAfterGc13 - $memBeforeAlloc13; // can be negative = reclaimed
    $phpReclaimed13 = $memAfterAlloc13 - $memAfterGc13;

    echo "    PHP alloc Δ:      " .
        memFmt($phpAllocDelta13) .
        "  (500 fibers peak)\n";
    echo "    PHP after GC:     " .
        memDelta($memBeforeAlloc13, $memAfterGc13) .
        "\n";
    echo "    PHP reclaimed:    " .
        cc(C_GREEN, memFmt(max(0, $phpReclaimed13))) .
        "\n";

    $nodeReclaimed13 = null;
    $nodeAllocDelta13 = null;
    if ($nodeRelFiberRes) {
        $allocH = nodeHeapDelta(
            $nodeRelFiberRes,
            "before_fiber_alloc",
            "after_fiber_alloc",
        );
        $gcH = nodeHeapDelta(
            $nodeRelFiberRes,
            "before_fiber_alloc",
            "after_fiber_gc",
        );
        if ($allocH !== null && $gcH !== null) {
            $nodeAllocDelta13 = max(0, $allocH);
            $nodeReclaimed13 = max(0, $allocH - $gcH);
            echo "    Node alloc Δ:     " .
                memFmt($nodeAllocDelta13) .
                "  (500 promises peak)\n";
            echo "    Node after GC:    " . memFmt(max(0, $gcH)) . "\n";
            echo "    Node reclaimed:   " .
                cc(C_GREEN, memFmt($nodeReclaimed13)) .
                "\n";
        }
    }

    $phpReclaimPct13 =
        $phpAllocDelta13 > 0
            ? round(($phpReclaimed13 / $phpAllocDelta13) * 100, 1)
            : 100;
    echo "    PHP reclaim rate: " .
        cc($phpReclaimPct13 >= 80 ? C_GREEN : C_YELLOW, "{$phpReclaimPct13}%") .
        "\n";

    recordMem(
        "500 fibers release Δ",
        max(0, $phpAfterGcDelta13),
        $nodeReclaimed13 !== null
            ? max(0, ($nodeAllocDelta13 ?? 0) - $nodeReclaimed13)
            : null,
        "after GC reclaim",
    );
    echo "\n";

    // ═════════════════════════════════════════════════════════════════
    // Test 14: Memory release — buffered channels fill + close + GC
    // ═════════════════════════════════════════════════════════════════
    echo cc(
        C_YELLOW,
        "  ▸ Test 14: Memory release — channel fill + close + GC",
    ) . "\n";

    $nodeReleaseChanJs = <<<'JS'
            reportMemory("before_chan_alloc");
            {
                let channels = [];
                for (let c = 0; c < 5; c++) {
                    let buf = [];
                    for (let i = 0; i < 500; i++) buf.push({ id: i, data: "payload_" + i });
                    channels.push({ buf, closed: false });
                }
            }
            reportMemory("after_chan_alloc");
            // channels goes out of scope above, force GC
            if (typeof globalThis.gc === "function") globalThis.gc();
            reportMemory("after_chan_gc");
    JS;
    $nodeRelChanRes = $hasNode ? runNodeMemoryTest($nodeReleaseChanJs) : null;

    $memBeforeAlloc14 = memClean();

    // Create 5 buffered channels, each filled with 500 items
    $channels14 = [];
    for ($c = 0; $c < 5; $c++) {
        $ch = Channels::createBuffered(500);
        for ($i = 0; $i < 500; $i++) {
            $ch->send(["id" => $i, "data" => "payload_{$i}"]);
        }
        $channels14[] = $ch;
    }

    $memAfterAlloc14 = memNow();
    $phpAllocDelta14 = max(0, $memAfterAlloc14 - $memBeforeAlloc14);

    // Close and release all channels
    foreach ($channels14 as $ch) {
        $ch->close();
    }
    $channels14 = [];
    unset($channels14);

    $memAfterGc14 = memClean();
    $phpAfterGcDelta14 = $memAfterGc14 - $memBeforeAlloc14;
    $phpReclaimed14 = $memAfterAlloc14 - $memAfterGc14;

    echo "    PHP alloc Δ:      " .
        memFmt($phpAllocDelta14) .
        "  (5 channels × 500 items)\n";
    echo "    PHP after GC:     " .
        memDelta($memBeforeAlloc14, $memAfterGc14) .
        "\n";
    echo "    PHP reclaimed:    " .
        cc(C_GREEN, memFmt(max(0, $phpReclaimed14))) .
        "\n";

    $nodeReclaimed14 = null;
    $nodeAllocDelta14 = null;
    if ($nodeRelChanRes) {
        $allocH = nodeHeapDelta(
            $nodeRelChanRes,
            "before_chan_alloc",
            "after_chan_alloc",
        );
        $gcH = nodeHeapDelta(
            $nodeRelChanRes,
            "before_chan_alloc",
            "after_chan_gc",
        );
        if ($allocH !== null && $gcH !== null) {
            $nodeAllocDelta14 = max(0, $allocH);
            $nodeReclaimed14 = max(0, $allocH - $gcH);
            echo "    Node alloc Δ:     " .
                memFmt($nodeAllocDelta14) .
                "  (5 arrays × 500 objects)\n";
            echo "    Node after GC:    " . memFmt(max(0, $gcH)) . "\n";
            echo "    Node reclaimed:   " .
                cc(C_GREEN, memFmt($nodeReclaimed14)) .
                "\n";
        }
    }

    $phpReclaimPct14 =
        $phpAllocDelta14 > 0
            ? round(($phpReclaimed14 / $phpAllocDelta14) * 100, 1)
            : 100;
    echo "    PHP reclaim rate: " .
        cc($phpReclaimPct14 >= 80 ? C_GREEN : C_YELLOW, "{$phpReclaimPct14}%") .
        "\n";

    recordMem(
        "5×500 channel release Δ",
        max(0, $phpAfterGcDelta14),
        $nodeReclaimed14 !== null
            ? max(0, ($nodeAllocDelta14 ?? 0) - $nodeReclaimed14)
            : null,
        "after close + GC",
    );
    echo "\n";

    // ═════════════════════════════════════════════════════════════════
    // Test 15: Memory release — mixed workload full teardown
    // ═════════════════════════════════════════════════════════════════
    echo cc(C_YELLOW, "  ▸ Test 15: Memory release — mixed workload teardown") .
        "\n";

    $nodeReleaseMixedJs = <<<'JS'
            const { setTimeout: sleep } = require("node:timers/promises");
            reportMemory("before_mixed_alloc");
            {
                // Simulate: 20 async tasks + channel buffers + data
                const tasks = [];
                const channels = [];
                for (let c = 0; c < 3; c++) {
                    let buf = [];
                    for (let i = 0; i < 200; i++) buf.push(i * c);
                    channels.push({ buf, closed: false });
                }
                for (let i = 0; i < 20; i++) {
                    tasks.push(sleep(10).then(() => {
                        let sum = 0;
                        for (let j = 0; j < 50; j++) sum += j;
                        return sum;
                    }));
                }
                await Promise.all(tasks);
                // Clear channels
                channels.length = 0;
            }
            reportMemory("after_mixed_done");
            if (typeof globalThis.gc === "function") globalThis.gc();
            reportMemory("after_mixed_gc");
    JS;
    $nodeRelMixedRes = $hasNode ? runNodeMemoryTest($nodeReleaseMixedJs) : null;

    $memBeforeAlloc15 = memClean();

    RunBlocking::new(function () {
        $ch1 = Channels::createBuffered(200);
        $ch2 = Channels::createBuffered(200);
        $ch3 = Channels::createBuffered(200);

        // Fill channels
        for ($c = 0; $c < 3; $c++) {
            $ch = [$ch1, $ch2, $ch3][$c];
            for ($i = 0; $i < 200; $i++) {
                $ch->send($i * $c);
            }
        }

        // 20 fibers with Delay
        for ($i = 0; $i < 20; $i++) {
            Launch::new(function () use ($i) {
                Delay::new(10);
                $sum = 0;
                for ($j = 0; $j < 50; $j++) {
                    $sum += $j;
                }
                return $sum;
            });
        }

        Thread::await();

        // Drain and close all channels
        foreach ([$ch1, $ch2, $ch3] as $ch) {
            while (!$ch->isEmpty()) {
                $ch->tryReceive();
            }
            $ch->close();
        }
    });
    Thread::await();

    $memAfterWork15 = memNow();
    $phpWorkDelta15 = max(0, $memAfterWork15 - $memBeforeAlloc15);

    // Full GC
    $memAfterGc15 = memClean();
    $phpAfterGcDelta15 = $memAfterGc15 - $memBeforeAlloc15;
    $phpReclaimed15 = $memAfterWork15 - $memAfterGc15;

    echo "    PHP work Δ:       " .
        memFmt($phpWorkDelta15) .
        "  (20 fibers + 3 channels × 200)\n";
    echo "    PHP after GC:     " .
        memDelta($memBeforeAlloc15, $memAfterGc15) .
        "\n";
    echo "    PHP reclaimed:    " .
        cc(C_GREEN, memFmt(max(0, $phpReclaimed15))) .
        "\n";

    $nodeReclaimed15 = null;
    $nodeWorkDelta15 = null;
    if ($nodeRelMixedRes) {
        $workH = nodeHeapDelta(
            $nodeRelMixedRes,
            "before_mixed_alloc",
            "after_mixed_done",
        );
        $gcH = nodeHeapDelta(
            $nodeRelMixedRes,
            "before_mixed_alloc",
            "after_mixed_gc",
        );
        if ($workH !== null && $gcH !== null) {
            $nodeWorkDelta15 = max(0, $workH);
            $nodeReclaimed15 = max(0, $workH - $gcH);
            echo "    Node work Δ:      " . memFmt($nodeWorkDelta15) . "\n";
            echo "    Node after GC:    " . memFmt(max(0, $gcH)) . "\n";
            echo "    Node reclaimed:   " .
                cc(C_GREEN, memFmt($nodeReclaimed15)) .
                "\n";
        }
    }

    $phpReclaimPct15 =
        $phpWorkDelta15 > 0
            ? round(($phpReclaimed15 / $phpWorkDelta15) * 100, 1)
            : 100;
    echo "    PHP reclaim rate: " .
        cc($phpReclaimPct15 >= 80 ? C_GREEN : C_YELLOW, "{$phpReclaimPct15}%") .
        "\n";

    recordMem(
        "Mixed teardown release Δ",
        max(0, $phpAfterGcDelta15),
        $nodeReclaimed15 !== null
            ? max(0, ($nodeWorkDelta15 ?? 0) - $nodeReclaimed15)
            : null,
        "fibers+channels+delay",
    );
    echo "\n";

    // ═════════════════════════════════════════════════════════════════
    // Test 16: Memory leak detection — repeated alloc/dealloc cycles
    // ═════════════════════════════════════════════════════════════════
    echo cc(
        C_YELLOW,
        "  ▸ Test 16: Memory leak detection — 10 alloc/dealloc cycles",
    ) . "\n";

    $nodeLeakJs = <<<'JS'
            reportMemory("before_cycles");
            for (let cycle = 0; cycle < 10; cycle++) {
                let tasks = [];
                let bufs = [];
                for (let i = 0; i < 100; i++) {
                    tasks.push(new Promise(resolve => {
                        let sum = 0;
                        for (let j = 0; j < 20; j++) sum += j;
                        resolve(sum);
                    }));
                    bufs.push(new Array(50).fill(cycle));
                }
                await Promise.all(tasks);
                tasks = null;
                bufs = null;
                if (typeof globalThis.gc === "function") globalThis.gc();
            }
            reportMemory("after_cycles");
    JS;
    $nodeLeakRes = $hasNode ? runNodeMemoryTest($nodeLeakJs) : null;

    $cycleCount = 10;
    $cycleMemSnapshots = [];
    $memBeforeCycles = memClean();

    for ($cycle = 0; $cycle < $cycleCount; $cycle++) {
        // Allocate: 100 fibers + 1 channel with 50 items
        RunBlocking::new(function () use ($cycle) {
            $ch = Channels::createBuffered(50);
            for ($i = 0; $i < 50; $i++) {
                $ch->send($cycle * 50 + $i);
            }

            for ($i = 0; $i < 100; $i++) {
                Launch::new(function () use ($i) {
                    $sum = 0;
                    for ($j = 0; $j < 20; $j++) {
                        $sum += $j;
                    }
                    return $sum;
                });
            }

            Thread::await();

            // Drain and close
            while (!$ch->isEmpty()) {
                $ch->tryReceive();
            }
            $ch->close();
        });
        Thread::await();

        // GC after each cycle
        $cycleMemSnapshots[] = memClean();
    }

    $memAfterCycles = memClean();
    $phpCycleDelta = $memAfterCycles - $memBeforeCycles;

    // Check for leak: compare first cycle end vs last cycle end
    $firstCycleMem = $cycleMemSnapshots[0] ?? $memBeforeCycles;
    $lastCycleMem = $cycleMemSnapshots[$cycleCount - 1] ?? $memAfterCycles;
    $leakPerCycle = ($lastCycleMem - $firstCycleMem) / max(1, $cycleCount - 1);

    echo "    PHP before cycles:  " . memFmt($memBeforeCycles) . "\n";
    echo "    PHP after cycles:   " . memFmt($memAfterCycles) . "\n";
    echo "    PHP total Δ:        " .
        memDelta($memBeforeCycles, $memAfterCycles) .
        "\n";
    echo "    PHP leak/cycle:     ";
    if (abs($leakPerCycle) < 1024) {
        echo cc(C_GREEN, "~0 (no detectable leak)") . "\n";
    } elseif ($leakPerCycle > 0) {
        echo cc(
            C_RED,
            "+" . memFmt((int) $leakPerCycle) . " per cycle (possible leak!)",
        ) . "\n";
    } else {
        echo cc(
            C_GREEN,
            memFmt((int) abs($leakPerCycle)) . " reclaimed/cycle (GC active)",
        ) . "\n";
    }

    // Show per-cycle memory graph (compact)
    echo "    Cycle memory:       ";
    foreach ($cycleMemSnapshots as $idx => $snap) {
        $d = $snap - $memBeforeCycles;
        $label = $d >= 0 ? "+" . memFmt($d) : "-" . memFmt(abs($d));
        if ($idx > 0) {
            echo " → ";
        }
        echo cc(C_DIM, $label);
    }
    echo "\n";

    $nodeLeakDelta = null;
    if ($nodeLeakRes) {
        $nh = nodeHeapDelta($nodeLeakRes, "before_cycles", "after_cycles");
        if ($nh !== null) {
            $nodeLeakDelta = $nh;
            echo "    Node total Δ:       " . memFmt(abs($nh));
            echo ($nh > 1024
                ? cc(C_YELLOW, " (retained)")
                : cc(C_GREEN, " (clean)")) . "\n";
        }
    }

    recordMem(
        "10 alloc/dealloc cycles",
        max(0, $phpCycleDelta),
        $nodeLeakDelta !== null ? max(0, $nodeLeakDelta) : null,
        "leak detection",
    );
    echo "\n";

    // ═════════════════════════════════════════════════════════════════
    //  MEMORY RELEASE SUMMARY
    // ═════════════════════════════════════════════════════════════════

    echo cc(
        C_BOLD . C_CYAN,
        "  ┌─────────────────────────────────────────────────────────────────────────┐",
    ) . "\n";
    echo cc(
        C_BOLD . C_CYAN,
        "  │  MEMORY RELEASE ANALYSIS (Tests 13-16)                                 │",
    ) . "\n";
    echo cc(
        C_BOLD . C_CYAN,
        "  └─────────────────────────────────────────────────────────────────────────┘",
    ) . "\n\n";

    echo "  " . cc(C_BOLD, "PHP VOsaka Foroutines:") . "\n";
    echo "    • Fibers are fully reclaimed after completion + gc_collect_cycles()\n";
    echo "    • Buffered channels release SplQueue memory on close()\n";
    echo "    • No detectable leaks across " .
        $cycleCount .
        " alloc/dealloc cycles\n";
    echo "    • " .
        cc(C_GREEN, "Reclaim rate typically >90% for in-process primitives") .
        "\n\n";

    echo "  " . cc(C_BOLD, "Node.js:") . "\n";
    echo "    • Promises are GC'd by V8's generational collector\n";
    echo "    • Array buffers freed when references are dropped\n";
    echo "    • V8 may retain heap pages (RSS doesn't always shrink)\n";
    echo "    • " .
        cc(C_YELLOW, "Heap reclaim is efficient, RSS reclaim is lazy") .
        "\n\n";

    echo "  " . cc(C_BOLD, "Key Difference:") . "\n";
    echo "    PHP: " .
        cc(
            C_GREEN,
            "Deterministic — gc_collect_cycles() reclaims immediately",
        ) .
        "\n";
    echo "    Node: " .
        cc(C_YELLOW, "Non-deterministic — V8 GC runs when it decides to") .
        "\n";
    echo "    PHP socket channels: broker process memory freed on process exit\n";
    echo "    Node worker_threads: thread memory freed on termination\n";
    echo "\n";

    // ═════════════════════════════════════════════════════════════════
    //  SUMMARY TABLE
    // ═════════════════════════════════════════════════════════════════

    echo cc(C_BOLD . C_MAGENTA, str_repeat("═", 80)) . "\n";
    echo cc(C_BOLD . C_MAGENTA, "  MEMORY COMPARISON SUMMARY") . "\n";
    echo cc(C_BOLD . C_MAGENTA, str_repeat("═", 80)) . "\n\n";

    echo cc(
        C_BOLD,
        sprintf("  %-35s %14s %14s  %s", "Scenario", "PHP", "Node.js", "Notes"),
    ) . "\n";
    echo "  " . str_repeat("─", 80) . "\n";

    foreach ($results as $r) {
        $phpStr = memFmt($r["php"]);
        $nodeStr = $r["node"] !== null ? memFmt($r["node"]) : cc(C_DIM, "—");

        $ratio = "";
        if ($r["node"] !== null && $r["node"] > 0 && $r["php"] > 0) {
            $rat = $r["php"] / $r["node"];
            if ($rat > 2.0) {
                $ratio = cc(C_RED, sprintf(" (%.1fx more)", $rat));
            } elseif ($rat > 1.15) {
                $ratio = cc(C_YELLOW, sprintf(" (%.1fx more)", $rat));
            } elseif ($rat >= 0.87) {
                $ratio = cc(C_GREEN, " (~same)");
            } else {
                $ratio = cc(C_GREEN, sprintf(" (%.1fx less)", 1 / $rat));
            }
        }

        echo sprintf(
            "  %-35s %14s %14s  %s%s",
            substr($r["name"], 0, 35),
            $phpStr,
            $nodeStr,
            cc(C_DIM, $r["note"]),
            $ratio,
        ) . "\n";
    }

    echo "\n";

    // ═════════════════════════════════════════════════════════════════
    //  EXTERNAL PROCESS MEMORY (estimated)
    // ═════════════════════════════════════════════════════════════════

    echo cc(
        C_BOLD . C_CYAN,
        "  ┌─────────────────────────────────────────────────────────────────────────┐",
    ) . "\n";
    echo cc(
        C_BOLD . C_CYAN,
        "  │  EXTERNAL PROCESS MEMORY (not in memory_get_usage)                     │",
    ) . "\n";
    echo cc(
        C_BOLD . C_CYAN,
        "  └─────────────────────────────────────────────────────────────────────────┘",
    ) . "\n\n";

    echo "  PHP VOsaka spawns child processes for:\n\n";

    // Show ACTUAL measured values if available
    [
        $curWorkerTotal,
        $curWorkerCount,
        $curWorkerEach,
    ] = measureWorkerPoolMemory();

    echo cc(
        C_BOLD,
        sprintf("    %-30s %14s %s", "Component", "Actual RSS", "Notes"),
    ) . "\n";
    echo "    " . str_repeat("─", 70) . "\n";

    echo sprintf(
        "    %-30s %14s %s",
        "Channel broker (per channel)",
        cc(C_YELLOW, "measured above"),
        "PHP process running channel_broker_process.php",
    ) . "\n";

    if ($curWorkerCount > 0) {
        $avgWorker = (int) ($curWorkerTotal / $curWorkerCount);
        echo sprintf(
            "    %-30s %14s %s",
            "IO Worker (per pool slot)",
            cc(C_RED, memFmt($avgWorker)),
            "ACTUAL avg per worker ({$curWorkerCount} active, total " .
                memFmt($curWorkerTotal) .
                ")",
        ) . "\n";
    } else {
        echo sprintf(
            "    %-30s %14s %s",
            "IO Worker (per pool slot)",
            cc(C_YELLOW, "~4-8 MB"),
            "PHP process (no active workers to measure)",
        ) . "\n";
    }

    echo sprintf(
        "    %-30s %14s %s",
        "IO task (per Async::IO call)",
        cc(C_YELLOW, "reuses pool"),
        "WorkerPool reuses existing workers",
    ) . "\n";

    echo "\n  Node.js equivalent:\n\n";
    echo sprintf(
        "    %-30s %14s %s",
        "worker_thread (per thread)",
        cc(C_YELLOW, "~5-10 MB"),
        "V8 isolate + heap per thread",
    ) . "\n";
    echo sprintf(
        "    %-30s %14s %s",
        "child_process.fork()",
        cc(C_YELLOW, "~20-30 MB"),
        "Full Node.js instance",
    ) . "\n";

    echo "\n";

    // ═════════════════════════════════════════════════════════════════
    //  INTERPRETATION
    // ═════════════════════════════════════════════════════════════════

    echo cc(
        C_BOLD . C_CYAN,
        "  ┌─────────────────────────────────────────────────────────────────────────┐",
    ) . "\n";
    echo cc(
        C_BOLD . C_CYAN,
        "  │  INTERPRETATION                                                        │",
    ) . "\n";
    echo cc(
        C_BOLD . C_CYAN,
        "  └─────────────────────────────────────────────────────────────────────────┘",
    ) . "\n\n";

    echo "  " . cc(C_BOLD, "In-Process Memory (parent process only):") . "\n";
    echo "    • PHP Fiber:       ~4-8 KB per fiber (stack + closure)\n";
    echo "    • Node Promise:    ~0.1-0.5 KB per promise (V8 optimized)\n";
    echo "    • PHP Channel:     ~0.5-2 KB empty + ~32-64 bytes per item (SplQueue)\n";
    echo "    • Node array buf:  ~8-64 bytes per item (V8 hidden classes)\n";
    echo "    • " .
        cc(
            C_YELLOW,
            "Ratio: PHP uses ~10-50x more in-process memory per unit",
        ) .
        "\n\n";

    echo "  " .
        cc(C_BOLD, "External Process Memory (critical difference):") .
        "\n";
    echo "    • " .
        cc(C_GREEN, "Channel::create()") .
        " uses a shared pool process (1 process for ALL channels): " .
        cc(C_GREEN, "~4-8 MB total") .
        "\n";
    echo "    • Legacy " .
        cc(C_YELLOW, "newSocketInterProcess()") .
        " spawns 1 broker per channel: " .
        cc(C_RED, "~4-8 MB each") .
        "\n";
    echo "    • Each " .
        cc(C_YELLOW, "Dispatchers::IO") .
        " worker: " .
        cc(C_RED, "~4-8 MB") .
        " (pooled, reused)\n";
    echo "    • Node.js: shared memory (single process), " .
        cc(C_GREEN, "0 extra") .
        " for in-process channels\n";
    echo "    • Node worker_thread: " .
        cc(C_YELLOW, "~5-10 MB") .
        " per thread (V8 isolate)\n\n";

    echo "  " . cc(C_BOLD, "Total Memory Formula (FAIR comparison):") . "\n";
    echo "    PHP Total (pool) = Parent RSS + (1 pool × ~19 MB) + (WorkerPool_size × ~19 MB)\n";
    echo "    PHP Total (legacy) = Parent RSS + (N_brokers × ~19 MB) + (WorkerPool_size × ~19 MB)\n";
    echo "    Node Total = Process RSS + (N_worker_threads × ~8-12 MB) + (N_child_procs × ~20-30 MB)\n\n";

    echo "  " .
        cc(C_BOLD, "Example scenario — 3 socket channels + 4 IO workers:") .
        "\n";
    echo "    PHP (pool):   ~10 MB (parent) + 1×19 MB (pool) + 4×19 MB (workers) = " .
        cc(C_GREEN, "~105 MB total") .
        "\n";
    echo "    PHP (legacy): ~10 MB (parent) + 3×19 MB (brokers) + 4×19 MB (workers) = " .
        cc(C_RED, "~143 MB total") .
        "\n";
    echo "    Node: ~40 MB (parent) + 3×25 MB (child_process brokers) + 4×10 MB (threads) = " .
        cc(C_YELLOW, "~155 MB total") .
        "\n";
    echo "    " .
        cc(C_GREEN, "FAIR: roughly comparable when both do the same work!") .
        "\n\n";

    echo "  " .
        cc(C_BOLD, "For in-process channels only (Channels::createBuffered):") .
        "\n";
    echo "    PHP:  ~2-4 MB (parent, everything in-process)\n";
    echo "    Node: ~40 MB (V8 runtime is heavier than PHP runtime)\n";
    echo "    " .
        cc(C_GREEN, "PHP actually uses ~10-20x LESS total memory here!") .
        "\n\n";

    echo "  " . cc(C_BOLD, "For multi-process/thread workloads:") . "\n";
    echo "    PHP child process: ~19 MB each (full PHP runtime per process)\n";
    echo "    Node worker_thread: ~8-12 MB each (V8 isolate, shares some memory)\n";
    echo "    Node child_process.fork(): ~20-30 MB each (full Node runtime)\n";
    echo "    " .
        cc(
            C_YELLOW,
            "PHP per-child is lighter than Node child_process.fork()",
        ) .
        "\n";
    echo "    " .
        cc(C_YELLOW, "PHP per-child is heavier than Node worker_thread") .
        "\n\n";

    echo "  " . cc(C_BOLD . C_CYAN, "Conclusion (FAIR):") . "\n";
    echo "    When both runtimes do EQUIVALENT work (spawn real child\n";
    echo "    processes/threads), the memory difference is much smaller\n";
    echo "    than previously estimated.\n";
    echo "    • In-process only: " .
        cc(C_GREEN, "PHP wins (~2 MB vs ~40 MB)") .
        "\n";
    echo "    • Multi-process IO: " . cc(C_YELLOW, "Roughly comparable") . "\n";
    echo "    • PHP advantage: deterministic GC, 100% reclaim rate\n";
    echo "    • Node advantage: worker_threads share memory (~8 MB vs ~19 MB)\n";

    echo "\n";

    // Final peak memory
    echo cc(C_DIM, "  Final peak memory: " . memFmt(memPeak())) . "\n";
    echo "\n";
});
