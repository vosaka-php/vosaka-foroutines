<?php

declare(strict_types=1);

require __DIR__ . "/../vendor/autoload.php";

use vosaka\foroutines\AsyncMain;
use vosaka\foroutines\Pause;
use vosaka\foroutines\RunBlocking;

#[AsyncMain]
function main(): void
{
    RunBlocking::new(function (): void {
        for ($i = 0; $i < 100000; $i++) {
            var_dump("Hello $i");
            Pause::new();
        }
    });
}
