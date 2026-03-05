<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  FiberPool implementation
// ═══════════════════════════════════════════════════════════════
class FiberPool
{
    private SplStack $idle;
    public int $created = 0;
    public int $reused = 0;

    public function __construct(private int $maxSize = 64)
    {
        $this->idle = new SplStack();
    }

    public function run(callable $task): mixed
    {
        if (!$this->idle->isEmpty()) {
            $fiber = $this->idle->pop();
            $this->reused++;
        } else {
            $fiber = new Fiber(function (): void {
                $task = Fiber::suspend('ready');
                while (true) {
                    $result = $task();
                    $task = Fiber::suspend($result);
                }
            });
            $fiber->start();
            $this->created++;
        }

        $result = $fiber->resume($task);

        if ($fiber->isSuspended()) {
            if ($this->idle->count() < $this->maxSize) {
                $this->idle->push($fiber);
            }
        }

        return $result;
    }
}

// ═══════════════════════════════════════════════════════════════
//  Helpers
// ═══════════════════════════════════════════════════════════════
function now(): float
{
    return hrtime(true) / 1e6;
}
function sep(): void
{
    echo str_repeat('─', 56) . "\n";
}
function line(string $k, string $v, string $color = ''): void
{
    $reset = $color ? "\033[0m" : '';
    printf("  %-28s %s%s%s\n", $k, $color, $v, $reset);
}

// The "work" each fiber does — intentionally cheap to isolate scheduler cost
$work = static function (int $n): int {
    $sum = 0;
    for ($i = 1; $i <= 20; $i++)
        $sum += $i * $n;
    return $sum;
};

const N = 5000;

echo "\n\033[1;37m PHP Fiber Benchmark — N=" . N . "\033[0m\n";
echo " PHP " . PHP_VERSION . "  |  " . date('H:i:s') . "\n\n";

// ═══════════════════════════════════════════════════════════════
//  ROUND 1 — Raw Fiber spawn
// ═══════════════════════════════════════════════════════════════
sep();
echo "\033[1;31m  [A] Raw Fiber — new Fiber() every call\033[0m\n";
sep();

// warm-up
for ($i = 0; $i < 20; $i++) {
    $f = new Fiber(function () use ($work, $i): void {
        Fiber::suspend(($work)($i)); });
    $f->start();
}
gc_collect_cycles();

$memBefore = memory_get_usage();
$t0 = now();

$rawResults = [];
for ($i = 0; $i < N; $i++) {
    $f = new Fiber(function () use ($work, $i): void {
        Fiber::suspend(($work)($i));
    });
    $rawResults[] = $f->start();   // runs until Fiber::suspend()
}

$rawMs = now() - $t0;
$rawMem = memory_get_usage() - $memBefore;
$rawOps = (int) round(N / ($rawMs / 1000));

line("Fibers created", N . " / " . N);
line("Elapsed", round($rawMs, 3) . " ms");
line("Throughput", number_format($rawOps) . " ops/sec");
line("Heap delta", number_format($rawMem) . " bytes");
line("Sample result[0]", (string) $rawResults[0]);
echo "\n";

// ═══════════════════════════════════════════════════════════════
//  ROUND 2 — FiberPool
// ═══════════════════════════════════════════════════════════════
sep();
echo "\033[1;32m  [B] FiberPool — reuse idle Fibers\033[0m\n";
sep();

// warm-up
$warmPool = new FiberPool(64);
for ($i = 0; $i < 20; $i++)
    $warmPool->run(fn() => ($work)($i));
unset($warmPool);
gc_collect_cycles();

$pool = new FiberPool(64);
$memBefore = memory_get_usage();
$t0 = now();

$poolResults = [];
for ($i = 0; $i < N; $i++) {
    $poolResults[] = $pool->run(fn() => ($work)($i));
}

$poolMs = now() - $t0;
$poolMem = memory_get_usage() - $memBefore;
$poolOps = (int) round(N / ($poolMs / 1000));

line("Fibers created", $pool->created . " / " . N);
line("Fibers reused", $pool->reused . " / " . N);
line("Elapsed", round($poolMs, 3) . " ms");
line("Throughput", number_format($poolOps) . " ops/sec");
line("Heap delta", number_format($poolMem) . " bytes");
line("Sample result[0]", (string) $poolResults[0]);
echo "\n";

// ═══════════════════════════════════════════════════════════════
//  COMPARISON
// ═══════════════════════════════════════════════════════════════
sep();
echo "\033[1;37m  COMPARISON\033[0m\n";
sep();

$speedup = $rawMs / max($poolMs, 0.001);
$winner = $speedup >= 1.0 ? 'Pool' : 'Raw';
$winnerColor = $speedup >= 1.0 ? "\033[1;32m" : "\033[1;31m";
$ratio = $speedup >= 1.0 ? $speedup : (1 / $speedup);

line("Raw elapsed", round($rawMs, 3) . " ms");
line("Pool elapsed", round($poolMs, 3) . " ms");
line("Raw ops/sec", number_format($rawOps));
line("Pool ops/sec", number_format($poolOps));
printf("  %-28s %s%.2f× faster\033[0m\n", "Winner ($winner)", $winnerColor, $ratio);

$memWinner = $poolMem <= $rawMem ? 'Pool' : 'Raw';
printf("  %-28s %s (Δ %+d bytes)\n",
    "Memory winner",
    $memWinner,
    $poolMem - $rawMem
);

sep();
echo "\n";