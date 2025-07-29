<?php

use vosaka\foroutines\Delay;
use vosaka\foroutines\Launch;
use vosaka\foroutines\RunBlocking;

require '../vendor/autoload.php';

if (__FILE__ === realpath($_SERVER['SCRIPT_FILENAME'])) {
    RunBlocking::new(function () {
        Launch::new(function () {
            Delay::new(2000);
            var_dump('World1');
        });
        Launch::new(function () {
            Delay::new(1000);
            var_dump('World2');
        });
        var_dump('Hello,');
    });
}
