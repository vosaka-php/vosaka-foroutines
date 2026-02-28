<?php

use vosaka\foroutines\Delay;
use vosaka\foroutines\Dispatchers;
use vosaka\foroutines\Launch;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Thread;

use function vosaka\foroutines\main;

require "../vendor/autoload.php";

class Test
{
    public $arr = [];
    public function __construct()
    {
        var_dump("Test class created");
    }
}

main(function () {
    $class = new Test();
    $class->arr[] = "Hello, World!";
    RunBlocking::new(function () use ($class) {
        Launch::new(function () use ($class) {
            Delay::new(2000);
            var_dump("World1");

            var_dump($class->arr);
        }, Dispatchers::IO);
        Launch::new(function () {
            Delay::new(1000);
            var_dump("World2");
        });
        var_dump("Hello,");

        Thread::wait();
    }, Dispatchers::IO);

    Thread::wait();
});
