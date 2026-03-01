<?php

namespace vosaka\foroutines\sync;

use Exception;
use InvalidArgumentException;

/**
 * Class Mutex
 * Provides mutual exclusion (mutex) functionality for multi-process synchronization.
 * Automatically detects platform capabilities and falls back to compatible methods.
 * Supports file-based locking, System V semaphores, and APCu-based locking.
 */
class Mutex
{
    private $lockFile;
    private $lockHandle;
    private $semaphore;
    private $semaphoreKey;
    private $lockType;
    private $actualLockType; // The type actually being used after fallback
    private $isLocked = false;
    private $timeout;
    private $lockName;
    private $apcu_key;

    const LOCK_FILE = "file";
    const LOCK_SEMAPHORE = "semaphore";
    const LOCK_APCU = "apcu";
    const LOCK_AUTO = "auto"; // Automatically choose best available method

    /**
     * @param string $name Unique name for the mutex
     * @param string $type Lock type: 'file', 'semaphore', 'apcu', or 'auto'
     * @param int $timeout Timeout in seconds (0 = no timeout)
     * @param string|null $lockDir Directory for lock files (only for file locks)
     */
    public function __construct(
        string $name,
        string $type = self::LOCK_AUTO,
        int $timeout = 0,
        ?string $lockDir = null,
    ) {
        if (empty($name)) {
            throw new InvalidArgumentException("Mutex name cannot be empty");
        }

        if (
            !in_array($type, [
                self::LOCK_FILE,
                self::LOCK_SEMAPHORE,
                self::LOCK_APCU,
                self::LOCK_AUTO,
            ])
        ) {
            throw new InvalidArgumentException(
                "Invalid lock type. Use LOCK_FILE, LOCK_SEMAPHORE, LOCK_APCU, or LOCK_AUTO",
            );
        }

        $this->lockName = $name;
        $this->lockType = $type;
        $this->timeout = $timeout;

        // Auto-detect best available lock type
        if ($type === self::LOCK_AUTO) {
            $this->actualLockType = $this->detectBestLockType();
        } else {
            $this->actualLockType = $this->validateAndGetLockType($type);
        }

        // Initialize based on actual lock type
        switch ($this->actualLockType) {
            case self::LOCK_FILE:
                $this->initFileLock($lockDir);
                break;
            case self::LOCK_SEMAPHORE:
                $this->initSemaphoreLock();
                break;
            case self::LOCK_APCU:
                $this->initApcuLock();
                break;
        }

        // Cleanup on script termination
        register_shutdown_function([$this, "release"]);
    }

    /**
     * Detect the best available lock type for current platform
     */
    private function detectBestLockType(): string
    {
        // Priority order: semaphore > apcu > file
        if ($this->isSemaphoreAvailable()) {
            return self::LOCK_SEMAPHORE;
        } elseif ($this->isApcuAvailable()) {
            return self::LOCK_APCU;
        } else {
            return self::LOCK_FILE;
        }
    }

    /**
     * Validate requested lock type and return actual usable type
     */
    private function validateAndGetLockType(string $requestedType): string
    {
        switch ($requestedType) {
            case self::LOCK_SEMAPHORE:
                if (!$this->isSemaphoreAvailable()) {
                    // Fallback to next best option
                    if ($this->isApcuAvailable()) {
                        error_log(
                            "Warning: System V semaphore not available, falling back to APCu",
                        );
                        return self::LOCK_APCU;
                    } else {
                        error_log(
                            "Warning: System V semaphore not available, falling back to file locking",
                        );
                        return self::LOCK_FILE;
                    }
                }
                return self::LOCK_SEMAPHORE;

            case self::LOCK_APCU:
                if (!$this->isApcuAvailable()) {
                    error_log(
                        "Warning: APCu not available, falling back to file locking",
                    );
                    return self::LOCK_FILE;
                }
                return self::LOCK_APCU;

            case self::LOCK_FILE:
                return self::LOCK_FILE; // Always available

            default:
                return self::LOCK_FILE;
        }
    }

    /**
     * Check if System V semaphore is available
     */
    private function isSemaphoreAvailable(): bool
    {
        return extension_loaded("sysvsem") && function_exists("sem_get");
    }

    /**
     * Check if APCu is available
     */
    private function isApcuAvailable(): bool
    {
        return extension_loaded("apcu") &&
            function_exists("apcu_store") &&
            \apcu_enabled();
    }

    /**
     * Check if we're running on Windows
     */
    private function isWindows(): bool
    {
        return strtoupper(substr(PHP_OS, 0, 3)) === "WIN";
    }

    /**
     * Initialize file-based locking
     */
    private function initFileLock(?string $lockDir): void
    {
        $lockDir = $lockDir ?: sys_get_temp_dir();

        if (!is_dir($lockDir) || !is_writable($lockDir)) {
            throw new Exception("Lock directory '{$lockDir}' is not writable");
        }

        $this->lockFile =
            $lockDir .
            DIRECTORY_SEPARATOR .
            "mutex_" .
            md5($this->lockName) .
            ".lock";
    }

    /**
     * Initialize semaphore-based locking
     */
    private function initSemaphoreLock(): void
    {
        // Generate a unique key based on the mutex name
        $this->semaphoreKey = $this->generateSemaphoreKey($this->lockName);

        if ($this->semaphoreKey === -1) {
            throw new Exception("Failed to generate semaphore key");
        }
    }

    /**
     * Initialize APCu-based locking
     */
    private function initApcuLock(): void
    {
        $this->apcu_key = "mutex_" . md5($this->lockName);
    }

    /**
     * Generate a semaphore key from mutex name
     */
    private function generateSemaphoreKey(string $name): int
    {
        // Try ftok first
        if (function_exists("ftok")) {
            $key = ftok(__FILE__, substr(md5($name), 0, 1));
            if ($key !== -1) {
                return $key;
            }
        }

        // Fallback: generate from hash
        return abs(crc32($name)) % 0x7fffffff;
    }

    /**
     * Acquire the mutex lock
     *
     * @param bool $blocking Whether to block until lock is acquired
     * @return bool True if lock acquired, false otherwise
     * @throws Exception
     */
    public function acquire(bool $blocking = true): bool
    {
        if ($this->isLocked) {
            return true; // Already locked by this instance
        }

        $startTime = time();

        do {
            $acquired = false;

            switch ($this->actualLockType) {
                case self::LOCK_FILE:
                    $acquired = $this->acquireFileLock($blocking);
                    break;
                case self::LOCK_SEMAPHORE:
                    $acquired = $this->acquireSemaphoreLock($blocking);
                    break;
                case self::LOCK_APCU:
                    $acquired = $this->acquireApcuLock($blocking);
                    break;
            }

            if ($acquired) {
                $this->isLocked = true;
                return true;
            }

            if (!$blocking) {
                return false;
            }

            // Check timeout
            if ($this->timeout > 0 && time() - $startTime >= $this->timeout) {
                throw new Exception(
                    "Mutex timeout after {$this->timeout} seconds",
                );
            }

            // Short sleep to avoid busy waiting
            usleep(10000); // 10ms
        } while ($blocking);

        return false;
    }

    /**
     * Acquire file-based lock
     */
    private function acquireFileLock(bool $blocking): bool
    {
        if (!$this->lockHandle) {
            $this->lockHandle = fopen($this->lockFile, "c+");
            if (!$this->lockHandle) {
                throw new Exception(
                    "Failed to open lock file: {$this->lockFile}",
                );
            }
        }

        $lockType = $blocking ? LOCK_EX : LOCK_EX | LOCK_NB;
        return flock($this->lockHandle, $lockType);
    }

    /**
     * Acquire semaphore-based lock
     */
    private function acquireSemaphoreLock(bool $blocking): bool
    {
        if (!$this->semaphore) {
            // Create or get existing semaphore (1 resource = mutex behavior)
            $this->semaphore = sem_get($this->semaphoreKey, 1, 0666, 1);
            if (!$this->semaphore) {
                throw new Exception("Failed to create/get semaphore");
            }
        }

        return $blocking
            ? sem_acquire($this->semaphore)
            : sem_acquire($this->semaphore, true);
    }

    /**
     * Acquire APCu-based lock
     */
    private function acquireApcuLock(bool $blocking): bool
    {
        $lockValue = getmypid() . "_" . microtime(true);

        // Try to acquire lock
        if (
            \apcu_add(
                $this->apcu_key,
                $lockValue,
                $this->timeout > 0 ? $this->timeout : 3600,
            )
        ) {
            return true;
        }

        if (!$blocking) {
            return false;
        }

        // For blocking mode, we need to implement our own retry logic
        // since APCu doesn't have native blocking
        return false; // Will be retried in main acquire loop
    }

    /**
     * Release the mutex lock
     *
     * @return bool True if successfully released
     */
    public function release(): bool
    {
        if (!$this->isLocked) {
            return true; // Already released
        }

        $released = false;

        switch ($this->actualLockType) {
            case self::LOCK_FILE:
                $released = $this->releaseFileLock();
                break;
            case self::LOCK_SEMAPHORE:
                $released = $this->releaseSemaphoreLock();
                break;
            case self::LOCK_APCU:
                $released = $this->releaseApcuLock();
                break;
        }

        if ($released) {
            $this->isLocked = false;
        }

        return $released;
    }

    /**
     * Release file-based lock
     */
    private function releaseFileLock(): bool
    {
        if ($this->lockHandle) {
            $result = flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;

            // Optionally remove lock file (be careful with this)
            if (file_exists($this->lockFile)) {
                @unlink($this->lockFile);
            }

            return $result;
        }
        return true;
    }

    /**
     * Release semaphore-based lock
     */
    private function releaseSemaphoreLock(): bool
    {
        if ($this->semaphore) {
            return sem_release($this->semaphore);
        }
        return true;
    }

    /**
     * Release APCu-based lock
     */
    private function releaseApcuLock(): bool
    {
        return \apcu_delete($this->apcu_key);
    }

    /**
     * Try to acquire lock without blocking
     *
     * @return bool True if lock acquired immediately
     */
    public function tryLock(): bool
    {
        return $this->acquire(false);
    }

    /**
     * Check if the mutex is currently locked by this instance
     *
     * @return bool
     */
    public function isLocked(): bool
    {
        return $this->isLocked;
    }

    /**
     * Execute a callback with the mutex locked
     *
     * @param callable $callback
     * @param bool $blocking
     * @return mixed The return value of the callback
     * @throws Exception
     */
    public function synchronized(callable $callback, bool $blocking = true)
    {
        if (!$this->acquire($blocking)) {
            throw new Exception("Failed to acquire mutex lock");
        }

        try {
            return $callback();
        } finally {
            $this->release();
        }
    }

    /**
     * Get mutex information
     *
     * @return array
     */
    public function getInfo(): array
    {
        return [
            "name" => $this->lockName,
            "requested_type" => $this->lockType,
            "actual_type" => $this->actualLockType,
            "locked" => $this->isLocked,
            "timeout" => $this->timeout,
            "platform" => PHP_OS,
            "is_windows" => $this->isWindows(),
            "capabilities" => [
                "sysvsem" => $this->isSemaphoreAvailable(),
                "apcu" => $this->isApcuAvailable(),
                "file_locking" => true, // Always available
            ],
            "lock_file" => $this->lockFile ?? null,
            "semaphore_key" => $this->semaphoreKey ?? null,
            "apcu_key" => $this->apcu_key ?? null,
        ];
    }

    /**
     * Get platform compatibility report
     *
     * @return array
     */
    public static function getPlatformInfo(): array
    {
        $instance = new self("test", self::LOCK_AUTO);
        return [
            "platform" => PHP_OS,
            "is_windows" => $instance->isWindows(),
            "available_methods" => [
                "file" => true,
                "semaphore" => $instance->isSemaphoreAvailable(),
                "apcu" => $instance->isApcuAvailable(),
            ],
            "recommended_method" => $instance->detectBestLockType(),
            "extensions" => [
                "sysvsem" => extension_loaded("sysvsem"),
                "apcu" => extension_loaded("apcu"),
            ],
        ];
    }

    /**
     * Cleanup resources
     */
    public function __destruct()
    {
        $this->release();

        // Clean up semaphore if needed
        if (
            $this->semaphore &&
            $this->actualLockType === self::LOCK_SEMAPHORE
        ) {
            // Note: sem_remove() removes the semaphore completely
            // Use with caution in multi-process environments.
            // Suppress warnings in case another Mutex instance sharing
            // the same semaphore key already removed it, or the
            // shutdown function already ran release() which invalidated
            // the resource.
            @sem_remove($this->semaphore);
            $this->semaphore = null;
        }
    }

    /**
     * Static factory method for quick mutex creation
     *
     * @param string $name
     * @param string $type
     * @param int $timeout
     * @return static
     */
    public static function create(
        string $name,
        string $type = self::LOCK_AUTO,
        int $timeout = 0,
    ): self {
        return new static($name, $type, $timeout);
    }

    /**
     * Static method to execute code with mutex protection
     *
     * @param string $mutexName
     * @param callable $callback
     * @param string $lockType
     * @param int $timeout
     * @return mixed
     */
    public static function protect(
        string $mutexName,
        callable $callback,
        string $lockType = self::LOCK_AUTO,
        int $timeout = 30,
    ) {
        $mutex = self::create($mutexName, $lockType, $timeout);
        return $mutex->synchronized($callback);
    }
}
