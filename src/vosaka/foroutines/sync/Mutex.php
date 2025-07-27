<?php

namespace vosaka\foroutines\sync;

use Exception;
use InvalidArgumentException;

/**
 * Class Mutex
 * Provides mutual exclusion (mutex) functionality for multi-process synchronization.
 * Supports both file-based locking and System V semaphores.
 */
class Mutex
{
    private $lockFile;
    private $lockHandle;
    private $semaphore;
    private $semaphoreKey;
    private $lockType;
    private $isLocked = false;
    private $timeout;
    private $lockName;

    const LOCK_FILE = 'file';
    const LOCK_SEMAPHORE = 'semaphore';

    /**
     * @param string $name Unique name for the mutex
     * @param string $type Lock type: 'file' or 'semaphore'
     * @param int $timeout Timeout in seconds (0 = no timeout)
     * @param string|null $lockDir Directory for lock files (only for file locks)
     */
    public function __construct(
        string $name,
        string $type = self::LOCK_FILE,
        int $timeout = 0,
        ?string $lockDir = null
    ) {
        if (empty($name)) {
            throw new InvalidArgumentException('Mutex name cannot be empty');
        }

        if (!in_array($type, [self::LOCK_FILE, self::LOCK_SEMAPHORE])) {
            throw new InvalidArgumentException('Invalid lock type. Use LOCK_FILE or LOCK_SEMAPHORE');
        }

        $this->lockName = $name;
        $this->lockType = $type;
        $this->timeout = $timeout;

        if ($type === self::LOCK_FILE) {
            $this->initFileLock($lockDir);
        } else {
            $this->initSemaphoreLock();
        }

        // Cleanup on script termination
        register_shutdown_function([$this, 'release']);
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

        $this->lockFile = $lockDir . DIRECTORY_SEPARATOR . 'mutex_' . md5($this->lockName) . '.lock';
    }

    /**
     * Initialize semaphore-based locking
     */
    private function initSemaphoreLock(): void
    {
        if (!extension_loaded('sysvsem')) {
            throw new Exception('System V semaphore extension is not loaded');
        }

        // Generate a unique key based on the mutex name
        $this->semaphoreKey = ftok(__FILE__, substr(md5($this->lockName), 0, 1));

        if ($this->semaphoreKey === -1) {
            throw new Exception('Failed to generate semaphore key');
        }
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
            $acquired = $this->lockType === self::LOCK_FILE
                ? $this->acquireFileLock($blocking)
                : $this->acquireSemaphoreLock($blocking);

            if ($acquired) {
                $this->isLocked = true;
                return true;
            }

            if (!$blocking) {
                return false;
            }

            // Check timeout
            if ($this->timeout > 0 && (time() - $startTime) >= $this->timeout) {
                throw new Exception("Mutex timeout after {$this->timeout} seconds");
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
            $this->lockHandle = fopen($this->lockFile, 'c+');
            if (!$this->lockHandle) {
                throw new Exception("Failed to open lock file: {$this->lockFile}");
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
                throw new Exception('Failed to create/get semaphore');
            }
        }

        return $blocking ? sem_acquire($this->semaphore) : sem_acquire($this->semaphore, true);
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

        $released = $this->lockType === self::LOCK_FILE
            ? $this->releaseFileLock()
            : $this->releaseSemaphoreLock();

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
            throw new Exception('Failed to acquire mutex lock');
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
            'name' => $this->lockName,
            'type' => $this->lockType,
            'locked' => $this->isLocked,
            'timeout' => $this->timeout,
            'lock_file' => $this->lockFile ?? null,
            'semaphore_key' => $this->semaphoreKey ?? null,
        ];
    }

    /**
     * Cleanup resources
     */
    public function __destruct()
    {
        $this->release();

        // Clean up semaphore if needed
        if ($this->semaphore && $this->lockType === self::LOCK_SEMAPHORE) {
            // Note: sem_remove() removes the semaphore completely
            // Use with caution in multi-process environments
            // sem_remove($this->semaphore);
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
        string $type = self::LOCK_FILE,
        int $timeout = 0
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
        string $lockType = self::LOCK_FILE,
        int $timeout = 30
    ) {
        $mutex = self::create($mutexName, $lockType, $timeout);
        return $mutex->synchronized($callback);
    }
}
