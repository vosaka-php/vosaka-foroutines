<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Fiber;
use FiberError;
use Exception;
use RuntimeException;

/**
 * Fiber Timeout Manager
 * Provides timeout functionality for PHP Fibers similar to Kotlin coroutines
 */
final class Timeout
{
    private static $timeouts = [];
    private static $timerId = 0;

    /**
     * Runs a fiber with specified timeout in milliseconds
     * Throws RuntimeException if timeout is exceeded
     * 
     * @param int $timeoutMs Timeout in milliseconds
     * @param callable $block The function to execute in fiber
     * @return mixed Result of the block execution
     * @throws RuntimeException
     */
    public static function withTimeout(int $timeoutMs, callable $block)
    {
        if ($timeoutMs <= 0) {
            throw new RuntimeException("Timed out immediately waiting for {$timeoutMs} ms");
        }

        $fiber = new Fiber($block);
        $timerId = ++self::$timerId;
        $startTime = microtime(true);

        // Register timeout
        self::$timeouts[$timerId] = [
            'fiber' => $fiber,
            'timeout' => $timeoutMs,
            'start_time' => $startTime,
            'expired' => false
        ];

        try {
            // Start the fiber
            $result = $fiber->start();

            // Check if fiber completed immediately
            if (!$fiber->isRunning()) {
                unset(self::$timeouts[$timerId]);
                return $result;
            }

            // Main execution loop with timeout checking
            while ($fiber->isRunning()) {
                // Check timeout
                if (self::checkTimeout($timerId)) {
                    throw new RuntimeException(
                        "Timed out waiting for {$timeoutMs} ms"
                    );
                }

                // Resume fiber with null (or could be a value from external source)
                try {
                    $result = $fiber->resume();
                } catch (FiberError $e) {
                    // Fiber already terminated
                    break;
                }

                // Small sleep to prevent busy waiting
                usleep(1000); // 1ms
            }

            unset(self::$timeouts[$timerId]);
            return $result;
        } catch (Exception $e) {
            unset(self::$timeouts[$timerId]);
            throw $e;
        }
    }

    /**
     * Runs a fiber with specified timeout, returns null if timeout is exceeded
     * 
     * @param int $timeoutMs Timeout in milliseconds
     * @param callable $block The function to execute in fiber
     * @return mixed|null Result of the block execution or null on timeout
     */
    public static function withTimeoutOrNull(int $timeoutMs, callable $block)
    {
        if ($timeoutMs <= 0) {
            return null;
        }

        try {
            return self::withTimeout($timeoutMs, $block);
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * Runs a fiber with timeout specified in seconds (float)
     * 
     * @param float $timeoutSeconds Timeout in seconds
     * @param callable $block The function to execute
     * @return mixed
     */
    public static function withTimeoutSeconds(float $timeoutSeconds, callable $block)
    {
        return self::withTimeout((int)($timeoutSeconds * 1000), $block);
    }

    /**
     * Runs a fiber with timeout specified in seconds, returns null on timeout
     * 
     * @param float $timeoutSeconds Timeout in seconds
     * @param callable $block The function to execute
     * @return mixed|null
     */
    public static function withTimeoutOrNullSeconds(float $timeoutSeconds, callable $block)
    {
        return self::withTimeoutOrNull((int)($timeoutSeconds * 1000), $block);
    }

    /**
     * Check if a specific timeout has expired
     * 
     * @param int $timerId
     * @return bool
     */
    private static function checkTimeout(int $timerId): bool
    {
        if (!isset(self::$timeouts[$timerId])) {
            return false;
        }

        $timeout = &self::$timeouts[$timerId];
        $elapsed = (microtime(true) - $timeout['start_time']) * 1000;

        if ($elapsed >= $timeout['timeout']) {
            $timeout['expired'] = true;
            return true;
        }

        return false;
    }

    /**
     * Get current running timeouts (for debugging)
     * 
     * @return array
     */
    public static function getActiveTimeouts(): array
    {
        $active = [];
        foreach (self::$timeouts as $id => $timeout) {
            $elapsed = (microtime(true) - $timeout['start_time']) * 1000;
            $active[$id] = [
                'timeout_ms' => $timeout['timeout'],
                'elapsed_ms' => $elapsed,
                'remaining_ms' => max(0, $timeout['timeout'] - $elapsed),
                'expired' => $timeout['expired'],
                'fiber_running' => $timeout['fiber']->isRunning()
            ];
        }
        return $active;
    }

    /**
     * Cancel all active timeouts (cleanup)
     */
    public static function cancelAllTimeouts(): void
    {
        self::$timeouts = [];
    }
}
