<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Closure;
use Exception;
use Laravel\SerializableClosure\SerializableClosure;

/**
 * Process class for running closures in a separate process using shared memory.
 * This class provides methods to check if a process is running and to run closures asynchronously.
 */
final class Process
{
    private const MAX_INT = 2147483647;

    public function __construct(private int $shmopKey = 0)
    {
        if (!$this->shmopKey) {
            $this->shmopKey = mt_rand(1, self::MAX_INT) + time();
        }
    }

    /**
     * Checks if a process with the given PID is running.
     *
     * @param int $pid The process ID to check.
     * @return Async An Async instance that resolves to true if the process is running, false otherwise.
     */
    public static function isProcessRunning(int $pid): Async
    {
        return Async::new(function () use ($pid) {
            if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
                // Windows
                exec("tasklist /FI \"PID eq $pid\" 2>NUL", $output);
                foreach ($output as $line) {
                    if (strpos($line, (string)$pid) !== false) {
                        return true;
                    }
                    Pause::new();
                }
                return false;
            } else {
                // Linux/macOS
                if (function_exists('posix_kill')) {
                    return posix_kill($pid, 0);
                }
                // fallback: check /proc
                return file_exists("/proc/$pid");
            }
        });
    }

    /**
     * Runs a closure in a separate process using shared memory.
     *
     * @param Closure $closure The closure to run asynchronously.
     * @return Async An Async instance that will return the result of the closure when it completes.
     * @throws Exception if the shmop extension is not loaded or if there is an error writing to shared memory.
     */
    public function run(Closure $closure): Async
    {
        if (!extension_loaded('shmop')) {
            throw new Exception('The shmop extension is not loaded.');
        }

        $serialized = serialize(new SerializableClosure($closure));
        $length = mb_strlen($serialized);
        $shmopInstance = shmop_open($this->shmopKey, 'c', 0660, $length);
        $bytesWritten = shmop_write($shmopInstance, $serialized, 0);

        if ($bytesWritten < $length) {
            throw new Exception('Error: Could not write the entire data to shared memory with length: ' .
                $length . '. Bytes written: ' . $bytesWritten . PHP_EOL);
        }

        $fileWithPath = __DIR__ . DIRECTORY_SEPARATOR . 'background_processor_file.php';
        $file = new PhpFile($fileWithPath, [$this->shmopKey]);
        return $file->run();
    }
}
