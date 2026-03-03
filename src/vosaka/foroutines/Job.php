<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Fiber;
use Throwable;
use RuntimeException;

class Job
{
    public ?Fiber $fiber = null;
    public ?Job $job = null;

    /**
     * Start time in nanoseconds (monotonic clock via hrtime).
     * Using int nanoseconds avoids float zval boxing/unboxing
     * that microtime(true) would incur on every access.
     */
    private int $startTimeNs;

    /**
     * End time in nanoseconds, or -1 if not yet ended.
     * Using -1 sentinel instead of ?int avoids nullable zval overhead.
     */
    private int $endTimeNs = -1;

    private JobState $status;

    /**
     * Lazy-initialized join callbacks — null until first onJoin() call.
     * Avoids allocating an empty array for every Job instance since
     * most jobs never use onJoin().
     * @var array<callable>|null
     */
    private ?array $joins = null;

    /**
     * Lazy-initialized invoker callbacks — null until first invokeOnCompletion() call.
     * Avoids allocating an empty array for every Job instance since
     * most jobs never use invokeOnCompletion().
     * @var array<callable>|null
     */
    private ?array $invokers = null;

    /**
     * Timeout in nanoseconds, or null if no timeout is set.
     */
    private ?int $timeoutNs = null;

    public function __construct(public int $id)
    {
        $this->startTimeNs = hrtime(true);
        $this->status = JobState::PENDING;
    }

    /**
     * Recycle this Job instance for reuse from an object pool.
     *
     * Resets all mutable state to the same values as a freshly constructed
     * Job, but reuses the existing PHP object allocation. This avoids:
     *   - Zend object allocation overhead (~0.5-1µs per new object)
     *   - GC pressure from short-lived Job instances
     *   - Constructor call overhead (parent::__construct, enum init)
     *
     * The fiber reference is set to the new fiber, and the id is updated.
     * All callbacks (joins, invokers) are cleared, timeout is removed,
     * and the status is reset to PENDING.
     *
     * @param int   $id    New job ID (typically spl_object_id of the new fiber).
     * @param Fiber $fiber The new fiber to associate with this recycled job.
     */
    public function recycleJob(int $id, Fiber $fiber): void
    {
        $this->id = $id;
        $this->fiber = $fiber;
        $this->job = null;
        $this->startTimeNs = hrtime(true);
        $this->endTimeNs = -1;
        $this->status = JobState::PENDING;
        $this->joins = null;
        $this->invokers = null;
        $this->timeoutNs = null;
    }

    /**
     * Returns start time as float seconds for external consumers
     * that still expect microtime-compatible values.
     */
    public function getStartTime(): float
    {
        return $this->startTimeNs / 1_000_000_000;
    }

    /**
     * Returns end time as float seconds, or null if not yet ended.
     */
    public function getEndTime(): ?float
    {
        return $this->endTimeNs === -1
            ? null
            : $this->endTimeNs / 1_000_000_000;
    }

    public function getStatus(): JobState
    {
        return $this->status;
    }

    public function start(): bool
    {
        if ($this->status !== JobState::PENDING) {
            return true;
        }
        $this->fiber->start();
        $this->status = JobState::RUNNING;
        $this->startTimeNs = hrtime(true);
        return true;
    }

    public function complete(): void
    {
        if ($this->status === JobState::COMPLETED) {
            return; // Already completed, idempotent
        }

        if ($this->status !== JobState::RUNNING) {
            throw new RuntimeException("Job must be running to complete it.");
        }
        $this->status = JobState::COMPLETED;
        $this->endTimeNs = hrtime(true);
        $this->triggerJoins();
        $this->triggerInvokers();
    }

    public function fail(): void
    {
        if ($this->status === JobState::FAILED) {
            return; // Already failed, idempotent
        }

        // Allow fail from both RUNNING and PENDING states
        // (a fiber can throw before being fully resumed, e.g. during start())
        if (
            $this->status !== JobState::RUNNING &&
            $this->status !== JobState::PENDING
        ) {
            throw new RuntimeException("Job must be running to fail it.");
        }
        $this->status = JobState::FAILED;
        $this->endTimeNs = hrtime(true);
        $this->triggerJoins();
        $this->triggerInvokers();
    }

    public function cancel(): void
    {
        if (
            $this->status === JobState::COMPLETED ||
            $this->status === JobState::FAILED
        ) {
            throw new RuntimeException(
                "Job cannot be cancelled after it has completed or failed.",
            );
        }
        $this->status = JobState::CANCELLED;
        $this->endTimeNs = hrtime(true);
        $this->triggerInvokers();
    }

    public function isFinal(): bool
    {
        return $this->status->isFinal();
    }

    public function isCompleted(): bool
    {
        return $this->status === JobState::COMPLETED;
    }

    public function isRunning(): bool
    {
        return $this->status === JobState::RUNNING;
    }

    public function isFailed(): bool
    {
        return $this->status === JobState::FAILED;
    }

    public function isCancelled(): bool
    {
        return $this->status === JobState::CANCELLED;
    }

    public function onJoin(callable $callback): void
    {
        if ($this->isFinal()) {
            throw new RuntimeException(
                "Cannot add join callback to a job that has already completed.",
            );
        }

        // Lazy init — only allocate array when actually needed
        $this->joins ??= [];
        $this->joins[] = $callback;
    }

    private function triggerJoins(): void
    {
        if ($this->joins !== null) {
            foreach ($this->joins as $callback) {
                $callback($this);
            }
            $this->joins = null;
        }
    }

    public function join(): mixed
    {
        if (!$this->isFinal()) {
            throw new RuntimeException("Job is not yet complete.");
        }

        if ($this->isFailed()) {
            throw new RuntimeException("Job has failed.");
        }

        if ($this->isCancelled()) {
            throw new RuntimeException("Job has been cancelled.");
        }

        if ($this->fiber === null) {
            throw new RuntimeException("Job fiber is not set.");
        }

        if (!$this->fiber->isStarted()) {
            $this->fiber->start();
        }

        try {
            while (FiberUtils::fiberStillRunning($this->fiber)) {
                $this->fiber->resume();
                Pause::force();
            }
        } catch (Throwable $e) {
            if (!$this->isFinal()) {
                $this->fail();
            }
            throw $e;
        }

        if ($this->fiber->isTerminated() && !$this->isFinal()) {
            $this->complete();
        }

        $result = $this->fiber->getReturn();
        // Release fiber reference early so its memory can be reclaimed
        // before the Job object itself is garbage-collected.
        $this->fiber = null;
        return $result;
    }

    public function invokeOnCompletion(callable $callback): void
    {
        if ($this->isFinal()) {
            throw new RuntimeException(
                "Cannot add invoke callback to a job that has already completed.",
            );
        }

        // Lazy init — only allocate array when actually needed
        $this->invokers ??= [];
        $this->invokers[] = $callback;
    }

    public function triggerInvokers(): void
    {
        if ($this->invokers !== null) {
            foreach ($this->invokers as $callback) {
                $callback($this);
            }
            $this->invokers = null;
        }
    }

    /**
     * Set a timeout after which the job is considered timed out.
     * Converts seconds (float) to nanoseconds (int) once at set-time
     * so that isTimedOut() only performs int arithmetic on the hot path.
     *
     * @param float $seconds Timeout duration in seconds.
     */
    public function cancelAfter(float $seconds): void
    {
        if ($this->isFinal()) {
            throw new RuntimeException(
                "Cannot set timeout for a job that has already completed.",
            );
        }
        $this->timeoutNs = (int) ($seconds * 1_000_000_000);
    }

    /**
     * Check whether this job has exceeded its timeout.
     * Uses hrtime(true) int nanoseconds — pure int comparison,
     * no float boxing/unboxing on the hot scheduler path.
     */
    public function isTimedOut(): bool
    {
        return $this->timeoutNs !== null &&
            hrtime(true) - $this->startTimeNs >= $this->timeoutNs;
    }
}
