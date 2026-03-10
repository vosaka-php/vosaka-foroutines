<?php

declare(strict_types=1);

namespace vosaka\foroutines;

/**
 * Interface Deferred
 *
 * Represents an asynchronous task or operation that can be awaited.
 */
interface Deferred
{
    /**
     * Awaits the result of the asynchronous operation.
     *
     * @return mixed The result of the operation.
     */
    public function await(): mixed;
}
