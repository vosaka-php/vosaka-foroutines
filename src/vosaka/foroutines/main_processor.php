<?php

register_shutdown_function(function () {
    $found = [];
    $scriptFile = realpath($_SERVER["SCRIPT_FILENAME"]);

    foreach (get_defined_functions()["user"] as $funcName) {
        $ref = new ReflectionFunction($funcName);

        if (realpath($ref->getFileName()) !== $scriptFile) {
            continue;
        }

        foreach ($ref->getAttributes() as $attr) {
            if ($attr->getName() === \vosaka\foroutines\AsyncMain::class) {
                $found[] = $funcName;
            }
        }
    }

    if (count($found) === 0) {
        return;
    }
    if (count($found) > 1) {
        throw new \RuntimeException("Multiple #[AsyncMain] found.");
    }

    $found[0]();
});
