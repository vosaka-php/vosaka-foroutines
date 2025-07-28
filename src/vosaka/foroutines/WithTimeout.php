<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Fiber;
use Exception;
use RuntimeException;

final class WithTimeout
{
    /**
     * Runs a Foroutine with specified timeout in milliseconds.
     * Throws RuntimeException if timeout is exceeded.
     *
     * @param int $timeoutMs Timeout in milliseconds
     * @param callable $callable The function to execute in Foroutine 
     * @return mixed Result of the block execution
     * @throws RuntimeException
     */
    public static function new(int $timeoutMs, callable $callable): mixed
    {
        if ($timeoutMs <= 0) {
            throw new RuntimeException("Timed out immediately waiting for {$timeoutMs} ms");
        }

        $fiber = new Fiber($callable);
        $startTime = microtime(true);

        try {
            $fiber->start();

            while (!$fiber->isTerminated()) {
                $elapsedTime = (microtime(true) - $startTime) * 1000;
                if ($elapsedTime >= $timeoutMs) {
                    throw new RuntimeException("Timed out waiting for {$timeoutMs} ms");
                }

                try {
                    if ($fiber->isSuspended()) {
                        $fiber->resume();
                    }
                } catch (Exception $e) {
                    throw new RuntimeException("Error during Foroutines execution: " . $e->getMessage(), 0, $e);
                }

                Pause::new();
            }

            return $fiber->getReturn();
        } catch (Exception $e) {
            throw $e;
        }
    }
}
