<?php

require "../vendor/autoload.php";

use vosaka\foroutines\{RuntimeFiberPool, Launch, RunBlocking, Pause};

use function vosaka\foroutines\main;

main(function () {
    RuntimeFiberPool::configure(initialSize: 4, maxSize: 16);

    RunBlocking::new(function () {
        $jobs = [];
        for ($i = 0; $i < 5; $i++) {
            $idx = $i;
            $jobs[] = Launch::new(function () use ($idx) {
                Pause::force();
                return $idx;
            });
        }

        echo "Before joins: ac=" .
            Launch::$activeCount .
            " q=" .
            count(Launch::$queue) .
            PHP_EOL;

        foreach ($jobs as $i => $job) {
            $result = $job->join();
        }
        echo "After joins: ac=" .
            Launch::$activeCount .
            " q=" .
            count(Launch::$queue) .
            PHP_EOL;
    });
    echo "Done!";
});
