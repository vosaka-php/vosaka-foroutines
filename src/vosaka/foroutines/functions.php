<?php

declare(strict_types=1);

namespace vosaka\foroutines;

function main(callable $callable): void
{
    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
    $currentFile = $backtrace[0]['file'];
    if ($currentFile === realpath($_SERVER['SCRIPT_FILENAME'])) {
        $callable();
    }
}
