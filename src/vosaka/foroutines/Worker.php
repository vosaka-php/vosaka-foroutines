<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Closure;

/**
 * Worker class for running closures asynchronously.
 * This class is used to encapsulate a closure and run it in a separate process.
 * It generates a unique ID for each worker instance.
 */
final class Worker
{
    public int $id;

    public function __construct(public Closure $closure)
    {
        $this->id = mt_rand(1, 1000000) + time();
    }

    /**
     * Runs the closure in a separate process and returns an Async instance.
     *
     * @return Async
     */
    public function run(): Async
    {
        $process = new Process();
        return $process->run($this->closure);
    }
}
