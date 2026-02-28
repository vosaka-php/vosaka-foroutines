<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Closure;

/**
 * Worker class for running closures asynchronously.
 * This class is used to encapsulate a closure and run it in a separate process.
 * It generates a unique ID for each worker instance.
 *
 * When pcntl_fork() is available (Linux/macOS), the worker uses ForkProcess
 * for minimal overhead (~1-5ms per task). On Windows or when pcntl is not
 * available, it falls back to the original Process class which spawns a
 * new PHP interpreter via symfony/process (~50-200ms per task).
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
     * Strategy selection:
     *   1. If pcntl_fork() is available (Linux/macOS with pcntl + shmop extensions),
     *      uses ForkProcess which forks the current process directly. The forked
     *      child inherits the entire parent memory space (copy-on-write), so there
     *      is no process bootstrap cost, no Composer autoload overhead, and no
     *      closure serialization round-trip.
     *
     *   2. Otherwise (Windows, or pcntl not available), falls back to the original
     *      Process class which serializes the closure via SerializableClosure,
     *      writes it to shmop, and spawns `php background_processor_file.php`
     *      via symfony/process.
     *
     * @return Async An Async instance that resolves to the closure's return value.
     */
    public function run(): Async
    {
        // if (ForkProcess::isForkAvailable()) {
        //     $fork = new ForkProcess();
        //     return $fork->run($this->closure);
        // }

        $process = new Process();
        return $process->run($this->closure);
    }
}
