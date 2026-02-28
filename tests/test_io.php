<?php

use vosaka\foroutines\Delay;
use vosaka\foroutines\Dispatchers;
use vosaka\foroutines\Launch;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Thread;

use function vosaka\foroutines\main;

require '../vendor/autoload.php';

main(function () {
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

        Thread::wait();
    }, Dispatchers::IO);

    Thread::wait();
});
