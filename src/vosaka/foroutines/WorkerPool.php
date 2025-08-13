<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Closure;
use Exception;

/**
 * WorkerPool class for managing a pool of workers that can run closures asynchronously.
 * This class allows you to add closures to a pool and run them concurrently with a limit on the number of concurrent workers.
 */
final class WorkerPool
{
    /**
     * @var Worker[]
     */
    private static array $workers = [];
    private static array $returns = [];
    private static int $running = 0;
    private static int $poolSize = 4;

    public function __construct() {}

    /**
     * Checks if the worker pool is empty.
     */
    public static function isEmpty(): bool
    {
        return empty(self::$workers);
    }

    /**
     * Sets the maximum number of workers that can run concurrently.
     *
     * @param int $size The maximum number of workers.
     * @throws Exception if the size is less than or equal to 0.
     */
    public static function setPoolSize(int $size): void
    {
        if ($size <= 0) {
            throw new Exception('Pool size must be greater than 0.');
        }
        self::$poolSize = $size;
    }

    /**
     * Gets the current pool size.
     *
     * @return int The current pool size.
     */
    public static function add(Closure $closure): int
    {
        $worker = new Worker(CallableUtils::makeCallableForThread($closure, get_included_files()));
        self::$workers[$worker->id] = $worker;
        return $worker->id;
    }

    /**
     * Adds a closure to the worker pool and returns an Async instance that can be used to wait for the result.
     *
     * @param Closure $closure The closure to run asynchronously.
     * @return Async An Async instance that will return the result of the closure when it completes.
     */
    public static function addAsync(Closure $closure): Async
    {
        $id = self::add(CallableUtils::makeCallableForThread($closure, get_included_files()));
        return Async::new(function () use ($id) {
            $result = self::waitForResult($id);
            return $result->wait();
        });
    }

    private static function waitForResult(int $id): Async
    {
        return Async::new(function () use ($id) {
            while (!isset(self::$returns[$id])) {
                Pause::new();
            }
            $result = self::$returns[$id];
            unset(self::$returns[$id]);
            return $result;
        });
    }

    /**
     * Runs the workers in the pool concurrently, respecting the pool size limit.
     * This method will start workers until the pool size limit is reached or there are no more workers to run.
     */
    public static function run(): void
    {
        if (empty(self::$workers)) {
            return;
        }

        if (self::$running >= self::$poolSize) {
            return;
        }

        foreach (self::$workers as $id => $worker) {
            if (self::$running >= self::$poolSize) {
                break;
            }

            Launch::new(function () use ($worker, $id) {
                try {
                    self::$running++;
                    $result = $worker->run()->wait();
                    self::$returns[$id] = $result;
                } catch (Exception $e) {
                    self::$returns[$id] = 'Error: ' . $e->getMessage();
                } finally {
                    self::$running--;
                }
            });

            unset(self::$workers[$id]);
        }
    }
}
