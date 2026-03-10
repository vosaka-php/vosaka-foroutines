<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Fiber;

/**
 * Deferred — A lazy wrapper for async I/O operations.
 *
 * Instead of executing I/O logic immediately, AsyncIO methods return an
 * Deferred instance. The actual work is deferred until the caller
 * invokes ->await(), making the async nature of the code explicit:
 *
 *     $body = AsyncIO::httpGet('https://example.com')->await();
 *     $data = AsyncIO::fileGetContents('/path/to/file')->await();
 *
 * When ->await() is called from within a Fiber context (e.g. inside
 * Launch::new or Async::new), the callable is executed directly in the
 * current Fiber so that internal waitForRead/waitForWrite suspensions
 * work correctly with the scheduler's pollOnce() cycle.
 *
 * When ->await() is called from a non-Fiber context (e.g. top-level code),
 * the operation is wrapped in Async::new() which creates a dedicated Fiber
 * and drives the scheduler loop to completion.
 */
final class LazyDeferred implements Deferred
{
    /**
     * @var callable The deferred I/O operation to execute on await().
     */
    private $operation;

    /**
     * @param callable $operation The I/O operation callable to defer.
     */
    public function __construct(callable $operation)
    {
        $this->operation = $operation;
    }

    /**
     * Execute the deferred I/O operation and return its result.
     *
     * - Inside a Fiber: runs the callable directly in the current Fiber
     *   context so that AsyncIO's internal waitForRead/waitForWrite
     *   suspensions integrate naturally with the scheduler's pollOnce().
     *
     * - Outside a Fiber: wraps the callable in Async::new() to create
     *   a dedicated Fiber, then calls Async::await() which drives the
     *   full scheduler loop (AsyncIO::pollOnce, WorkerPool::run,
     *   Launch::runOnce) until completion.
     *
     * @return mixed The result of the I/O operation.
     */
    public function await(): mixed
    {
        if (Fiber::getCurrent() !== null) {
            // We are inside a Fiber — execute directly so that any
            // Fiber::suspend() calls within the operation (e.g. from
            // AsyncIO::waitForRead/waitForWrite) suspend THIS fiber,
            // which the scheduler's pollOnce() knows how to resume.
            return ($this->operation)();
        }

        // We are NOT inside a Fiber — wrap in Async::new() which
        // creates a Fiber and runs a blocking scheduler loop.
        return Async::new($this->operation)->await();
    }
}
