<?php

/**
 * Test: Dispatchers::IO — class/object sharing across worker processes
 *
 * Demonstrates that user-defined objects captured by closures can be
 * correctly serialized into IO worker processes and that their state
 * is preserved (deserialized) on the other side.
 *
 * Each Dispatchers::IO task runs in a separate child process via
 * WorkerPool. The closures are simple — they receive the captured
 * object, read/use its state, and return a result. The parent
 * process drives everything with Thread::await().
 *
 * Expected output:
 *   string(18) "Test class created"
 *   string(6) "Hello,"
 *   string(6) "World2"
 *   string(6) "World1"
 *   array(1) {
 *     [0]=>
 *     string(13) "Hello, World!"
 *   }
 *   string(26) "All IO dispatcher tests OK"
 */

require "../vendor/autoload.php";

use vosaka\foroutines\Async;
use vosaka\foroutines\Dispatchers;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Thread;

use function vosaka\foroutines\main;

class Test
{
    public array $arr = [];

    public function __construct()
    {
        var_dump("Test class created");
    }
}

main(function () {
    $class = new Test();
    $class->arr[] = "Hello, World!";

    RunBlocking::new(function () use ($class) {
        // IO task 1: simulates a longer-running task (2s worth of work),
        // reads the captured object's state and returns it.
        $async1 = Async::new(function () use ($class) {
            // In a real scenario this would be a blocking I/O call
            // (file read, HTTP request, database query, etc.)
            sleep(2);
            return [
                "message" => "World1",
                "arr" => $class->arr,
            ];
        }, Dispatchers::IO);

        // IO task 2: simulates a shorter-running task (1s)
        $async2 = Async::new(function () {
            sleep(1);
            return "World2";
        }, Dispatchers::IO);

        // Print "Hello," immediately — this runs in the parent process
        // while the two IO tasks are executing concurrently in workers.
        var_dump("Hello,");

        // Await the shorter task first (it finishes sooner)
        $result2 = $async2->await();
        var_dump($result2);

        // Await the longer task
        $result1 = $async1->await();
        var_dump($result1["message"]);
        var_dump($result1["arr"]);

    });


    var_dump("All IO dispatcher tests OK");
});
