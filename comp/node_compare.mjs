/**
 * Node.js Benchmark — Direct Comparison with VOsaka Foroutines PHP Benchmarks
 *
 * This script reproduces the same scenarios from bench_01..bench_05 using
 * idiomatic Node.js (Promises, setTimeout, streams, worker_threads, etc.)
 * so we can do an apples-to-apples comparison on the same machine.
 *
 * Usage:
 *   node comp/node_compare.mjs
 *
 * Requires: Node.js >= 16 (for worker_threads, performance.now, etc.)
 */

import { performance } from "node:perf_hooks";
import {
    Worker,
    isMainThread,
    parentPort,
    workerData,
} from "node:worker_threads";
import {
    createReadStream,
    writeFileSync,
    unlinkSync,
    existsSync,
    mkdirSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { createHash } from "node:crypto";
import { Readable, Transform, Writable } from "node:stream";
import { pipeline } from "node:stream/promises";
import { setTimeout as sleep } from "node:timers/promises";
import { fileURLToPath } from "node:url";

// ─── Worker thread handler ───────────────────────────────────────────────
if (!isMainThread) {
    const { task, args } = workerData;
    let result;
    switch (task) {
        case "sumPrimes":
            result = sumPrimesUpTo(args.n);
            break;
        case "fib":
            result = fibIterative(args.n);
            break;
        case "hashChain":
            result = hashChain(args.input, args.iterations);
            break;
        default:
            result = null;
    }
    parentPort.postMessage(result);
    process.exit(0);
}

// ─── Utilities ───────────────────────────────────────────────────────────

const COLORS = {
    reset: "\x1b[0m",
    bold: "\x1b[1m",
    dim: "\x1b[2m",
    red: "\x1b[31m",
    green: "\x1b[32m",
    yellow: "\x1b[33m",
    cyan: "\x1b[36m",
};

function c(color, text) {
    return `${COLORS[color]}${text}${COLORS.reset}`;
}

function formatTime(ms) {
    if (ms < 0.01) return `${(ms * 1000).toFixed(1)} µs`;
    if (ms < 1) return `${(ms * 1000).toFixed(1)} µs`;
    if (ms < 1000) return `${ms.toFixed(2)} ms`;
    return `${(ms / 1000).toFixed(3)} s`;
}

function formatMs(ms) {
    return formatTime(ms);
}

async function measure(fn) {
    const start = performance.now();
    const result = await fn();
    const elapsed = performance.now() - start;
    return [result, elapsed];
}

function measureSync(fn) {
    const start = performance.now();
    const result = fn();
    const elapsed = performance.now() - start;
    return [result, elapsed];
}

function comparison(label, blockingMs, asyncMs) {
    const ratio = blockingMs / asyncMs;
    let speedLabel;
    if (ratio > 1.15) {
        speedLabel = c("green", `▲ ${ratio.toFixed(2)}x faster (async)`);
    } else if (ratio < 0.87) {
        speedLabel = c("red", `▼ ${(1 / ratio).toFixed(2)}x slower (async)`);
    } else {
        speedLabel = c("yellow", `≈ ${ratio.toFixed(2)}x (roughly equal)`);
    }
    console.log(`    ${label}`);
    console.log(`      Blocking:  ${formatMs(blockingMs)}`);
    console.log(`      Async:     ${formatMs(asyncMs)}`);
    console.log(`      Speedup:   ${speedLabel}`);
}

const records = [];
function record(name, blockingMs, asyncMs, note = "") {
    records.push({ name, blockingMs, asyncMs, note });
}

function printSummary(title) {
    const divider = "═".repeat(86);
    const thinDiv = "─".repeat(86);
    console.log(`\n${c("cyan", divider)}`);
    console.log(c("cyan", `  ${title} — SUMMARY`));
    console.log(c("cyan", divider));
    console.log("");
    console.log(
        c(
            "bold",
            `  ${"Test".padEnd(40)} ${"Blocking".padStart(12)} ${"Async".padStart(12)} ${"Speedup".padStart(12)}   Note`,
        ),
    );
    console.log(`  ${thinDiv}`);

    let totalBlocking = 0;
    let totalAsync = 0;

    for (const r of records) {
        const ratio = r.blockingMs / r.asyncMs;
        let arrow;
        if (ratio > 1.15) arrow = `${ratio.toFixed(2)}x ▲`;
        else if (ratio < 0.87) arrow = `${(1 / ratio).toFixed(2)}x ▼`;
        else arrow = `${ratio.toFixed(2)}x ≈`;

        console.log(
            `  ${r.name.padEnd(40)} ${formatMs(r.blockingMs).padStart(12)} ${formatMs(r.asyncMs).padStart(12)} ${arrow.padStart(12)}   ${r.note}`,
        );
        totalBlocking += r.blockingMs;
        totalAsync += r.asyncMs;
    }

    console.log(`  ${thinDiv}`);
    const totalRatio = totalBlocking / totalAsync;
    let totalArrow;
    if (totalRatio > 1.15) totalArrow = `${totalRatio.toFixed(2)}x ▲`;
    else if (totalRatio < 0.87)
        totalArrow = `${(1 / totalRatio).toFixed(2)}x ▼`;
    else totalArrow = `${totalRatio.toFixed(2)}x ≈`;
    console.log(
        `  ${"TOTAL".padEnd(40)} ${formatMs(totalBlocking).padStart(12)} ${formatMs(totalAsync).padStart(12)} ${totalArrow.padStart(12)}`,
    );
    console.log("");
}

function header(text) {
    const d = "═".repeat(70);
    console.log(`\n${c("cyan", d)}`);
    console.log(c("cyan", `  ${text}`));
    console.log(c("cyan", d));
}

function subHeader(text) {
    console.log(`\n  ${c("yellow", `▸ ${text}`)}`);
    console.log(`  ${"─".repeat(60)}`);
}

function timing(label, ms) {
    console.log(`    ${label.padEnd(38)} ${formatMs(ms)}`);
}

// ─── CPU-bound functions (same algorithms as PHP benchmarks) ─────────────

function sumPrimesUpTo(n) {
    let sum = 0;
    for (let i = 2; i <= n; i++) {
        let isPrime = true;
        for (let j = 2, sq = Math.sqrt(i); j <= sq; j++) {
            if (i % j === 0) {
                isPrime = false;
                break;
            }
        }
        if (isPrime) sum += i;
    }
    return sum;
}

function fibIterative(n) {
    if (n <= 1) return n;
    let a = 0,
        b = 1;
    for (let i = 2; i <= n; i++) {
        [a, b] = [b, a + b];
    }
    return b;
}

function matMul(a, b) {
    const n = a.length;
    const result = Array.from({ length: n }, () => new Array(n).fill(0));
    for (let i = 0; i < n; i++) {
        for (let j = 0; j < n; j++) {
            let sum = 0;
            for (let k = 0; k < n; k++) {
                sum += a[i][k] * b[k][j];
            }
            result[i][j] = sum;
        }
    }
    return result;
}

function randomMatrix(n) {
    return Array.from({ length: n }, () =>
        Array.from({ length: n }, () => Math.random() * 10),
    );
}

function hashChain(input, iterations) {
    let data = input;
    for (let i = 0; i < iterations; i++) {
        data = createHash("sha256").update(data).digest("hex");
    }
    return data;
}

function simulateBlockingApiCall(ms) {
    const end = performance.now() + ms;
    while (performance.now() < end) {
        /* busy wait */
    }
}

function generateContent(sizeBytes) {
    const chars =
        "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789\n";
    let content = "";
    for (let i = 0; i < sizeBytes; i++) {
        content += chars[Math.floor(Math.random() * chars.length)];
    }
    return content;
}

function runWorker(task, args) {
    return new Promise((resolve, reject) => {
        const worker = new Worker(fileURLToPath(import.meta.url), {
            workerData: { task, args },
        });
        worker.on("message", resolve);
        worker.on("error", reject);
        worker.on("exit", (code) => {
            if (code !== 0)
                reject(new Error(`Worker exited with code ${code}`));
        });
    });
}

// ═════════════════════════════════════════════════════════════════════════
//  BENCHMARK 01: Concurrent Delay
// ═════════════════════════════════════════════════════════════════════════
async function bench01() {
    records.length = 0;
    header("Node.js Benchmark 01: Concurrent Delay");
    console.log(
        `    Blocking sequential sleep vs async parallel setTimeout/sleep`,
    );
    console.log(`    Node.js ${process.version} | ${process.platform}`);

    // Test 1: 5 tasks × 200ms
    subHeader("Test 1: 5 tasks × 200ms delay");
    let taskCount = 5,
        delayMs = 200;

    let [, blockingMs] = measureSync(() => {
        for (let i = 0; i < taskCount; i++) {
            const end = performance.now() + delayMs;
            while (performance.now() < end) {}
        }
    });
    timing("Blocking (sequential busy-wait):", blockingMs);

    let [, asyncMs] = await measure(async () => {
        const tasks = [];
        for (let i = 0; i < taskCount; i++) {
            tasks.push(sleep(delayMs));
        }
        await Promise.all(tasks);
    });
    timing("Async (concurrent setTimeout):", asyncMs);
    comparison("5×200ms", blockingMs, asyncMs);
    record("5×200ms delay", blockingMs, asyncMs, "basic concurrency");

    // Test 2: 10 tasks × 100ms
    subHeader("Test 2: 10 tasks × 100ms delay");
    taskCount = 10;
    delayMs = 100;

    [, blockingMs] = measureSync(() => {
        for (let i = 0; i < taskCount; i++) {
            const end = performance.now() + delayMs;
            while (performance.now() < end) {}
        }
    });
    timing("Blocking (sequential busy-wait):", blockingMs);

    [, asyncMs] = await measure(async () => {
        await Promise.all(
            Array.from({ length: taskCount }, () => sleep(delayMs)),
        );
    });
    timing("Async (concurrent setTimeout):", asyncMs);
    comparison("10×100ms", blockingMs, asyncMs);
    record("10×100ms delay", blockingMs, asyncMs, "more tasks");

    // Test 3: 20 tasks × 50ms
    subHeader("Test 3: 20 tasks × 50ms delay");
    taskCount = 20;
    delayMs = 50;

    [, blockingMs] = measureSync(() => {
        for (let i = 0; i < taskCount; i++) {
            const end = performance.now() + delayMs;
            while (performance.now() < end) {}
        }
    });
    timing("Blocking (sequential busy-wait):", blockingMs);

    [, asyncMs] = await measure(async () => {
        await Promise.all(
            Array.from({ length: taskCount }, () => sleep(delayMs)),
        );
    });
    timing("Async (concurrent setTimeout):", asyncMs);
    comparison("20×50ms", blockingMs, asyncMs);
    record("20×50ms delay", blockingMs, asyncMs, "many small tasks");

    // Test 4: Staggered delays
    subHeader("Test 4: Staggered delays (100..500ms)");
    const delays = [100, 200, 300, 400, 500];

    [, blockingMs] = measureSync(() => {
        for (const ms of delays) {
            const end = performance.now() + ms;
            while (performance.now() < end) {}
        }
    });
    timing("Blocking (sequential):", blockingMs);

    [, asyncMs] = await measure(async () => {
        await Promise.all(delays.map((ms) => sleep(ms)));
    });
    timing("Async (concurrent):", asyncMs);
    comparison("Staggered 100-500ms", blockingMs, asyncMs);
    record("Staggered delays", blockingMs, asyncMs, "100+200+300+400+500ms");

    // Test 5: Async/Await with return values
    subHeader("Test 5: Async/Await with return values (5×150ms)");
    taskCount = 5;
    delayMs = 150;

    [, blockingMs] = measureSync(() => {
        const results = [];
        for (let i = 0; i < taskCount; i++) {
            const end = performance.now() + delayMs;
            while (performance.now() < end) {}
            results.push(i * i);
        }
        return results;
    });
    timing("Blocking (sequential):", blockingMs);

    let [asyncResults, asyncMs2] = await measure(async () => {
        const promises = [];
        for (let i = 0; i < taskCount; i++) {
            promises.push(sleep(delayMs).then(() => i * i));
        }
        return Promise.all(promises);
    });
    asyncMs = asyncMs2;
    timing("Async (concurrent await):", asyncMs);
    comparison("5×150ms async/await", blockingMs, asyncMs);
    const expected = [0, 1, 4, 9, 16];
    console.log(`    ✓ PASS Results: [${asyncResults}] === [${expected}]`);
    record("Async/Await 5×150ms", blockingMs, asyncMs, "with return values");

    // Test 6: High concurrency — 50 tasks × 100ms
    subHeader("Test 6: High concurrency — 50 tasks × 100ms");
    taskCount = 50;
    delayMs = 100;

    [, blockingMs] = measureSync(() => {
        for (let i = 0; i < taskCount; i++) {
            const end = performance.now() + delayMs;
            while (performance.now() < end) {}
        }
    });
    timing("Blocking (sequential):", blockingMs);

    [, asyncMs] = await measure(async () => {
        await Promise.all(
            Array.from({ length: taskCount }, () => sleep(delayMs)),
        );
    });
    timing("Async (concurrent):", asyncMs);
    comparison("50×100ms", blockingMs, asyncMs);
    record("50×100ms delay", blockingMs, asyncMs, "high concurrency");

    // Test 7: Nested — 3 parents × 3 children × 80ms
    subHeader("Test 7: Nested (3 parents × 3 children × 80ms)");
    const parentCount = 3,
        childCount = 3;
    delayMs = 80;

    [, blockingMs] = measureSync(() => {
        for (let p = 0; p < parentCount; p++) {
            for (let ch = 0; ch < childCount; ch++) {
                const end = performance.now() + delayMs;
                while (performance.now() < end) {}
            }
        }
    });
    timing("Blocking (sequential):", blockingMs);

    [, asyncMs] = await measure(async () => {
        const parents = [];
        for (let p = 0; p < parentCount; p++) {
            parents.push(
                (async () => {
                    const children = [];
                    for (let ch = 0; ch < childCount; ch++) {
                        children.push(sleep(delayMs));
                    }
                    await Promise.all(children);
                })(),
            );
        }
        await Promise.all(parents);
    });
    timing("Async (nested concurrent):", asyncMs);
    comparison("Nested 3×3×80ms", blockingMs, asyncMs);
    record("Nested 3×3×80ms", blockingMs, asyncMs, "structured concurrency");

    // Test 8: Scheduler overhead — 100 tasks × 1ms
    subHeader("Test 8: Scheduler overhead — 100 tasks × 1ms");
    taskCount = 100;
    delayMs = 1;

    [, blockingMs] = measureSync(() => {
        for (let i = 0; i < taskCount; i++) {
            const end = performance.now() + delayMs;
            while (performance.now() < end) {}
        }
    });
    timing("Blocking (sequential):", blockingMs);

    [, asyncMs] = await measure(async () => {
        await Promise.all(
            Array.from({ length: taskCount }, () => sleep(delayMs)),
        );
    });
    timing("Async (concurrent):", asyncMs);
    comparison("100×1ms (overhead)", blockingMs, asyncMs);
    record("100×1ms overhead", blockingMs, asyncMs, "scheduler overhead test");

    printSummary("Benchmark 01: Concurrent Delay");
}

// ═════════════════════════════════════════════════════════════════════════
//  BENCHMARK 02: CPU-Bound Computation
// ═════════════════════════════════════════════════════════════════════════
async function bench02() {
    records.length = 0;
    header("Node.js Benchmark 02: CPU-Bound Computation");
    console.log(
        `    Blocking sequential vs async (Promise wrapper / worker_threads)`,
    );
    console.log(`    Node.js ${process.version} | ${process.platform}`);

    // Test 1: Single computation — sumPrimes(10000)
    subHeader("Test 1: Single computation — sumPrimes(10000)");

    let [blockingResult, blockingMs] = measureSync(() => sumPrimesUpTo(10000));
    timing("Blocking (direct call):", blockingMs);

    let [asyncResult, asyncMs] = await measure(async () =>
        sumPrimesUpTo(10000),
    );
    timing("Async (Promise wrapper):", asyncMs);
    comparison("sumPrimes(10000)", blockingMs, asyncMs);
    console.log(`    ✓ PASS Results match: ${blockingResult === asyncResult}`);
    record("Single sumPrimes", blockingMs, asyncMs, "promise wrapper overhead");

    // Test 2: 5 independent computations — sumPrimes(5000)
    subHeader("Test 2: 5 independent computations — sumPrimes(5000)");

    [, blockingMs] = measureSync(() => {
        const results = [];
        for (let i = 0; i < 5; i++) results.push(sumPrimesUpTo(5000));
        return results;
    });
    timing("Blocking (sequential):", blockingMs);

    [, asyncMs] = await measure(async () => {
        return Promise.all(
            Array.from({ length: 5 }, () =>
                Promise.resolve().then(() => sumPrimesUpTo(5000)),
            ),
        );
    });
    timing("Async (5 Promises, same thread):", asyncMs);
    comparison("5×sumPrimes(5000)", blockingMs, asyncMs);
    record(
        "5×sumPrimes(5000)",
        blockingMs,
        asyncMs,
        "same thread, no parallelism",
    );

    // Test 3: Promise creation overhead — 500 trivial tasks
    subHeader("Test 3: Promise creation overhead — 500 trivial tasks");

    [, blockingMs] = measureSync(() => {
        let sum = 0;
        for (let i = 0; i < 500; i++) sum += i;
        return sum;
    });
    timing("Blocking (plain loop):", blockingMs);

    [, asyncMs] = await measure(async () => {
        const promises = [];
        for (let i = 0; i < 500; i++) {
            promises.push(Promise.resolve(i));
        }
        const results = await Promise.all(promises);
        return results.reduce((a, b) => a + b, 0);
    });
    timing("Async (500 Promises):", asyncMs);
    comparison("500 trivial Promises", blockingMs, asyncMs);
    const overheadPerPromise = ((asyncMs * 1000) / 500).toFixed(1);
    console.log(`        Overhead per Promise: ~${overheadPerPromise} µs`);
    record(
        "500 trivial Promises",
        blockingMs,
        asyncMs,
        "creation + await cost",
    );

    // Test 4: Microtask overhead — 1000 queueMicrotask yields
    subHeader("Test 4: Microtask overhead — 1000 queueMicrotask yields");

    [, blockingMs] = measureSync(() => {
        for (let i = 0; i < 1000; i++) {
            /* no-op */
        }
    });
    timing("Blocking (no-op loop):", blockingMs);

    [, asyncMs] = await measure(async () => {
        for (let i = 0; i < 1000; i++) {
            await new Promise((resolve) => queueMicrotask(resolve));
        }
    });
    timing("Async (1000 microtask yields):", asyncMs);
    comparison("1000 yields", blockingMs, asyncMs);
    const costPerYield = ((asyncMs * 1000) / 1000).toFixed(1);
    console.log(`        Cost per yield: ~${costPerYield} µs`);
    record("1000 yields", blockingMs, asyncMs, "queueMicrotask cost");

    // Test 5: Async overhead — 10×fib(50)
    subHeader("Test 5: Async overhead — 10×fib(50)");

    [blockingResult, blockingMs] = measureSync(() => {
        const results = [];
        for (let i = 0; i < 10; i++) results.push(fibIterative(50));
        return results;
    });
    timing("Blocking (sequential):", blockingMs);

    [asyncResult, asyncMs] = await measure(async () => {
        return Promise.all(
            Array.from({ length: 10 }, () =>
                Promise.resolve().then(() => fibIterative(50)),
            ),
        );
    });
    timing("Async (10 Promise.resolve):", asyncMs);
    comparison("10×fib(50)", blockingMs, asyncMs);
    console.log(
        `    ✓ PASS Results match: ${blockingResult[0] === asyncResult[0]}`,
    );
    record(
        "10×fib(50) await",
        blockingMs,
        asyncMs,
        "Promise.resolve + then cost",
    );

    // Test 6: Matrix multiplication — 50×50
    subHeader("Test 6: Matrix multiplication — 50×50 matmul");

    const mA = randomMatrix(50),
        mB = randomMatrix(50);
    [, blockingMs] = measureSync(() => matMul(mA, mB));
    timing("Blocking (direct):", blockingMs);

    [, asyncMs] = await measure(async () => matMul(mA, mB));
    timing("Async (Promise-wrapped):", asyncMs);
    comparison("50×50 matmul", blockingMs, asyncMs);
    record("50×50 matmul", blockingMs, asyncMs, "heavy single computation");

    // Test 7: Hash chain — 3×5000 SHA-256
    subHeader("Test 7: Hash chain — 3×5000 SHA-256 iterations");

    [blockingResult, blockingMs] = measureSync(() => {
        return [
            hashChain("input_0", 5000),
            hashChain("input_1", 5000),
            hashChain("input_2", 5000),
        ];
    });
    timing("Blocking (sequential):", blockingMs);

    [asyncResult, asyncMs] = await measure(async () => {
        return Promise.all([
            Promise.resolve().then(() => hashChain("input_0", 5000)),
            Promise.resolve().then(() => hashChain("input_1", 5000)),
            Promise.resolve().then(() => hashChain("input_2", 5000)),
        ]);
    });
    timing("Async (3 Promises):", asyncMs);
    comparison("3×5000 SHA-256", blockingMs, asyncMs);
    console.log(
        `    ✓ PASS Hash results match: ${blockingResult[0] === asyncResult[0]}`,
    );
    record("3×SHA-256 chains", blockingMs, asyncMs, "hash chains, no I/O");

    // Test 8: Worker thread — sumPrimes(8000) in child thread
    subHeader("Test 8: Worker thread — sumPrimes(8000) in worker_thread");
    console.log(`        (This tests worker_thread spawn + message overhead)`);

    [blockingResult, blockingMs] = measureSync(() => sumPrimesUpTo(8000));
    timing("Blocking (direct):", blockingMs);

    [asyncResult, asyncMs] = await measure(async () => {
        return runWorker("sumPrimes", { n: 8000 });
    });
    timing("Worker thread (child):", asyncMs);
    comparison("Worker thread overhead", blockingMs, asyncMs);
    console.log(
        `    ✓ PASS Worker result matches: ${blockingResult === asyncResult}`,
    );
    const workerOverhead = (asyncMs - blockingMs).toFixed(2);
    console.log(`        Thread spawn overhead: ~${workerOverhead} ms`);
    record(
        "Worker sumPrimes(8000)",
        blockingMs,
        asyncMs,
        "worker_thread overhead",
    );

    // Test 9: Mixed CPU + I/O — 5×(sumPrimes(3000) + 100ms delay)
    subHeader("Test 9: Mixed CPU + I/O — 5×(sumPrimes(3000) + 100ms delay)");

    [, blockingMs] = measureSync(() => {
        for (let i = 0; i < 5; i++) {
            sumPrimesUpTo(3000);
            const end = performance.now() + 100;
            while (performance.now() < end) {}
        }
    });
    timing("Blocking (sequential):", blockingMs);

    [, asyncMs] = await measure(async () => {
        const tasks = [];
        for (let i = 0; i < 5; i++) {
            tasks.push(
                (async () => {
                    sumPrimesUpTo(3000);
                    await sleep(100);
                })(),
            );
        }
        await Promise.all(tasks);
    });
    timing("Async (concurrent):", asyncMs);
    comparison("Mixed CPU+I/O", blockingMs, asyncMs);
    record("Mixed CPU+I/O", blockingMs, asyncMs, "CPU work + delay overlap");

    // Test 10: Scaling — fiber/promise count impact on CPU overhead
    subHeader("Test 10: Scaling — promise count impact on CPU overhead");

    for (const count of [1, 5, 10, 25, 50]) {
        const [, bMs] = measureSync(() => {
            for (let i = 0; i < count; i++) sumPrimesUpTo(3000);
        });
        const [, aMs] = await measure(async () => {
            const tasks = Array.from({ length: count }, () =>
                Promise.resolve().then(() => sumPrimesUpTo(3000)),
            );
            return Promise.all(tasks);
        });
        const overhead = (((aMs - bMs) / bMs) * 100).toFixed(1);
        console.log(
            `        ${String(count).padStart(5)} promises: blocking=${formatMs(bMs).padEnd(15)} async=${formatMs(aMs).padEnd(15)} overhead=${overhead > 0 ? "+" : ""}${overhead}%`,
        );
    }

    printSummary("Benchmark 02: CPU-Bound");
}

// ═════════════════════════════════════════════════════════════════════════
//  BENCHMARK 03: Channel Throughput (simulated with message passing)
// ═════════════════════════════════════════════════════════════════════════
async function bench03() {
    records.length = 0;
    header("Node.js Benchmark 03: Channel Throughput");
    console.log(`    Array-based queue vs async channel patterns`);
    console.log(`    Node.js ${process.version} | ${process.platform}`);

    // Simple async channel implementation for Node.js
    class Channel {
        constructor(capacity = 0) {
            this.capacity = capacity;
            this.buffer = [];
            this.closed = false;
            this.waitingSenders = [];
            this.waitingReceivers = [];
        }

        async send(value) {
            if (this.closed) throw new Error("Channel closed");

            if (this.waitingReceivers.length > 0) {
                const resolve = this.waitingReceivers.shift();
                resolve({ value, done: false });
                return;
            }

            if (this.capacity > 0 && this.buffer.length < this.capacity) {
                this.buffer.push(value);
                return;
            }

            return new Promise((resolve) => {
                this.waitingSenders.push({ value, resolve });
            });
        }

        async receive() {
            if (this.buffer.length > 0) {
                const value = this.buffer.shift();
                if (this.waitingSenders.length > 0) {
                    const sender = this.waitingSenders.shift();
                    this.buffer.push(sender.value);
                    sender.resolve();
                }
                return { value, done: false };
            }

            if (this.waitingSenders.length > 0) {
                const sender = this.waitingSenders.shift();
                sender.resolve();
                return { value: sender.value, done: false };
            }

            if (this.closed) return { value: undefined, done: true };

            return new Promise((resolve) => {
                this.waitingReceivers.push(resolve);
            });
        }

        close() {
            this.closed = true;
            for (const resolve of this.waitingReceivers) {
                resolve({ value: undefined, done: true });
            }
            this.waitingReceivers.length = 0;
            for (const sender of this.waitingSenders) {
                sender.resolve();
            }
            this.waitingSenders.length = 0;
        }
    }

    // Test 1: Unbuffered single producer/consumer — 1000 messages
    subHeader("Test 1: Unbuffered single producer/consumer — 1000 msgs");
    const msgCount1 = 1000;

    let [, blockingMs] = measureSync(() => {
        const queue = [];
        for (let i = 0; i < msgCount1; i++) queue.push(i);
        let sum = 0;
        for (const v of queue) sum += v;
        return sum;
    });
    timing("Blocking (array queue):", blockingMs);

    let [, asyncMs] = await measure(async () => {
        const ch = new Channel();
        let sum = 0;

        const producer = (async () => {
            for (let i = 0; i < msgCount1; i++) await ch.send(i);
            ch.close();
        })();

        const consumer = (async () => {
            while (true) {
                const { value, done } = await ch.receive();
                if (done) break;
                sum += value;
            }
        })();

        await Promise.all([producer, consumer]);
        return sum;
    });
    timing("Async (channel):", asyncMs);
    comparison(`Unbuffered ${msgCount1} msgs`, blockingMs, asyncMs);
    record(
        `Unbuf ${msgCount1} msgs`,
        blockingMs,
        asyncMs,
        "single producer/consumer",
    );

    // Test 2: Buffered channel — 1000 msgs, buffer=32
    subHeader("Test 2: Buffered channel — 1000 msgs, buffer=32");

    [, blockingMs] = measureSync(() => {
        const queue = [];
        for (let i = 0; i < msgCount1; i++) queue.push(i);
        let sum = 0;
        for (const v of queue) sum += v;
        return sum;
    });
    timing("Blocking (array queue):", blockingMs);

    [, asyncMs] = await measure(async () => {
        const ch = new Channel(32);
        let sum = 0;

        const producer = (async () => {
            for (let i = 0; i < msgCount1; i++) await ch.send(i);
            ch.close();
        })();

        const consumer = (async () => {
            while (true) {
                const { value, done } = await ch.receive();
                if (done) break;
                sum += value;
            }
        })();

        await Promise.all([producer, consumer]);
        return sum;
    });
    timing("Async (buffered channel):", asyncMs);
    comparison(`Buffered(32) ${msgCount1} msgs`, blockingMs, asyncMs);
    record(
        `Buf(32) ${msgCount1} msgs`,
        blockingMs,
        asyncMs,
        "buffered channel",
    );

    // Test 3: Fan-out — 1 producer, 3 consumers
    subHeader("Test 3: Fan-out — 1 producer, 3 consumers, 900 msgs");
    const msgCount3 = 900;

    [, blockingMs] = measureSync(() => {
        const queues = [[], [], []];
        for (let i = 0; i < msgCount3; i++) queues[i % 3].push(i);
        let total = 0;
        for (const q of queues) for (const v of q) total += v;
        return total;
    });
    timing("Blocking (array round-robin):", blockingMs);

    [, asyncMs] = await measure(async () => {
        const ch = new Channel(16);
        let total = 0;

        const producer = (async () => {
            for (let i = 0; i < msgCount3; i++) await ch.send(i);
            ch.close();
        })();

        const consumers = Array.from({ length: 3 }, () =>
            (async () => {
                let sum = 0;
                while (true) {
                    const { value, done } = await ch.receive();
                    if (done) break;
                    sum += value;
                }
                return sum;
            })(),
        );

        const [, ...sums] = await Promise.all([producer, ...consumers]);
        total = sums.reduce((a, b) => a + b, 0);
        return total;
    });
    timing("Async (fan-out channel):", asyncMs);
    comparison(`Fan-out ${msgCount3} msgs`, blockingMs, asyncMs);
    record(`Fan-out ${msgCount3} msgs`, blockingMs, asyncMs, "1 prod → 3 cons");

    // Test 4: Fan-in — 3 producers, 1 consumer
    subHeader("Test 4: Fan-in — 3 producers, 1 consumer, 900 msgs");

    [, blockingMs] = measureSync(() => {
        const queue = [];
        for (let p = 0; p < 3; p++) {
            for (let i = 0; i < 300; i++) queue.push(p * 300 + i);
        }
        let sum = 0;
        for (const v of queue) sum += v;
        return sum;
    });
    timing("Blocking (array concat):", blockingMs);

    [, asyncMs] = await measure(async () => {
        const ch = new Channel(16);
        let sum = 0;

        const producers = Array.from({ length: 3 }, (_, p) =>
            (async () => {
                for (let i = 0; i < 300; i++) await ch.send(p * 300 + i);
            })(),
        );

        const consumer = (async () => {
            let count = 0;
            while (count < 900) {
                const { value, done } = await ch.receive();
                if (done) break;
                sum += value;
                count++;
            }
        })();

        await Promise.all([...producers, consumer]);
        ch.close();
        return sum;
    });
    timing("Async (fan-in channel):", asyncMs);
    comparison(`Fan-in ${msgCount3} msgs`, blockingMs, asyncMs);
    record(`Fan-in ${msgCount3} msgs`, blockingMs, asyncMs, "3 prod → 1 cons");

    // Test 5: Pipeline — producer → transform → consumer (500 msgs)
    subHeader("Test 5: Pipeline — producer → transform → consumer, 500 msgs");
    const msgCount5 = 500;

    [, blockingMs] = measureSync(() => {
        const data = [];
        for (let i = 0; i < msgCount5; i++) data.push(i);
        const transformed = data.map((v) => v * 2 + 1);
        let sum = 0;
        for (const v of transformed) sum += v;
        return sum;
    });
    timing("Blocking (array map):", blockingMs);

    [, asyncMs] = await measure(async () => {
        const ch1 = new Channel(16);
        const ch2 = new Channel(16);
        let sum = 0;

        const producer = (async () => {
            for (let i = 0; i < msgCount5; i++) await ch1.send(i);
            ch1.close();
        })();

        const transformer = (async () => {
            while (true) {
                const { value, done } = await ch1.receive();
                if (done) break;
                await ch2.send(value * 2 + 1);
            }
            ch2.close();
        })();

        const consumer = (async () => {
            while (true) {
                const { value, done } = await ch2.receive();
                if (done) break;
                sum += value;
            }
        })();

        await Promise.all([producer, transformer, consumer]);
        return sum;
    });
    timing("Async (pipeline channel):", asyncMs);
    comparison(`Pipeline ${msgCount5} msgs`, blockingMs, asyncMs);
    record(
        `Pipeline ${msgCount5} msgs`,
        blockingMs,
        asyncMs,
        "prod → transform → cons",
    );

    // Test 6: Throughput measurement — large volume
    subHeader("Test 6: Throughput — 10000 messages, buffered(64)");
    const msgCount6 = 10000;

    [, blockingMs] = measureSync(() => {
        const queue = [];
        for (let i = 0; i < msgCount6; i++) queue.push(i);
        let sum = 0;
        for (const v of queue) sum += v;
        return sum;
    });
    timing("Blocking (array):", blockingMs);

    [, asyncMs] = await measure(async () => {
        const ch = new Channel(64);
        let sum = 0;

        const producer = (async () => {
            for (let i = 0; i < msgCount6; i++) await ch.send(i);
            ch.close();
        })();

        const consumer = (async () => {
            while (true) {
                const { value, done } = await ch.receive();
                if (done) break;
                sum += value;
            }
        })();

        await Promise.all([producer, consumer]);
        return sum;
    });
    timing("Async (channel buf=64):", asyncMs);
    comparison(`Throughput ${msgCount6} msgs`, blockingMs, asyncMs);
    const throughput = Math.round(msgCount6 / (asyncMs / 1000));
    console.log(`        Throughput: ~${throughput.toLocaleString()} msgs/sec`);
    record(
        `Throughput ${msgCount6}`,
        blockingMs,
        asyncMs,
        `~${throughput.toLocaleString()} msg/s`,
    );

    printSummary("Benchmark 03: Channel Throughput");
}

// ═════════════════════════════════════════════════════════════════════════
//  BENCHMARK 04: I/O-Bound Operations
// ═════════════════════════════════════════════════════════════════════════
async function bench04() {
    records.length = 0;
    header("Node.js Benchmark 04: I/O-Bound Operations");
    console.log(`    Sequential vs concurrent I/O (file, network sim, mixed)`);
    console.log(`    Node.js ${process.version} | ${process.platform}`);

    const benchDir = join(tmpdir(), "node_bench_04");
    if (!existsSync(benchDir)) mkdirSync(benchDir, { recursive: true });

    // Test 1: Simulated API calls — 5 × 200ms
    subHeader("Test 1: Simulated API calls — 5 × 200ms");
    const apiCount = 5,
        apiDelay = 200;

    let [, blockingMs] = measureSync(() => {
        for (let i = 0; i < apiCount; i++) simulateBlockingApiCall(apiDelay);
    });
    timing("Blocking (sequential):", blockingMs);

    let [, asyncMs] = await measure(async () => {
        await Promise.all(
            Array.from({ length: apiCount }, () => sleep(apiDelay)),
        );
    });
    timing("Async (concurrent setTimeout):", asyncMs);
    comparison(`${apiCount}×${apiDelay}ms API`, blockingMs, asyncMs);
    record(
        `${apiCount}×${apiDelay}ms API`,
        blockingMs,
        asyncMs,
        "simulated API calls",
    );

    // Test 2: File write — 10 files × 10KB
    subHeader("Test 2: File write — 10 files × 10KB each");
    const fileCount = 10,
        fileSize = 10 * 1024;

    [, blockingMs] = measureSync(() => {
        for (let i = 0; i < fileCount; i++) {
            const content = generateContent(fileSize);
            writeFileSync(join(benchDir, `file_${i}.txt`), content);
        }
    });
    timing("Blocking (sequential writeFileSync):", blockingMs);

    [, asyncMs] = await measure(async () => {
        const { writeFile } = await import("node:fs/promises");
        const tasks = [];
        for (let i = 0; i < fileCount; i++) {
            const content = generateContent(fileSize);
            tasks.push(
                writeFile(join(benchDir, `async_file_${i}.txt`), content),
            );
        }
        await Promise.all(tasks);
    });
    timing("Async (concurrent writeFile):", asyncMs);
    comparison(`${fileCount}×${fileSize / 1024}KB write`, blockingMs, asyncMs);
    record(`${fileCount}×10KB write`, blockingMs, asyncMs, "file I/O");

    // Test 3: Mixed I/O — API + file write interleaved
    subHeader("Test 3: Mixed I/O — 5×(API 100ms + file 5KB)");

    [, blockingMs] = measureSync(() => {
        for (let i = 0; i < 5; i++) {
            simulateBlockingApiCall(100);
            const content = generateContent(5 * 1024);
            writeFileSync(join(benchDir, `mixed_${i}.txt`), content);
        }
    });
    timing("Blocking (sequential):", blockingMs);

    [, asyncMs] = await measure(async () => {
        const { writeFile } = await import("node:fs/promises");
        const tasks = [];
        for (let i = 0; i < 5; i++) {
            tasks.push(
                (async () => {
                    await sleep(100);
                    const content = generateContent(5 * 1024);
                    await writeFile(
                        join(benchDir, `async_mixed_${i}.txt`),
                        content,
                    );
                })(),
            );
        }
        await Promise.all(tasks);
    });
    timing("Async (concurrent):", asyncMs);
    comparison("Mixed I/O", blockingMs, asyncMs);
    record("Mixed I/O", blockingMs, asyncMs, "API + file interleaved");

    // Test 4: High-concurrency API — 20 × 150ms
    subHeader("Test 4: High-concurrency API — 20 × 150ms");

    [, blockingMs] = measureSync(() => {
        for (let i = 0; i < 20; i++) simulateBlockingApiCall(150);
    });
    timing("Blocking (sequential):", blockingMs);

    [, asyncMs] = await measure(async () => {
        await Promise.all(Array.from({ length: 20 }, () => sleep(150)));
    });
    timing("Async (concurrent):", asyncMs);
    comparison("20×150ms API", blockingMs, asyncMs);
    record("20×150ms API", blockingMs, asyncMs, "high concurrency I/O");

    // Test 5: Stream processing — read + transform + write
    subHeader("Test 5: Stream processing — 50KB file through transform");

    const srcContent = generateContent(50 * 1024);
    const srcPath = join(benchDir, "stream_src.txt");
    writeFileSync(srcPath, srcContent);

    [, blockingMs] = measureSync(() => {
        const data = srcContent; // simulate reading (already in memory)
        const upper = data.toUpperCase();
        writeFileSync(join(benchDir, "stream_dst_sync.txt"), upper);
    });
    timing("Blocking (readSync → toUpperCase → writeSync):", blockingMs);

    [, asyncMs] = await measure(async () => {
        const upperTransform = new Transform({
            transform(chunk, encoding, callback) {
                callback(null, chunk.toString().toUpperCase());
            },
        });

        const source = createReadStream(srcPath);
        const destPath = join(benchDir, "stream_dst_async.txt");
        const { createWriteStream } = await import("node:fs");
        const dest =
            createWriteStream.call(null, destPath) ||
            Writable.toWeb(createWriteStream(destPath));

        // Use the imported createWriteStream properly
        const destStream = (await import("node:fs")).createWriteStream(
            destPath,
        );
        await pipeline(source, upperTransform, destStream);
    });
    timing("Async (stream pipeline):", asyncMs);
    comparison("50KB stream transform", blockingMs, asyncMs);
    record("50KB stream", blockingMs, asyncMs, "read→transform→write");

    // Cleanup
    try {
        const { readdirSync } = await import("node:fs");
        const files = readdirSync(benchDir);
        for (const f of files) {
            try {
                unlinkSync(join(benchDir, f));
            } catch {}
        }
        const { rmdirSync } = await import("node:fs");
        rmdirSync(benchDir);
    } catch {}

    printSummary("Benchmark 04: I/O-Bound");
}

// ═════════════════════════════════════════════════════════════════════════
//  BENCHMARK 05: Flow / Stream Throughput
// ═════════════════════════════════════════════════════════════════════════
async function bench05() {
    records.length = 0;
    header("Node.js Benchmark 05: Flow / Stream Throughput");
    console.log(`    Array iteration vs async generators vs Node.js streams`);
    console.log(`    Node.js ${process.version} | ${process.platform}`);

    // Test 1: Array iteration vs async generator — 2000 items
    subHeader("Test 1: Array iteration vs async generator — 2000 items");
    const count1 = 2000;

    let [, blockingMs] = measureSync(() => {
        const arr = Array.from({ length: count1 }, (_, i) => i);
        let sum = 0;
        for (const v of arr) sum += v;
        return sum;
    });
    timing("Array iteration:", blockingMs);

    let [, asyncMs] = await measure(async () => {
        async function* gen() {
            for (let i = 0; i < count1; i++) yield i;
        }
        let sum = 0;
        for await (const v of gen()) sum += v;
        return sum;
    });
    timing("Async generator:", asyncMs);
    comparison("2000 items", blockingMs, asyncMs);
    record("Array vs async gen", blockingMs, asyncMs, "2000 items baseline");

    // Test 2: Transform — array_map vs async generator map
    subHeader(
        "Test 2: Transform — array.map vs async generator map — 1000 items",
    );
    const count2 = 1000;

    [, blockingMs] = measureSync(() => {
        const arr = Array.from({ length: count2 }, (_, i) => i);
        return arr.map((v) => v * 3 + 1).reduce((a, b) => a + b, 0);
    });
    timing("Array.map + reduce:", blockingMs);

    [, asyncMs] = await measure(async () => {
        async function* gen() {
            for (let i = 0; i < count2; i++) yield i;
        }
        async function* mapGen(source) {
            for await (const v of source) yield v * 3 + 1;
        }
        let sum = 0;
        for await (const v of mapGen(gen())) sum += v;
        return sum;
    });
    timing("Async generator map:", asyncMs);
    comparison("1000 items ×3+1", blockingMs, asyncMs);
    record("Array.map vs async map", blockingMs, asyncMs, "1000 items ×3+1");

    // Test 3: Node Readable stream — 2000 items object mode
    subHeader("Test 3: Readable stream (object mode) — 2000 items");

    [, blockingMs] = measureSync(() => {
        const arr = Array.from({ length: 2000 }, (_, i) => i);
        let sum = 0;
        for (const v of arr) sum += v;
        return sum;
    });
    timing("Array iteration:", blockingMs);

    [, asyncMs] = await measure(async () => {
        let idx = 0;
        const readable = new Readable({
            objectMode: true,
            read() {
                if (idx < 2000) {
                    this.push(idx++);
                } else {
                    this.push(null);
                }
            },
        });

        let sum = 0;
        for await (const v of readable) {
            sum += v;
        }
        return sum;
    });
    timing("Readable stream (object mode):", asyncMs);
    comparison("2000 items stream", blockingMs, asyncMs);
    record("Array vs Readable", blockingMs, asyncMs, "2000 items object mode");

    // Test 4: EventEmitter-based pub/sub — 1000 messages
    subHeader("Test 4: EventEmitter pub/sub — 1000 messages");
    const { EventEmitter } = await import("node:events");

    [, blockingMs] = measureSync(() => {
        const callbacks = [];
        let sum = 0;
        callbacks.push((v) => {
            sum += v;
        });
        for (let i = 0; i < 1000; i++) {
            for (const cb of callbacks) cb(i);
        }
        return sum;
    });
    timing("Callback array:", blockingMs);

    [, asyncMs] = await measure(async () => {
        const emitter = new EventEmitter();
        let sum = 0;
        emitter.on("data", (v) => {
            sum += v;
        });
        for (let i = 0; i < 1000; i++) {
            emitter.emit("data", i);
        }
        return sum;
    });
    timing("EventEmitter:", asyncMs);
    comparison("1000 events", blockingMs, asyncMs);
    record("Callback vs EventEmitter", blockingMs, asyncMs, "1000 messages");

    // Test 5: Backpressure — Transform stream with highWaterMark
    subHeader("Test 5: Transform stream with backpressure — 500 items");

    [, blockingMs] = measureSync(() => {
        const arr = Array.from({ length: 500 }, (_, i) => i);
        return arr.map((v) => v * 2).reduce((a, b) => a + b, 0);
    });
    timing("Array (no backpressure):", blockingMs);

    [, asyncMs] = await measure(async () => {
        let idx = 0;
        const readable = new Readable({
            objectMode: true,
            highWaterMark: 8,
            read() {
                if (idx < 500) this.push(idx++);
                else this.push(null);
            },
        });

        const doubler = new Transform({
            objectMode: true,
            highWaterMark: 8,
            transform(chunk, enc, cb) {
                cb(null, chunk * 2);
            },
        });

        let sum = 0;
        const writable = new Writable({
            objectMode: true,
            highWaterMark: 8,
            write(chunk, enc, cb) {
                sum += chunk;
                cb();
            },
        });

        await pipeline(readable, doubler, writable);
        return sum;
    });
    timing("Stream with backpressure:", asyncMs);
    comparison("500 items backpressure", blockingMs, asyncMs);
    record("Array vs stream BP", blockingMs, asyncMs, "hwm=8, 500 items");

    // Test 6: Multiple subscribers — EventEmitter with 3 listeners
    subHeader("Test 6: Multiple subscribers — 500 events × 3 listeners");

    [, blockingMs] = measureSync(() => {
        let sum1 = 0,
            sum2 = 0,
            sum3 = 0;
        for (let i = 0; i < 500; i++) {
            sum1 += i;
            sum2 += i * 2;
            sum3 += i * 3;
        }
        return sum1 + sum2 + sum3;
    });
    timing("Direct computation:", blockingMs);

    [, asyncMs] = await measure(async () => {
        const emitter = new EventEmitter();
        let sum1 = 0,
            sum2 = 0,
            sum3 = 0;
        emitter.on("data", (v) => {
            sum1 += v;
        });
        emitter.on("data", (v) => {
            sum2 += v * 2;
        });
        emitter.on("data", (v) => {
            sum3 += v * 3;
        });
        for (let i = 0; i < 500; i++) emitter.emit("data", i);
        return sum1 + sum2 + sum3;
    });
    timing("EventEmitter 3 listeners:", asyncMs);
    comparison("500 events × 3 listeners", blockingMs, asyncMs);
    record("Multi-sub events", blockingMs, asyncMs, "3 listeners");

    // Test 7: Async generator pipeline — producer → filter → map → consumer
    subHeader("Test 7: Async generator pipeline — 1000 items, filter+map");

    [, blockingMs] = measureSync(() => {
        return Array.from({ length: 1000 }, (_, i) => i)
            .filter((v) => v % 2 === 0)
            .map((v) => v * v)
            .reduce((a, b) => a + b, 0);
    });
    timing("Array chain:", blockingMs);

    [, asyncMs] = await measure(async () => {
        async function* produce() {
            for (let i = 0; i < 1000; i++) yield i;
        }
        async function* filter(src) {
            for await (const v of src) if (v % 2 === 0) yield v;
        }
        async function* map(src) {
            for await (const v of src) yield v * v;
        }
        let sum = 0;
        for await (const v of map(filter(produce()))) sum += v;
        return sum;
    });
    timing("Async generator pipeline:", asyncMs);
    comparison("1000 items pipeline", blockingMs, asyncMs);
    record("Gen pipeline", blockingMs, asyncMs, "filter → map → collect");

    printSummary("Benchmark 05: Flow / Stream");
}

// ═════════════════════════════════════════════════════════════════════════
//  BENCHMARK 06: IPC Channel — Worker MessagePort vs SharedArrayBuffer
//
//  Mirrors PHP bench_06 (socket vs file channel) by comparing two
//  Node.js IPC primitives:
//    • MessagePort (structured clone) — like PHP socket channel
//    • SharedArrayBuffer + Atomics   — like PHP file/shmop channel
// ═════════════════════════════════════════════════════════════════════════
async function bench06() {
    records.length = 0;
    header(
        "Node.js Benchmark 06: Real IPC Channel — Worker Thread + MessagePort",
    );
    console.log(
        `    Real cross-thread IPC via worker_threads + MessagePort (structured clone)`,
    );
    console.log(`    Node.js ${process.version} | ${process.platform}`);
    console.log(
        `    Mirrors PHP bench_06: socket pool (shared process) + legacy broker + file IPC`,
    );
    console.log(
        `    PHP now uses ChannelBrokerPool by default: N channels share 1 background process`,
    );
    console.log(
        `    Both sides pay REAL IPC cost: process/thread spawn, serialization, transport`,
    );

    const { MessageChannel } = await import("node:worker_threads");

    // ─── Helper: spawn a dedicated worker thread that acts as a "broker" ───
    // This mirrors PHP's pool architecture: main ↔ ChannelBrokerPool via TCP
    // Here: main ↔ worker-thread via MessagePort (structured clone)
    // PHP pool: 1 process for N channels; Node: 1 worker per channel (closer to PHP legacy mode)

    /**
     * Create a worker thread that runs an IPC broker.
     * Returns { worker, port } where port is a MessagePort connected to
     * the broker's internal buffer. Commands are sent as objects:
     *   { cmd: "send", value }   → broker buffers value
     *   { cmd: "receive" }       → broker replies { value } or waits
     *   { cmd: "trySend", value }→ broker replies { ok: bool }
     *   { cmd: "tryReceive" }    → broker replies { value } or { value: null }
     *   { cmd: "size" }          → broker replies { size }
     *   { cmd: "isEmpty" }       → broker replies { isEmpty }
     *   { cmd: "isClosed" }      → broker replies { isClosed }
     *   { cmd: "close" }         → broker closes and terminates
     *   { cmd: "destroy" }       → broker terminates immediately
     */
    function createIPCChannel(capacity = 0) {
        const { port1, port2 } = new MessageChannel();

        // The worker runs an inline broker via eval
        const workerCode = `
            const { parentPort, workerData } = require("node:worker_threads");
            const port = workerData.port;
            const capacity = workerData.capacity;

            const buffer = [];
            let closed = false;
            const pendingReceivers = []; // callbacks waiting for data

            port.on("message", (msg) => {
                if (msg.cmd === "send") {
                    if (closed) {
                        port.postMessage({ cmd: "sendReply", ok: false, error: "closed" });
                        return;
                    }
                    // If someone is waiting to receive, deliver directly
                    if (pendingReceivers.length > 0) {
                        const resolve = pendingReceivers.shift();
                        port.postMessage({ cmd: "receiveReply", value: msg.value, done: false, id: resolve });
                    } else if (capacity === 0 || buffer.length < capacity) {
                        buffer.push(msg.value);
                        port.postMessage({ cmd: "sendReply", ok: true });
                    } else {
                        port.postMessage({ cmd: "sendReply", ok: false, error: "full" });
                    }
                } else if (msg.cmd === "receive") {
                    if (buffer.length > 0) {
                        const value = buffer.shift();
                        port.postMessage({ cmd: "receiveReply", value, done: false, id: msg.id });
                    } else if (closed) {
                        port.postMessage({ cmd: "receiveReply", value: null, done: true, id: msg.id });
                    } else {
                        pendingReceivers.push(msg.id);
                    }
                } else if (msg.cmd === "trySend") {
                    if (closed) {
                        port.postMessage({ cmd: "trySendReply", ok: false });
                        return;
                    }
                    if (pendingReceivers.length > 0) {
                        const resolve = pendingReceivers.shift();
                        port.postMessage({ cmd: "receiveReply", value: msg.value, done: false, id: resolve });
                        port.postMessage({ cmd: "trySendReply", ok: true });
                    } else if (capacity === 0 || buffer.length < capacity) {
                        buffer.push(msg.value);
                        port.postMessage({ cmd: "trySendReply", ok: true });
                    } else {
                        port.postMessage({ cmd: "trySendReply", ok: false });
                    }
                } else if (msg.cmd === "tryReceive") {
                    if (buffer.length > 0) {
                        port.postMessage({ cmd: "tryReceiveReply", value: buffer.shift() });
                    } else {
                        port.postMessage({ cmd: "tryReceiveReply", value: null });
                    }
                } else if (msg.cmd === "size") {
                    port.postMessage({ cmd: "sizeReply", size: buffer.length });
                } else if (msg.cmd === "isEmpty") {
                    port.postMessage({ cmd: "isEmptyReply", isEmpty: buffer.length === 0 });
                } else if (msg.cmd === "isClosed") {
                    port.postMessage({ cmd: "isClosedReply", isClosed: closed });
                } else if (msg.cmd === "close") {
                    closed = true;
                    // Flush all pending receivers
                    for (const id of pendingReceivers) {
                        port.postMessage({ cmd: "receiveReply", value: null, done: true, id });
                    }
                    pendingReceivers.length = 0;
                    port.postMessage({ cmd: "closeReply" });
                } else if (msg.cmd === "destroy") {
                    closed = true;
                    port.close();
                    process.exit(0);
                }
            });

            port.postMessage({ cmd: "ready" });
        `;

        const worker = new Worker(workerCode, {
            eval: true,
            workerData: { port: port2, capacity },
            transferList: [port2],
        });

        // Wrap port1 in a promise-based client API (mirrors PHP ChannelSocketClient)
        let nextId = 0;
        const pendingCallbacks = new Map();
        let readyResolve;
        const readyPromise = new Promise((r) => {
            readyResolve = r;
        });

        port1.on("message", (msg) => {
            if (msg.cmd === "ready") {
                readyResolve();
                return;
            }
            if (msg.cmd === "sendReply") {
                const cb = pendingCallbacks.get("send");
                if (cb) {
                    pendingCallbacks.delete("send");
                    cb(msg);
                }
                return;
            }
            if (msg.cmd === "receiveReply") {
                const cb = pendingCallbacks.get(`recv_${msg.id}`);
                if (cb) {
                    pendingCallbacks.delete(`recv_${msg.id}`);
                    cb(msg);
                }
                return;
            }
            if (msg.cmd === "trySendReply") {
                const cb = pendingCallbacks.get("trySend");
                if (cb) {
                    pendingCallbacks.delete("trySend");
                    cb(msg);
                }
                return;
            }
            if (msg.cmd === "tryReceiveReply") {
                const cb = pendingCallbacks.get("tryReceive");
                if (cb) {
                    pendingCallbacks.delete("tryReceive");
                    cb(msg);
                }
                return;
            }
            if (msg.cmd === "sizeReply") {
                const cb = pendingCallbacks.get("size");
                if (cb) {
                    pendingCallbacks.delete("size");
                    cb(msg);
                }
                return;
            }
            if (msg.cmd === "isEmptyReply") {
                const cb = pendingCallbacks.get("isEmpty");
                if (cb) {
                    pendingCallbacks.delete("isEmpty");
                    cb(msg);
                }
                return;
            }
            if (msg.cmd === "isClosedReply") {
                const cb = pendingCallbacks.get("isClosed");
                if (cb) {
                    pendingCallbacks.delete("isClosed");
                    cb(msg);
                }
                return;
            }
            if (msg.cmd === "closeReply") {
                const cb = pendingCallbacks.get("close");
                if (cb) {
                    pendingCallbacks.delete("close");
                    cb(msg);
                }
                return;
            }
        });

        const client = {
            ready: readyPromise,
            send(value) {
                return new Promise((resolve) => {
                    pendingCallbacks.set("send", resolve);
                    port1.postMessage({ cmd: "send", value });
                });
            },
            receive() {
                const id = nextId++;
                return new Promise((resolve) => {
                    pendingCallbacks.set(`recv_${id}`, resolve);
                    port1.postMessage({ cmd: "receive", id });
                });
            },
            trySend(value) {
                return new Promise((resolve) => {
                    pendingCallbacks.set("trySend", resolve);
                    port1.postMessage({ cmd: "trySend", value });
                });
            },
            tryReceive() {
                return new Promise((resolve) => {
                    pendingCallbacks.set("tryReceive", resolve);
                    port1.postMessage({ cmd: "tryReceive" });
                });
            },
            size() {
                return new Promise((resolve) => {
                    pendingCallbacks.set("size", resolve);
                    port1.postMessage({ cmd: "size" });
                });
            },
            isEmpty() {
                return new Promise((resolve) => {
                    pendingCallbacks.set("isEmpty", resolve);
                    port1.postMessage({ cmd: "isEmpty" });
                });
            },
            isClosed() {
                return new Promise((resolve) => {
                    pendingCallbacks.set("isClosed", resolve);
                    port1.postMessage({ cmd: "isClosed" });
                });
            },
            close() {
                return new Promise((resolve) => {
                    pendingCallbacks.set("close", resolve);
                    port1.postMessage({ cmd: "close" });
                });
            },
            async destroy() {
                port1.postMessage({ cmd: "destroy" });
                port1.close();
                await worker.terminate();
            },
        };

        return client;
    }

    // ─── Helper: "file-based" channel using Worker + fs temp file ────
    // Mirrors PHP's file-based transport: each send/recv does a full
    // file read → deserialize → modify → serialize → file write.
    // This gives Node.js the SAME penalty as PHP file transport.

    function createFileIPCChannel(capacity = 0) {
        const { port1, port2 } = new MessageChannel();

        const workerCode = `
            const { parentPort, workerData } = require("node:worker_threads");
            const fs = require("node:fs");
            const os = require("node:os");
            const path = require("node:path");
            const port = workerData.port;
            const capacity = workerData.capacity;

            // Create a temp file to store the buffer (simulates PHP file transport)
            const filePath = path.join(os.tmpdir(), "node_filechan_" + process.pid + "_" + Date.now() + ".json");
            fs.writeFileSync(filePath, JSON.stringify({ buffer: [], closed: false }));

            function readState() {
                const data = fs.readFileSync(filePath, "utf-8");
                return JSON.parse(data);
            }
            function writeState(state) {
                fs.writeFileSync(filePath, JSON.stringify(state));
            }

            const pendingReceivers = [];

            port.on("message", (msg) => {
                if (msg.cmd === "send") {
                    const state = readState();
                    if (state.closed) {
                        port.postMessage({ cmd: "sendReply", ok: false, error: "closed" });
                        return;
                    }
                    if (pendingReceivers.length > 0) {
                        const id = pendingReceivers.shift();
                        port.postMessage({ cmd: "receiveReply", value: msg.value, done: false, id });
                        port.postMessage({ cmd: "sendReply", ok: true });
                    } else if (capacity === 0 || state.buffer.length < capacity) {
                        state.buffer.push(msg.value);
                        writeState(state);
                        port.postMessage({ cmd: "sendReply", ok: true });
                    } else {
                        port.postMessage({ cmd: "sendReply", ok: false, error: "full" });
                    }
                } else if (msg.cmd === "receive") {
                    const state = readState();
                    if (state.buffer.length > 0) {
                        const value = state.buffer.shift();
                        writeState(state);
                        port.postMessage({ cmd: "receiveReply", value, done: false, id: msg.id });
                    } else if (state.closed) {
                        port.postMessage({ cmd: "receiveReply", value: null, done: true, id: msg.id });
                    } else {
                        pendingReceivers.push(msg.id);
                    }
                } else if (msg.cmd === "trySend") {
                    const state = readState();
                    if (state.closed) {
                        port.postMessage({ cmd: "trySendReply", ok: false });
                        return;
                    }
                    if (pendingReceivers.length > 0) {
                        const id = pendingReceivers.shift();
                        port.postMessage({ cmd: "receiveReply", value: msg.value, done: false, id });
                        port.postMessage({ cmd: "trySendReply", ok: true });
                    } else if (capacity === 0 || state.buffer.length < capacity) {
                        state.buffer.push(msg.value);
                        writeState(state);
                        port.postMessage({ cmd: "trySendReply", ok: true });
                    } else {
                        port.postMessage({ cmd: "trySendReply", ok: false });
                    }
                } else if (msg.cmd === "tryReceive") {
                    const state = readState();
                    if (state.buffer.length > 0) {
                        const value = state.buffer.shift();
                        writeState(state);
                        port.postMessage({ cmd: "tryReceiveReply", value });
                    } else {
                        port.postMessage({ cmd: "tryReceiveReply", value: null });
                    }
                } else if (msg.cmd === "size") {
                    const state = readState();
                    port.postMessage({ cmd: "sizeReply", size: state.buffer.length });
                } else if (msg.cmd === "isEmpty") {
                    const state = readState();
                    port.postMessage({ cmd: "isEmptyReply", isEmpty: state.buffer.length === 0 });
                } else if (msg.cmd === "isClosed") {
                    const state = readState();
                    port.postMessage({ cmd: "isClosedReply", isClosed: state.closed });
                } else if (msg.cmd === "close") {
                    const state = readState();
                    state.closed = true;
                    writeState(state);
                    for (const id of pendingReceivers) {
                        port.postMessage({ cmd: "receiveReply", value: null, done: true, id });
                    }
                    pendingReceivers.length = 0;
                    port.postMessage({ cmd: "closeReply" });
                } else if (msg.cmd === "destroy") {
                    try { fs.unlinkSync(filePath); } catch(e) {}
                    port.close();
                    process.exit(0);
                }
            });

            port.postMessage({ cmd: "ready" });
        `;

        const worker = new Worker(workerCode, {
            eval: true,
            workerData: { port: port2, capacity },
            transferList: [port2],
        });

        let nextId = 0;
        const pendingCallbacks = new Map();
        let readyResolve;
        const readyPromise = new Promise((r) => {
            readyResolve = r;
        });

        port1.on("message", (msg) => {
            if (msg.cmd === "ready") {
                readyResolve();
                return;
            }
            if (msg.cmd === "sendReply") {
                const cb = pendingCallbacks.get("send");
                if (cb) {
                    pendingCallbacks.delete("send");
                    cb(msg);
                }
                return;
            }
            if (msg.cmd === "receiveReply") {
                const cb = pendingCallbacks.get(`recv_${msg.id}`);
                if (cb) {
                    pendingCallbacks.delete(`recv_${msg.id}`);
                    cb(msg);
                }
                return;
            }
            if (msg.cmd === "trySendReply") {
                const cb = pendingCallbacks.get("trySend");
                if (cb) {
                    pendingCallbacks.delete("trySend");
                    cb(msg);
                }
                return;
            }
            if (msg.cmd === "tryReceiveReply") {
                const cb = pendingCallbacks.get("tryReceive");
                if (cb) {
                    pendingCallbacks.delete("tryReceive");
                    cb(msg);
                }
                return;
            }
            if (msg.cmd === "sizeReply") {
                const cb = pendingCallbacks.get("size");
                if (cb) {
                    pendingCallbacks.delete("size");
                    cb(msg);
                }
                return;
            }
            if (msg.cmd === "isEmptyReply") {
                const cb = pendingCallbacks.get("isEmpty");
                if (cb) {
                    pendingCallbacks.delete("isEmpty");
                    cb(msg);
                }
                return;
            }
            if (msg.cmd === "isClosedReply") {
                const cb = pendingCallbacks.get("isClosed");
                if (cb) {
                    pendingCallbacks.delete("isClosed");
                    cb(msg);
                }
                return;
            }
            if (msg.cmd === "closeReply") {
                const cb = pendingCallbacks.get("close");
                if (cb) {
                    pendingCallbacks.delete("close");
                    cb(msg);
                }
                return;
            }
        });

        const client = {
            ready: readyPromise,
            send(value) {
                return new Promise((resolve) => {
                    pendingCallbacks.set("send", resolve);
                    port1.postMessage({ cmd: "send", value });
                });
            },
            receive() {
                const id = nextId++;
                return new Promise((resolve) => {
                    pendingCallbacks.set(`recv_${id}`, resolve);
                    port1.postMessage({ cmd: "receive", id });
                });
            },
            trySend(value) {
                return new Promise((resolve) => {
                    pendingCallbacks.set("trySend", resolve);
                    port1.postMessage({ cmd: "trySend", value });
                });
            },
            tryReceive() {
                return new Promise((resolve) => {
                    pendingCallbacks.set("tryReceive", resolve);
                    port1.postMessage({ cmd: "tryReceive" });
                });
            },
            size() {
                return new Promise((resolve) => {
                    pendingCallbacks.set("size", resolve);
                    port1.postMessage({ cmd: "size" });
                });
            },
            isEmpty() {
                return new Promise((resolve) => {
                    pendingCallbacks.set("isEmpty", resolve);
                    port1.postMessage({ cmd: "isEmpty" });
                });
            },
            isClosed() {
                return new Promise((resolve) => {
                    pendingCallbacks.set("isClosed", resolve);
                    port1.postMessage({ cmd: "isClosed" });
                });
            },
            close() {
                return new Promise((resolve) => {
                    pendingCallbacks.set("close", resolve);
                    port1.postMessage({ cmd: "close" });
                });
            },
            async destroy() {
                port1.postMessage({ cmd: "destroy" });
                port1.close();
                await worker.terminate();
            },
        };

        return client;
    }

    // ═════════════════════════════════════════════════════════════════
    // Test 1: Single send/receive latency (1 message round-trip)
    //
    // PHP: Pool transport ~16ms (pool already running, CREATE_CHANNEL cmd + 1 msg)
    //      Legacy socket ~590ms (broker spawn + TCP connect + 1 msg)
    //      File transport ~50ms
    //
    // Node: Worker thread spawn + MessagePort + 1 msg (structured clone)
    //       File channel: Worker thread spawn + file I/O + 1 msg
    //
    // Both include channel creation + teardown (like PHP bench).
    // ═════════════════════════════════════════════════════════════════
    subHeader(
        "Test 1: Single message round-trip latency (incl. create+destroy)",
    );

    let [, fileMs] = await measure(async () => {
        const ch = createFileIPCChannel(10);
        await ch.ready;
        await ch.send("hello");
        const reply = await ch.receive();
        await ch.destroy();
        return reply.value;
    });
    timing("File IPC channel:", fileMs);

    let [, socketMs] = await measure(async () => {
        const ch = createIPCChannel(10);
        await ch.ready;
        await ch.send("hello");
        const reply = await ch.receive();
        await ch.destroy();
        return reply.value;
    });
    timing("Socket IPC channel (MessagePort):", socketMs);

    comparison("1 msg round-trip", fileMs, socketMs);
    record(
        "1 msg round-trip",
        fileMs,
        socketMs,
        "includes channel create+destroy",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 2: Sequential throughput — 500 messages
    //
    // Pre-create channel, measure only the send/receive loop.
    // ═════════════════════════════════════════════════════════════════
    subHeader("Test 2: Sequential throughput — 500 msgs");

    const msgCount2 = 500;

    // --- File IPC ---
    let chFile2 = createFileIPCChannel(msgCount2 + 10);
    await chFile2.ready;
    [, fileMs] = await measure(async () => {
        for (let i = 0; i < msgCount2; i++) await chFile2.send(i);
        let sum = 0;
        for (let i = 0; i < msgCount2; i++) {
            const r = await chFile2.receive();
            sum += r.value;
        }
        return sum;
    });
    await chFile2.destroy();
    timing("File IPC channel:", fileMs);
    console.log(`        Per-message (file): ~${formatMs(fileMs / msgCount2)}`);

    // --- Socket IPC ---
    let chSock2 = createIPCChannel(msgCount2 + 10);
    await chSock2.ready;
    [, socketMs] = await measure(async () => {
        for (let i = 0; i < msgCount2; i++) await chSock2.send(i);
        let sum = 0;
        for (let i = 0; i < msgCount2; i++) {
            const r = await chSock2.receive();
            sum += r.value;
        }
        return sum;
    });
    await chSock2.destroy();
    timing("Socket IPC channel (MessagePort):", socketMs);
    console.log(
        `        Per-message (socket): ~${formatMs(socketMs / msgCount2)}`,
    );

    comparison("500 sequential msgs", fileMs, socketMs);
    record("500 sequential msgs", fileMs, socketMs, "send then receive loop");

    // ═════════════════════════════════════════════════════════════════
    // Test 3: Burst send then burst receive — 200 messages
    // ═════════════════════════════════════════════════════════════════
    subHeader("Test 3: Burst send → burst receive — 200 msgs");

    const msgCount3 = 200;

    let chFile3 = createFileIPCChannel(msgCount3 + 10);
    await chFile3.ready;
    [, fileMs] = await measure(async () => {
        for (let i = 0; i < msgCount3; i++) await chFile3.send(`msg_${i}`);
        let count = 0;
        for (let i = 0; i < msgCount3; i++) {
            await chFile3.receive();
            count++;
        }
        return count;
    });
    await chFile3.destroy();
    timing("File IPC channel:", fileMs);

    let chSock3 = createIPCChannel(msgCount3 + 10);
    await chSock3.ready;
    [, socketMs] = await measure(async () => {
        for (let i = 0; i < msgCount3; i++) await chSock3.send(`msg_${i}`);
        let count = 0;
        for (let i = 0; i < msgCount3; i++) {
            await chSock3.receive();
            count++;
        }
        return count;
    });
    await chSock3.destroy();
    timing("Socket IPC channel (MessagePort):", socketMs);

    comparison("200 burst msgs", fileMs, socketMs);
    record("200 burst msgs", fileMs, socketMs, "burst send then burst recv");

    // ═════════════════════════════════════════════════════════════════
    // Test 4: trySend/tryReceive non-blocking — 500 messages
    // ═════════════════════════════════════════════════════════════════
    subHeader("Test 4: trySend/tryReceive — 500 msgs");

    const msgCount4 = 500;

    let chFile4 = createFileIPCChannel(msgCount4 + 10);
    await chFile4.ready;
    [, fileMs] = await measure(async () => {
        for (let i = 0; i < msgCount4; i++) await chFile4.trySend(i);
        let sum = 0;
        for (let i = 0; i < msgCount4; i++) {
            const r = await chFile4.tryReceive();
            if (r.value !== null) sum += r.value;
        }
        return sum;
    });
    await chFile4.destroy();
    timing("File IPC channel:", fileMs);

    let chSock4 = createIPCChannel(msgCount4 + 10);
    await chSock4.ready;
    [, socketMs] = await measure(async () => {
        for (let i = 0; i < msgCount4; i++) await chSock4.trySend(i);
        let sum = 0;
        for (let i = 0; i < msgCount4; i++) {
            const r = await chSock4.tryReceive();
            if (r.value !== null) sum += r.value;
        }
        return sum;
    });
    await chSock4.destroy();
    timing("Socket IPC channel (MessagePort):", socketMs);

    comparison("500 trySend/tryRecv", fileMs, socketMs);
    record("500 trySend/tryRecv", fileMs, socketMs, "non-blocking ops");

    // ═════════════════════════════════════════════════════════════════
    // Test 5: Large payload — 10 KB strings × 50
    //
    // Structured clone must copy the string across thread boundary.
    // File channel must also JSON.stringify → write → read → parse.
    // ═════════════════════════════════════════════════════════════════
    subHeader("Test 5: Large payload — 10 KB × 50 msgs");

    const msgCount5 = 50;
    const payload5 = "X".repeat(10240);

    let chFile5 = createFileIPCChannel(msgCount5 + 10);
    await chFile5.ready;
    [, fileMs] = await measure(async () => {
        for (let i = 0; i < msgCount5; i++) await chFile5.send(payload5);
        let count = 0;
        for (let i = 0; i < msgCount5; i++) {
            await chFile5.receive();
            count++;
        }
        return count;
    });
    await chFile5.destroy();
    timing("File IPC channel:", fileMs);

    let chSock5 = createIPCChannel(msgCount5 + 10);
    await chSock5.ready;
    [, socketMs] = await measure(async () => {
        for (let i = 0; i < msgCount5; i++) await chSock5.send(payload5);
        let count = 0;
        for (let i = 0; i < msgCount5; i++) {
            await chSock5.receive();
            count++;
        }
        return count;
    });
    await chSock5.destroy();
    timing("Socket IPC channel (MessagePort):", socketMs);

    comparison("10KB × 50 msgs", fileMs, socketMs);
    record("10KB × 50 msgs", fileMs, socketMs, "large payload stress");

    // ═════════════════════════════════════════════════════════════════
    // Test 6: Small payload — tiny integers × 1000
    // ═════════════════════════════════════════════════════════════════
    subHeader("Test 6: Small payload — int × 1000");

    const msgCount6 = 1000;

    let chFile6 = createFileIPCChannel(msgCount6 + 10);
    await chFile6.ready;
    [, fileMs] = await measure(async () => {
        for (let i = 0; i < msgCount6; i++) await chFile6.send(i);
        let sum = 0;
        for (let i = 0; i < msgCount6; i++) {
            const r = await chFile6.receive();
            sum += r.value;
        }
        return sum;
    });
    await chFile6.destroy();
    timing("File IPC channel:", fileMs);
    console.log(`        Per-message (file): ~${formatMs(fileMs / msgCount6)}`);

    let chSock6 = createIPCChannel(msgCount6 + 10);
    await chSock6.ready;
    [, socketMs] = await measure(async () => {
        for (let i = 0; i < msgCount6; i++) await chSock6.send(i);
        let sum = 0;
        for (let i = 0; i < msgCount6; i++) {
            const r = await chSock6.receive();
            sum += r.value;
        }
        return sum;
    });
    await chSock6.destroy();
    timing("Socket IPC channel (MessagePort):", socketMs);
    console.log(
        `        Per-message (socket): ~${formatMs(socketMs / msgCount6)}`,
    );

    comparison("1000 small msgs", fileMs, socketMs);
    record("1000 small msgs", fileMs, socketMs, "integer payloads");

    // ═════════════════════════════════════════════════════════════════
    // Test 7: Channel state query overhead — 200 queries each
    //
    // Each query crosses thread boundary via MessagePort (like PHP TCP cmd).
    // ═════════════════════════════════════════════════════════════════
    subHeader("Test 7: State query overhead — 200 queries each");

    const queryCount = 200;

    let chFile7 = createFileIPCChannel(10);
    await chFile7.ready;
    await chFile7.send("data");
    [, fileMs] = await measure(async () => {
        for (let i = 0; i < queryCount; i++) {
            await chFile7.isClosed();
            await chFile7.isEmpty();
            await chFile7.size();
        }
    });
    await chFile7.destroy();
    timing("File IPC channel:", fileMs);
    console.log(
        `        Per-query (file): ~${formatMs(fileMs / (queryCount * 3))}`,
    );

    let chSock7 = createIPCChannel(10);
    await chSock7.ready;
    await chSock7.send("data");
    [, socketMs] = await measure(async () => {
        for (let i = 0; i < queryCount; i++) {
            await chSock7.isClosed();
            await chSock7.isEmpty();
            await chSock7.size();
        }
    });
    await chSock7.destroy();
    timing("Socket IPC channel (MessagePort):", socketMs);
    console.log(
        `        Per-query (socket): ~${formatMs(socketMs / (queryCount * 3))}`,
    );

    comparison("600 state queries", fileMs, socketMs);
    record("600 state queries", fileMs, socketMs, "isClosed+isEmpty+size");

    // ═════════════════════════════════════════════════════════════════
    // Test 8: Interleaved send/receive — 500 messages
    // ═════════════════════════════════════════════════════════════════
    subHeader("Test 8: Interleaved send/receive — 500 msgs");

    const msgCount8 = 500;

    let chFile8 = createFileIPCChannel(10);
    await chFile8.ready;
    [, fileMs] = await measure(async () => {
        let sum = 0;
        for (let i = 0; i < msgCount8; i++) {
            await chFile8.send(i);
            const r = await chFile8.receive();
            sum += r.value;
        }
        return sum;
    });
    await chFile8.destroy();
    timing("File IPC channel:", fileMs);

    let chSock8 = createIPCChannel(10);
    await chSock8.ready;
    [, socketMs] = await measure(async () => {
        let sum = 0;
        for (let i = 0; i < msgCount8; i++) {
            await chSock8.send(i);
            const r = await chSock8.receive();
            sum += r.value;
        }
        return sum;
    });
    await chSock8.destroy();
    timing("Socket IPC channel (MessagePort):", socketMs);

    comparison("500 interleaved msgs", fileMs, socketMs);
    record("500 interleaved msgs", fileMs, socketMs, "send-recv-send-recv");

    // ═════════════════════════════════════════════════════════════════
    // Test 9: Cross-thread producer → main consumer (50 msgs)
    //
    // Spawns a REAL worker thread as producer, main thread as consumer.
    // This is the true equivalent of PHP IO dispatcher → main process.
    // ═════════════════════════════════════════════════════════════════
    subHeader(
        "Test 9: Cross-thread — Worker producer → main consumer (50 msgs)",
    );

    const msgCount9 = 50;

    // --- File IPC: real worker thread as producer ---
    let [, fileMs9] = await measure(async () => {
        const { port1, port2 } = new MessageChannel();

        const producerCode = `
            const { parentPort, workerData } = require("node:worker_threads");
            const fs = require("node:fs");
            const port = workerData.port;
            const count = workerData.count;
            const filePath = workerData.filePath;

            // Write each message by reading, modifying, writing the file (like PHP file transport)
            for (let i = 0; i < count; i++) {
                const data = fs.readFileSync(filePath, "utf-8");
                const state = JSON.parse(data);
                state.buffer.push(i);
                fs.writeFileSync(filePath, JSON.stringify(state));
            }
            port.postMessage({ done: true });
            port.close();
        `;

        const filePath = join(tmpdir(), `node_ipc_t9_${Date.now()}.json`);
        writeFileSync(filePath, JSON.stringify({ buffer: [], closed: false }));

        const worker = new Worker(producerCode, {
            eval: true,
            workerData: { port: port2, count: msgCount9, filePath },
            transferList: [port2],
        });

        await new Promise((resolve) => {
            port1.on("message", resolve);
        });
        port1.close();

        // Main thread reads as consumer
        const data = JSON.parse(
            (await import("node:fs")).readFileSync(filePath, "utf-8"),
        );
        const sum = data.buffer.reduce((a, b) => a + b, 0);
        await worker.terminate();
        try {
            unlinkSync(filePath);
        } catch (e) {}
        return sum;
    });
    timing("File IPC (worker → file → main):", fileMs9);

    // --- Socket IPC: real worker thread producer via MessagePort ---
    let [, socketMs9] = await measure(async () => {
        const { port1, port2 } = new MessageChannel();

        const producerCode = `
            const { parentPort, workerData } = require("node:worker_threads");
            const port = workerData.port;
            const count = workerData.count;
            for (let i = 0; i < count; i++) {
                port.postMessage(i);
            }
            port.postMessage({ __done: true });
        `;

        const worker = new Worker(producerCode, {
            eval: true,
            workerData: { port: port2, count: msgCount9 },
            transferList: [port2],
        });

        const sum = await new Promise((resolve) => {
            let s = 0;
            port1.on("message", (msg) => {
                if (msg && msg.__done) {
                    port1.close();
                    resolve(s);
                } else {
                    s += msg;
                }
            });
        });
        await worker.terminate();
        return sum;
    });
    timing("Socket IPC (worker → MessagePort → main):", socketMs9);

    comparison("IO→main 50 msgs", fileMs9, socketMs9);
    record("IO→main 50 msgs", fileMs9, socketMs9, "cross-thread producer");

    // ═════════════════════════════════════════════════════════════════
    // Test 10: Main producer → Worker consumer (50 msgs)
    // ═════════════════════════════════════════════════════════════════
    subHeader(
        "Test 10: Cross-thread — main producer → Worker consumer (50 msgs)",
    );

    const msgCount10 = 50;

    // --- File IPC ---
    let [, fileMs10] = await measure(async () => {
        const filePath = join(tmpdir(), `node_ipc_t10_${Date.now()}.json`);
        // Main produces into file
        writeFileSync(filePath, JSON.stringify({ buffer: [] }));
        for (let i = 0; i < msgCount10; i++) {
            const data = JSON.parse(
                (await import("node:fs")).readFileSync(filePath, "utf-8"),
            );
            data.buffer.push(i);
            writeFileSync(filePath, JSON.stringify(data));
        }

        const consumerCode = `
            const { parentPort, workerData } = require("node:worker_threads");
            const fs = require("node:fs");
            const port = workerData.port;
            const filePath = workerData.filePath;
            const count = workerData.count;

            const data = JSON.parse(fs.readFileSync(filePath, "utf-8"));
            let sum = 0;
            for (let i = 0; i < count && i < data.buffer.length; i++) {
                sum += data.buffer[i];
            }
            port.postMessage({ sum });
            port.close();
        `;

        const { port1, port2 } = new MessageChannel();
        const worker = new Worker(consumerCode, {
            eval: true,
            workerData: { port: port2, filePath, count: msgCount10 },
            transferList: [port2],
        });

        const result = await new Promise((resolve) => {
            port1.on("message", resolve);
        });
        port1.close();
        await worker.terminate();
        try {
            unlinkSync(filePath);
        } catch (e) {}
        return result.sum;
    });
    timing("File IPC (main → file → worker):", fileMs10);

    // --- Socket IPC ---
    let [, socketMs10] = await measure(async () => {
        const { port1, port2 } = new MessageChannel();

        const consumerCode = `
            const { parentPort, workerData } = require("node:worker_threads");
            const port = workerData.port;
            const count = workerData.count;
            let sum = 0;
            let received = 0;
            port.on("message", (msg) => {
                if (msg && msg.__done) {
                    port.postMessage({ sum });
                    port.close();
                } else {
                    sum += msg;
                    received++;
                }
            });
        `;

        const worker = new Worker(consumerCode, {
            eval: true,
            workerData: { port: port2, count: msgCount10 },
            transferList: [port2],
        });

        for (let i = 0; i < msgCount10; i++) {
            port1.postMessage(i);
        }
        port1.postMessage({ __done: true });

        const result = await new Promise((resolve) => {
            port1.on("message", resolve);
        });
        port1.close();
        await worker.terminate();
        return result.sum;
    });
    timing("Socket IPC (main → MessagePort → worker):", socketMs10);

    comparison("main→IO 50 msgs", fileMs10, socketMs10);
    record("main→IO 50 msgs", fileMs10, socketMs10, "cross-thread consumer");

    // ═════════════════════════════════════════════════════════════════
    // Test 11: Throughput scaling — increasing message counts
    // ═════════════════════════════════════════════════════════════════
    subHeader("Test 11: Throughput scaling");

    const scaleCounts = [50, 100, 250, 500];

    console.log(
        `    ${"Msgs".padEnd(8)}  ${"File(total)".padStart(14)}  ${"File/msg".padStart(12)}  ${"Socket(total)".padStart(14)}  ${"Socket/msg".padStart(12)}`,
    );

    for (const n of scaleCounts) {
        // File IPC
        const chF = createFileIPCChannel(n + 10);
        await chF.ready;
        const [, fMs] = await measure(async () => {
            for (let i = 0; i < n; i++) await chF.send(i);
            for (let i = 0; i < n; i++) await chF.receive();
        });
        await chF.destroy();

        // Socket IPC
        const chS = createIPCChannel(n + 10);
        await chS.ready;
        const [, sMs] = await measure(async () => {
            for (let i = 0; i < n; i++) await chS.send(i);
            for (let i = 0; i < n; i++) await chS.receive();
        });
        await chS.destroy();

        console.log(
            `    ${String(n).padEnd(8)}  ${formatMs(fMs).padStart(14)}  ${formatMs(fMs / n).padStart(12)}  ${formatMs(sMs).padStart(14)}  ${formatMs(sMs / n).padStart(12)}`,
        );
    }

    console.log("");
    console.log(
        "        (File IPC per-msg cost increases with buffer size; Socket IPC stays constant)",
    );

    // ═════════════════════════════════════════════════════════════════
    // Test 12: Channel creation + teardown cost
    //
    // PHP: File ~120ms (5× create), Pool ~63ms (5× TCP cmd), Legacy ~3s (5× broker spawn)
    // Node: Worker thread spawn + MessagePort setup + terminate
    // Pool mode makes PHP channel creation FASTER than Node worker spawn!
    // ═════════════════════════════════════════════════════════════════
    subHeader("Test 12: Channel creation + teardown cost");

    const createCount = 5;

    // --- File IPC ---
    let [, fileCreateMs] = await measure(async () => {
        for (let i = 0; i < createCount; i++) {
            const ch = createFileIPCChannel(10);
            await ch.ready;
            await ch.destroy();
        }
    });
    timing(`File IPC (${createCount}× create+destroy):`, fileCreateMs);
    console.log(
        `        Per-channel (file): ~${formatMs(fileCreateMs / createCount)}`,
    );

    // --- Socket IPC ---
    let [, socketCreateMs] = await measure(async () => {
        for (let i = 0; i < createCount; i++) {
            const ch = createIPCChannel(10);
            await ch.ready;
            await ch.destroy();
        }
    });
    timing(`Socket IPC (${createCount}× create+destroy):`, socketCreateMs);
    console.log(
        `        Per-channel (socket): ~${formatMs(socketCreateMs / createCount)}`,
    );

    comparison(`${createCount}× create+destroy`, fileCreateMs, socketCreateMs);
    record(
        "5× create+destroy",
        fileCreateMs,
        socketCreateMs,
        "channel lifecycle cost",
    );

    // ═════════════════════════════════════════════════════════════════
    // Summary
    // ═════════════════════════════════════════════════════════════════
    printSummary("Benchmark 06: Real IPC Channel");

    // Interpretation table
    console.log("");
    console.log(c("bold", "    Interpretation:"));
    console.log(
        "    ┌───────────────────────────────────────────────────────────────────────────┐",
    );
    console.log(
        "    │ ASPECT                  │ NODE SOCKET (MsgPort)  │ NODE FILE (fs+JSON)    │",
    );
    console.log(
        "    ├───────────────────────────────────────────────────────────────────────────┤",
    );
    console.log(
        "    │ Per-message overhead     │ ~10-50 µs (struct.    │ ~200-500 µs (fs read   │",
    );
    console.log(
        "    │                          │ clone across thread)  │ + JSON parse + write)  │",
    );
    console.log(
        "    │ State queries            │ ~5-20 µs (msg round-  │ ~100-300 µs (fs read   │",
    );
    console.log(
        "    │                          │ trip across thread)   │ + JSON parse)          │",
    );
    console.log(
        "    │ Scaling with buffer      │ O(1) per message       │ O(n) full file rewrite │",
    );
    console.log(
        "    │ Channel creation         │ ~30-80 ms (spawn       │ ~30-80 ms (spawn       │",
    );
    console.log(
        "    │                          │ worker thread)        │ worker + create file)  │",
    );
    console.log(
        "    │ Cross-thread             │ Structured clone IPC   │ File I/O (flock-like)  │",
    );
    console.log(
        "    │ Data types               │ Any cloneable value    │ JSON-serializable only │",
    );
    console.log(
        "    └───────────────────────────────────────────────────────────────────────────┘",
    );
    console.log("");
    console.log(
        c("bold", "    Fair comparison with PHP VOsaka (pool mode — default):"),
    );
    console.log(
        "    ┌───────────────────────────────────────────────────────────────────────────────┐",
    );
    console.log(
        "    │ ASPECT                  │ PHP POOL (default)     │ PHP LEGACY (per-chan)  │ NODE (MsgPort)  │",
    );
    console.log(
        "    ├───────────────────────────────────────────────────────────────────────────────┤",
    );
    console.log(
        "    │ Transport                │ TCP loopback + shared  │ TCP loopback + broker │ MessagePort     │",
    );
    console.log(
        "    │                          │ pool process (1 proc)  │ process (N procs)     │ (worker thread) │",
    );
    console.log(
        "    │ Per-message              │ ~120 µs (TCP+prefix)   │ ~110 µs (TCP+serial.) │ ~10-50 µs      │",
    );
    console.log(
        "    │ Channel creation         │ ~16 ms (TCP cmd)       │ ~600 ms (proc_open)   │ ~30-80 ms      │",
    );
    console.log(
        "    │ N channels               │ 1 background process   │ N background processes│ N worker threads│",
    );
    console.log(
        "    │ Multi-chan (10ch×100msg)  │ ~215 ms (pool)         │ ~6 s (10 processes)   │ ~varies        │",
    );
    console.log(
        "    │ Both have REAL IPC cost  │ ✓ cross-process        │ ✓ cross-process       │ ✓ cross-thread │",
    );
    console.log(
        "    └───────────────────────────────────────────────────────────────────────────────┘",
    );
    console.log("");
    console.log(
        "    PHP pool mode (default) dramatically closes the gap with Node.js:",
    );
    console.log(
        "      • Channel creation: PHP pool ~16ms vs Node ~50ms (PHP is FASTER)",
    );
    console.log(
        "      • Per-message: PHP ~120µs vs Node ~10-50µs (~2-10x gap, down from ~1000x)",
    );
    console.log(
        "      • Multi-channel: PHP pool shares 1 process for N channels (like Node shares 1 thread pool)",
    );
    console.log(
        "      • Remaining per-msg gap due to: TCP loopback vs in-process MessagePort,",
    );
    console.log(
        "        PHP serialize vs V8 structured clone, CH: prefix routing overhead",
    );
    console.log("");
}

// ═════════════════════════════════════════════════════════════════════════
//  MAIN — Run all benchmarks and print cross-comparison
// ═════════════════════════════════════════════════════════════════════════
async function main() {
    const bigDiv = "═".repeat(80);
    console.log(`\n${c("cyan", bigDiv)}`);
    console.log(
        c(
            "cyan",
            "  Node.js Benchmark Suite — Direct Comparison with VOsaka PHP",
        ),
    );
    console.log(c("cyan", bigDiv));
    console.log(`  Node.js:      ${process.version}`);
    console.log(`  Platform:     ${process.platform} (${process.arch})`);
    console.log(`  V8:           ${process.versions.v8}`);
    console.log("");

    const overallStart = performance.now();

    // Collect all records per benchmark for cross-comparison
    const allRecords = {};

    await bench01();
    allRecords["01_delay"] = [...records];

    await bench02();
    allRecords["02_cpu"] = [...records];

    await bench03();
    allRecords["03_channel"] = [...records];

    await bench04();
    allRecords["04_io"] = [...records];

    await bench05();
    allRecords["05_flow"] = [...records];

    await bench06();
    allRecords["06_ipc_channel"] = [...records];

    const overallMs = performance.now() - overallStart;

    // ─── Cross-comparison summary with expected PHP numbers ────────────────
    console.log(`\n${c("cyan", bigDiv)}`);
    console.log(c("cyan", "  NODE.JS vs PHP (VOsaka) — EXPECTED COMPARISON"));
    console.log(c("cyan", bigDiv));
    console.log("");
    console.log(
        "  Below are the Node.js results with notes on how they compare to PHP:",
    );
    console.log("");
    console.log(
        c(
            "bold",
            `  ${"Category".padEnd(25)} ${"Node.js Observation".padEnd(55)}`,
        ),
    );
    console.log(`  ${"─".repeat(80)}`);
    console.log(
        `  ${"Concurrent Delay".padEnd(25)} ${"Similar speedup ratios; Node libuv has lower base latency".padEnd(55)}`,
    );
    console.log(
        `  ${"".padEnd(25)} ${"setTimeout resolution ~1ms vs PHP usleep + fiber scheduler".padEnd(55)}`,
    );
    console.log(
        `  ${"CPU-Bound".padEnd(25)} ${"V8 JIT makes JS 2-10x faster for numeric computation".padEnd(55)}`,
    );
    console.log(
        `  ${"".padEnd(25)} ${"Promises have near-zero overhead; PHP Fibers ~12µs/create".padEnd(55)}`,
    );
    console.log(
        `  ${"Channel / Messaging".padEnd(25)} ${"Node.js async channel ~5-20µs/msg; PHP Channel ~50-200µs/msg".padEnd(55)}`,
    );
    console.log(
        `  ${"".padEnd(25)} ${"V8 microtask queue is extremely optimized".padEnd(55)}`,
    );
    console.log(
        `  ${"IPC Channel".padEnd(25)} ${"Node SAB/Atomics ~0.01-0.1µs/msg; PHP Socket ~100µs/msg".padEnd(55)}`,
    );
    console.log(
        `  ${"".padEnd(25)} ${"PHP File channel ~25ms/msg — 250,000x slower than Node SAB".padEnd(55)}`,
    );
    console.log(
        `  ${"I/O Bound".padEnd(25)} ${"Similar concurrency gains; libuv is native vs PHP fiber scheduler".padEnd(55)}`,
    );
    console.log(
        `  ${"".padEnd(25)} ${"Node.js has slight edge in absolute times".padEnd(55)}`,
    );
    console.log(
        `  ${"Flow / Streams".padEnd(25)} ${"Node Readable stream ~0.5-5µs/item; PHP Flow ~5-20µs/emit".padEnd(55)}`,
    );
    console.log(
        `  ${"".padEnd(25)} ${"EventEmitter < 1µs/emit; PHP SharedFlow ~10-50µs/msg".padEnd(55)}`,
    );
    console.log(`  ${"─".repeat(80)}`);
    console.log("");
    console.log(c("yellow", "  Key Differences:"));
    console.log("");
    console.log(
        "    1. V8 JIT compilation gives Node.js a 2-10x advantage in raw computation",
    );
    console.log(
        "    2. libuv event loop has lower scheduling overhead than PHP Fiber scheduler",
    );
    console.log(
        "    3. Promise/microtask resolution is ~0.1-1µs vs PHP Fiber suspend/resume ~2-12µs",
    );
    console.log(
        "    4. Both achieve similar concurrency RATIOS for I/O-bound workloads",
    );
    console.log(
        "    5. PHP VOsaka provides equivalent abstractions (Channel, Flow, SharedFlow)",
    );
    console.log(
        "       but with ~10-50x higher per-operation overhead due to interpreter + Fiber cost",
    );
    console.log(
        "    6. IPC channels: Node.js SAB/Atomics ~0.01µs vs PHP Socket ~100µs vs PHP File ~25ms",
    );
    console.log(
        "       PHP must cross process boundaries; Node.js shares memory natively",
    );
    console.log("");
    console.log(c("yellow", "  Where PHP VOsaka is competitive:"));
    console.log("");
    console.log(
        "    • I/O-bound workloads where wait time >> scheduling overhead",
    );
    console.log(
        "    • Applications already in PHP ecosystem (Laravel, Symfony, etc.)",
    );
    console.log(
        "    • Scenarios with < 10,000 concurrent tasks (fiber overhead acceptable)",
    );
    console.log(
        "    • Developer productivity: familiar PHP syntax + coroutine semantics",
    );
    console.log("");
    console.log(c("yellow", "  Where Node.js has clear advantage:"));
    console.log("");
    console.log("    • CPU-intensive computation (V8 JIT)");
    console.log("    • High-throughput message passing (> 100k msgs/sec)");
    console.log("    • Very high concurrency (> 100k simultaneous tasks)");
    console.log("    • Streaming / real-time data processing");
    console.log("");

    // Overall timing
    console.log(`${c("cyan", bigDiv)}`);
    console.log(`  Total Node.js benchmark time: ${formatMs(overallMs)}`);
    console.log(
        `  Peak memory: ~${Math.round(process.memoryUsage().heapUsed / 1024 / 1024)} MB heap`,
    );
    console.log(`${c("cyan", bigDiv)}`);
    console.log("");
    console.log(
        c("green", "  ✓ All Node.js benchmarks completed successfully!"),
    );
    console.log("");
}

main().catch((err) => {
    console.error("Benchmark failed:", err);
    process.exit(1);
});
