<?php

declare(strict_types=1);

namespace vosaka\foroutines;

/**
 * Executes the given callable if the current file is the main script.
 *
 * This function checks if the current file being executed is the same as
 * the script filename. If it is, it calls the provided callable.
 *
 * This is useful for defining entry points in scripts that may be included
 * or required by other scripts, ensuring that the callable is only executed
 *
 * @param callable $callable The callable to execute if this is the main script.
 */
function main(callable $callable): void
{
    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
    $currentFile = $backtrace[0]['file'];
    if ($currentFile === realpath($_SERVER['SCRIPT_FILENAME'])) {
        $callable();
    }
}
