<?php

use vosaka\foroutines\Delay;
use vosaka\foroutines\Dispatchers;
use vosaka\foroutines\RunBlocking;

use function vosaka\foroutines\main;

require_once '../vendor/autoload.php';

class Test6
{
    public function run()
    {
        $this->test1();
        $this->test2();
        $this->test3();
        $this->test4();
        $this->test5();
    }

    public function test1()
    {
        echo "Test 1 executed\n";
    }

    public function test2()
    {
        echo "Test 2 executed\n";
    }

    public function test3()
    {
        echo "Test 3 executed\n";
    }

    public function test4()
    {
        echo "Test 4 executed\n";
    }

    public function test5()
    {
        echo "Test 5 executed\n";
    }
}

main(function () {
    RunBlocking::new(function () {
        $test = new Test6();
        $test->run();
        Delay::new(1000);
    }, Dispatchers::IO);

    Delay::new(1000);
});
