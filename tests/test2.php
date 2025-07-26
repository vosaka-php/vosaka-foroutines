<?php

use vosaka\foroutines\Process;
use vosaka\foroutines\RunBlocking;

require '../vendor/autoload.php';

RunBlocking::new(function () {
    $process = new Process();

    $arr = [
        'a' => 1,
        'b' => 2,
        'c' => 3,
    ];

    $result = $process->run(function () use ($arr) {
        return $arr;
    })->wait();
    var_dump('Result from process:', $result);
});
