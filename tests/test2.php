<?php

use vosaka\foroutines\Process;
use vosaka\foroutines\RunBlocking;

require '../vendor/autoload.php';

// Must be run in the main thread
// If you dont make this check, the code IO will cause memory leak
if (__FILE__ === realpath($_SERVER['SCRIPT_FILENAME'])) {
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
}
