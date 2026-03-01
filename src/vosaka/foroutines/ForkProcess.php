<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Closure;
use Exception;
use Generator;

/**
 * ForkProcess — Low-overhead child process execution via pcntl_fork().
 *
 * On Linux/macOS where the pcntl and shmop extensions are available, this
 * class uses pcntl_fork() to create a child process. The forked child
 * inherits the entire parent memory space (copy-on-write), so there is:
 *   - NO process bootstrap cost (no PHP interpreter startup)
 *   - NO Composer autoload overhead
 *   - NO closure serialization/deserialization round-trip
 *   - NO file I/O for the closure payload
 *
 * The result is passed back to the parent via a small shmop segment.
 *
 * Compared to Process (which spawns `php background_processor_file.php`
 * via symfony/process), ForkProcess reduces per-task overhead from
 * ~50-200ms down to ~1-5ms on typical Linux systems.
 *
 * On Windows (or when pcntl is not available), ForkProcess transparently
 * falls back to the original Process class which uses symfony/process.
 *
 * Architecture:
 *   Parent                          Child (forked)
 *   ──────                          ──────────────
 *   fork()                          Execute $closure
 *     │                               │
 *     │  ┌─── shmop segment ───┐      │
 *     │  │ result placeholder  │      │
 *     │  └─────────────────────┘      │
 *     │                             Write serialized result to shmop
 *     │                             _exit(0)
 *     │
 *   waitpid(WNOHANG) in fiber loop
 *   Read result from shmop
 *   Cleanup shmop
 *
 * Usage:
 *   // Automatic — uses fork on Linux, symfony/process on Windows
 *   $fork = new ForkProcess();
 *   $async = $fork->run(function () {
 *       return heavy_computation();
 *   });
 *   $result = $async->wait();
 */
final class ForkProcess
{
    /**
     * Maximum shared memory segment size for the result (10 MB).
     * If the serialized result exceeds this, it falls back to a temp file.
     */
    private const MAX_SHMOP_SIZE = 10 * 1024 * 1024;

    /**
     * Sentinel written at the start of the shmop segment to indicate
     * that the child has finished writing its result.
     */
    private const READY_MARKER = "__FORK_RESULT_READY__\n";

    /**
     * Maximum integer for shmop key generation.
     */
    private const MAX_INT = 2147483647;

    /**
     * Unique shmop key for this process instance.
     */
    private int $shmopKey;

    /**
     * Temp file path used as fallback when result exceeds MAX_SHMOP_SIZE.
     */
    private ?string $tempFile = null;

    public function __construct(int $shmopKey = 0)
    {
        if ($shmopKey === 0) {
            $this->shmopKey =
                mt_rand(1, self::MAX_INT - 1) + (time() % 1000000);
            // Ensure positive 32-bit int
            $this->shmopKey = abs($this->shmopKey) % self::MAX_INT;
            if ($this->shmopKey === 0) {
                $this->shmopKey = 1;
            }
        } else {
            $this->shmopKey = $shmopKey;
        }
    }

    /**
     * Check whether pcntl_fork() is available on this platform.
     *
     * Requirements:
     *   - Not Windows
     *   - pcntl extension loaded
     *   - shmop extension loaded (for result passing)
     *   - pcntl_fork function exists and is not disabled
     */
    public static function isForkAvailable(): bool
    {
        // Windows never supports pcntl
        if (self::isWindows()) {
            return false;
        }

        if (!extension_loaded("pcntl")) {
            return false;
        }

        if (!function_exists("pcntl_fork")) {
            return false;
        }

        if (!extension_loaded("shmop")) {
            return false;
        }

        // Check if pcntl_fork is not in the disabled functions list
        $disabled = explode(",", (string) ini_get("disable_functions"));
        $disabled = array_map("trim", $disabled);
        if (in_array("pcntl_fork", $disabled, true)) {
            return false;
        }

        return true;
    }

    /**
     * Run a closure in a child process and return an Async handle.
     *
     * If pcntl_fork() is available, uses fork for minimal overhead.
     * Otherwise, falls back to the original Process class (symfony/process).
     *
     * @param Closure $closure The closure to execute in the child process.
     * @return Async An Async instance that resolves to the closure's return value.
     * @throws Exception On fork failure or shmop errors.
     */
    public function run(Closure $closure): Async
    {
        if (!self::isForkAvailable()) {
            // Fallback to symfony/process based Process class
            return $this->runViaSymfonyProcess($closure);
        }

        return $this->runViaFork($closure);
    }

    /**
     * Fork-based execution (Linux/macOS).
     *
     * The parent creates a shmop segment, forks, and the child executes
     * the closure directly (no serialization needed for the closure itself,
     * since fork copies the entire memory space). The result is serialized
     * and written to shmop. The parent polls waitpid(WNOHANG) in a
     * cooperative fiber loop.
     */
    private function runViaFork(Closure $closure): Async
    {
        // Pre-allocate shmop segment for the result.
        // We use a marker-based protocol:
        //   - Parent creates segment filled with NULs
        //   - Child writes: READY_MARKER + base64(serialize($result))
        //   - Parent polls for READY_MARKER prefix
        $shmopKey = $this->shmopKey;
        $markerLen = strlen(self::READY_MARKER);

        $shm = @shmop_open($shmopKey, "c", 0660, self::MAX_SHMOP_SIZE);
        if ($shm === false) {
            throw new Exception(
                "ForkProcess: Failed to create shmop segment with key {$shmopKey}",
            );
        }

        // Clear the segment
        shmop_write($shm, str_repeat("\0", min(self::MAX_SHMOP_SIZE, 4096)), 0);

        // Create a temp file path for oversized results
        $tempFile =
            sys_get_temp_dir() .
            DIRECTORY_SEPARATOR .
            "fork_result_" .
            $shmopKey .
            ".dat";
        $this->tempFile = $tempFile;

        $pid = pcntl_fork();

        if ($pid === -1) {
            // Fork failed — clean up shmop and fall back
            shmop_delete($shm);
            return $this->runViaSymfonyProcess($closure);
        }

        if ($pid === 0) {
            // ─── CHILD PROCESS ───────────────────────────────────
            // We are in the forked child. Execute the closure and
            // write the result back via shmop.
            $this->executeInChild($closure, $shmopKey, $tempFile);
            // Never returns — child calls _exit()
        }

        // ─── PARENT PROCESS ─────────────────────────────────────
        // Return an Async that polls waitpid + shmop in a fiber loop.
        return Async::new(function () use ($pid, $shmopKey, $tempFile) {
            return $this->waitForChild($pid, $shmopKey, $tempFile);
        });
    }

    /**
     * Executed in the CHILD process after fork.
     *
     * Runs the closure, serializes the result, and writes it to shmop.
     * Then calls _exit() to terminate the child without running PHP
     * shutdown functions (which could interfere with the parent).
     *
     * @param Closure $closure The closure to execute.
     * @param int $shmopKey The shmop key for result passing.
     * @param string $tempFile Fallback temp file for oversized results.
     */
    private function executeInChild(
        Closure $closure,
        int $shmopKey,
        string $tempFile,
    ): never {
        try {
            // Reset static scheduler state inherited from the parent
            // process. After fork(), the child gets a copy of the
            // parent's entire memory space, including Launch::$queue,
            // Launch::$map, WorkerPool's internal arrays, and AsyncIO's
            // read/write watchers. These contain stale fibers/jobs/
            // streams from the parent that are meaningless in the child.
            // If not cleared, Thread::wait() or any scheduler loop
            // running inside the child closure will see these phantom
            // entries and spin forever.
            Launch::$queue = new \SplQueue();
            Launch::$map = [];
            WorkerPool::resetState();
            AsyncIO::resetState();
            EventLoop::resetState();

            // Execute the user's closure
            $result = $closure();

            // If the closure returned a Generator, exhaust it to get
            // the final value. Generators cannot be serialized, so we
            // must consume them here in the child process.
            if ($result instanceof Generator) {
                $genResult = null;
                while ($result->valid()) {
                    $genResult = $result->current();
                    $result->next();
                }
                // If the generator used "return <value>", prefer that.
                try {
                    $returnValue = $result->getReturn();
                    if ($returnValue !== null) {
                        $genResult = $returnValue;
                    }
                } catch (\Exception) {
                    // Generator didn't return a value; use last yielded value
                }
                $result = $genResult;
            }

            // Serialize the result
            $serialized = serialize($result);
            $encoded = base64_encode($serialized);
            $payload = self::READY_MARKER . $encoded;
            $payloadLen = strlen($payload);

            // Open the pre-existing shmop segment
            $shm = @shmop_open($shmopKey, "w", 0, 0);

            if ($shm !== false) {
                if ($payloadLen <= self::MAX_SHMOP_SIZE) {
                    // Result fits in shmop — write directly
                    shmop_write($shm, $payload, 0);
                } else {
                    // Result too large — write to temp file, store path in shmop
                    file_put_contents($tempFile, $encoded);
                    $fileMarker = self::READY_MARKER . "__FILE__:" . $tempFile;
                    shmop_write($shm, $fileMarker, 0);
                }
            } else {
                // shmop_open failed in child — last resort: temp file
                file_put_contents($tempFile, $encoded);
                // Parent will check temp file if shmop read fails
            }
        } catch (\Throwable $e) {
            // Write error info
            try {
                $errorResult = [
                    "__fork_error__" => true,
                    "message" => $e->getMessage(),
                    "trace" => $e->getTraceAsString(),
                    "file" => $e->getFile(),
                    "line" => $e->getLine(),
                ];
                $errorPayload =
                    self::READY_MARKER . base64_encode(serialize($errorResult));

                $shm = @shmop_open($shmopKey, "w", 0, 0);
                if ($shm !== false) {
                    shmop_write($shm, $errorPayload, 0);
                }
            } catch (\Throwable) {
                // Can't do anything more — just exit with error code
            }

            // Exit child without running parent's shutdown handlers.
            // We use exit() instead of posix_kill(SIGKILL) so that
            // pcntl_wifexited() returns true and pcntl_wexitstatus()
            // gives a proper exit code in the parent.
            exit(1);
        }

        // Clean exit — exit(0) terminates the child. We intentionally
        // do NOT use posix_kill(SIGKILL) because a signal-killed process
        // causes pcntl_wifexited() to return false in the parent, making
        // it impossible to distinguish "exited normally with null result"
        // from "crashed". Plain exit(0) is sufficient; PHP destructors
        // that run are acceptable since the child is about to terminate.
        exit(0);
    }

    /**
     * Parent-side: wait for the child process to complete and read the result.
     *
     * Uses waitpid(WNOHANG) in a cooperative fiber loop so that other
     * fibers can run while the child is executing.
     *
     * @param int $pid Child process PID.
     * @param int $shmopKey Shmop key for result retrieval.
     * @param string $tempFile Fallback temp file path.
     * @return mixed The closure's return value.
     * @throws Exception On child process errors.
     */
    private function waitForChild(
        int $pid,
        int $shmopKey,
        string $tempFile,
    ): mixed {
        $markerLen = strlen(self::READY_MARKER);

        // Poll waitpid with WNOHANG so we don't block
        while (true) {
            $status = 0;
            $result = pcntl_waitpid($pid, $status, WNOHANG);

            if ($result === $pid) {
                // Child has exited
                break;
            }

            if ($result === -1) {
                // Error or child already reaped
                break;
            }

            // Child still running — yield to let other fibers run
            Pause::new();
        }

        // Read result from shmop
        $shm = @shmop_open($shmopKey, "a", 0, 0);
        $decoded = null;
        $resultFound = false;

        if ($shm !== false) {
            $size = shmop_size($shm);
            $raw = shmop_read($shm, 0, min($size, self::MAX_SHMOP_SIZE));

            // Trim NUL bytes
            $raw = rtrim($raw, "\0");

            if (str_starts_with($raw, self::READY_MARKER)) {
                $payload = substr($raw, $markerLen);

                // Check if result was stored in a temp file
                if (str_starts_with($payload, "__FILE__:")) {
                    $filePath = substr($payload, 9);
                    if (is_file($filePath) && is_readable($filePath)) {
                        $payload = file_get_contents($filePath);
                        @unlink($filePath);
                    } else {
                        throw new Exception(
                            "ForkProcess: Result file not found: {$filePath}",
                        );
                    }
                }

                $serialized = base64_decode($payload);
                if ($serialized !== false) {
                    $decoded = unserialize($serialized);
                    $resultFound = true;
                }
            }

            // Cleanup shmop
            shmop_delete($shm);
        }

        // Fallback: check temp file
        if (!$resultFound && is_file($tempFile) && is_readable($tempFile)) {
            $payload = file_get_contents($tempFile);
            @unlink($tempFile);
            $serialized = base64_decode($payload);
            if ($serialized !== false) {
                $decoded = unserialize($serialized);
                $resultFound = true;
            }
        }

        // Check for error result
        if (is_array($decoded) && isset($decoded["__fork_error__"])) {
            throw new Exception(
                "ForkProcess child error: " .
                    ($decoded["message"] ?? "Unknown error") .
                    "\n" .
                    ($decoded["trace"] ?? ""),
            );
        }

        // If we never found a result in shmop or temp file, something
        // went wrong in the child. Check the exit status for details.
        // Note: $decoded===null is a valid result (the closure returned null),
        // so we use $resultFound to distinguish "null result" from "no result".
        if (!$resultFound) {
            $exitCode = pcntl_wifexited($status)
                ? pcntl_wexitstatus($status)
                : -1;

            if ($exitCode !== 0) {
                throw new Exception(
                    "ForkProcess: Child process exited with code {$exitCode} " .
                        "and produced no result",
                );
            }
        }

        return $decoded;
    }

    /**
     * Fallback: run the closure via the original Process class
     * (uses symfony/process to spawn a new PHP interpreter).
     *
     * This is used on Windows or when pcntl is not available.
     *
     * @param Closure $closure The closure to execute.
     * @return Async An Async handle for the result.
     */
    private function runViaSymfonyProcess(Closure $closure): Async
    {
        $process = new Process($this->shmopKey);
        return $process->run($closure);
    }

    /**
     * Check if we're running on Windows.
     */
    private static function isWindows(): bool
    {
        return strncasecmp(PHP_OS, "WIN", 3) === 0;
    }

    /**
     * Clean up any leftover temp files.
     */
    public function __destruct()
    {
        if ($this->tempFile !== null && is_file($this->tempFile)) {
            @unlink($this->tempFile);
        }
    }
}
