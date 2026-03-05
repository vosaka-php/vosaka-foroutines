<?php

require "../vendor/autoload.php";

use vosaka\foroutines\Launch;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Delay;
use vosaka\foroutines\Thread;
use vosaka\foroutines\RuntimeFiberPool;

echo "=== Debug: trace tasks 17-19 ===\n";

RunBlocking::new(function () {
    $done = [];
    for ($i = 0; $i < 20; $i++) {
        $idx = $i;
        Launch::new(function () use ($idx, &$done) {
            Delay::new(100);
            $done[$idx] = true;
            echo "  Task $idx done (poolManaged=" .
                (Launch::isPooledMode() ? "yes" : "no") .
                ")\n";
        });
    }

    // Print pool stats right after enqueueing all tasks
    if (RuntimeFiberPool::isBooted()) {
        $s = RuntimeFiberPool::getInstance()->stats();
        echo "Pool after enqueue: size={$s["currentSize"]} idle={$s["idleCount"]} cooperative={$s["cooperativeActive"]} pending={$s["pendingTasks"]}\n";
    }
    echo "Launch queue=" .
        count(Launch::$queue) .
        " activeCount=" .
        Launch::$activeCount .
        "\n";

    Thread::await();
    echo "Thread::await() returned, done=" . count($done) . "/20\n";
});

echo "RunBlocking done\n";
if (RuntimeFiberPool::isBooted()) {
    $s = RuntimeFiberPool::getInstance()->stats();
    echo "Final pool: submitted={$s["totalSubmitted"]} completed={$s["totalCompleted"]} spawned={$s["totalSpawned"]} recycled={$s["totalRecycled"]}\n";
    echo "Final pool: cooperative={$s["cooperativeActive"]} pending={$s["pendingTasks"]}\n";
}
echo "Final launch: queue=" .
    count(Launch::$queue) .
    " activeCount=" .
    Launch::$activeCount .
    "\n";
