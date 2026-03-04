<?php

ini_set("display_errors", "on");
ini_set("display_startup_errors", 1);
ini_set("error_log", "foroutines-errors-" . date("YmdH") . ".log");
error_reporting(E_ALL);

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

    throw new RuntimeException(
        "Cannot find vendor/autoload.php (searched from: $startDir)",
    );
}

require_once findAutoload(__DIR__);

require_once __DIR__ . DIRECTORY_SEPARATOR . "script_functions.php";

use Laravel\SerializableClosure\SerializableClosure;

if (!isset($argv[1])) {
    error("Shmop Key not provided");
    exit(1);
}

$key = (int) $argv[1];

$shmopInstance = shmop_open($key, "w", 0, 0);
if (!$shmopInstance) {
    error("Could not open Shmop");
    exit(1);
}

$length = shmop_size($shmopInstance);

if ($length === 0) {
    error("Shmop length cannot be zero!");
    exit(1);
}

$dataCompressed = shmop_read($shmopInstance, 0, $length);
$data = stringFromMemoryBlock($dataCompressed);

/**
 * @var SerializableClosure $serializedClosure
 * @var callable $closure
 */
$serializedClosure = unserialize($data);
$closure = $serializedClosure->getClosure();
/* ob_start(); */
try {
    $result = $closure();
    if ($result instanceof Generator) {
        foreach ($result as $value) {
            // Process each yielded value if needed
        }
        $result = $result->getReturn();
    }
} catch (Throwable $e) {
    $result = [
        "error" => $e->getMessage(),
        "trace" => $e->getTraceAsString(),
    ];
    error(
        "Error in closure execution: " .
            $e->getMessage() .
            "\n" .
            $e->getTraceAsString(),
    );
    exit(1);
}
/* ob_end_clean(); */
echo "<RESULT>" . base64_encode(serialize($result));

exit(0);
