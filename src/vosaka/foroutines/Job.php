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
    private float $startTime;
    private ?float $endTime = null;
    private JobState $status;
    private array $joins = [];
    private array $invokers = [];
    private ?float $timeout = null;

    public function __construct(public int $id)
    {
        $this->startTime = microtime(true);
        $this->status = JobState::PENDING;
    }

    public function getStartTime(): float
    {
        return $this->startTime;
    }

    public function getEndTime(): ?float
    {
        return $this->endTime;
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
        $this->startTime = microtime(true);
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
        $this->endTime = microtime(true);
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
        $this->endTime = microtime(true);
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
        $this->endTime = microtime(true);
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

        $this->joins[] = $callback;
    }

    private function triggerJoins(): void
    {
        foreach ($this->joins as $callback) {
            $callback($this);
        }
        $this->joins = [];
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
                Pause::new();
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

        return $this->fiber->getReturn();
    }

    public function invokeOnCompletion(callable $callback): void
    {
        if ($this->isFinal()) {
            throw new RuntimeException(
                "Cannot add invoke callback to a job that has already completed.",
            );
        }

        $this->invokers[] = $callback;
    }

    public function triggerInvokers(): void
    {
        foreach ($this->invokers as $callback) {
            $callback($this);
        }
        $this->invokers = [];
    }

    public function cancelAfter(float $seconds): void
    {
        if ($this->isFinal()) {
            throw new RuntimeException(
                "Cannot set timeout for a job that has already completed.",
            );
        }
        $this->timeout = $seconds;
    }

    public function isTimedOut(): bool
    {
        if ($this->timeout === null) {
            return false;
        }
        return microtime(true) - $this->startTime >= $this->timeout;
    }
}
