<?php

function error(string $error): void
{
    $error = 'Error: ' . $error;
    fwrite(STDERR, $error);
    error_log($error);
}

function stringFromMemoryBlock(string $value): string
{
    $position = strpos($value, "\0");
    if ($position === false) {
        return $value;
    }
    return substr($value, 0, $position);
}

function findAutoload(string $startDir, int $maxDepth = 10): string
{
    $dir = $startDir;

    for ($i = 0; $i < $maxDepth; $i++) {
        if (
            file_exists($dir . "/composer.json") &&
            file_exists($dir . "/vendor/autoload.php")
        ) {
            return $dir . "/vendor/autoload.php";
        }

        if (file_exists($dir . "/vendor/autoload.php")) {
            return $dir . "/vendor/autoload.php";
        }

        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }

    fwrite(
        STDERR,
        "Could not find vendor/autoload.php (searched from: $startDir)\n",
    );
    exit(1);
}
