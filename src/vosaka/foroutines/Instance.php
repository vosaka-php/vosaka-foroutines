<?php

declare(strict_types=1);

namespace vosaka\foroutines;

trait Instance
{
    public static function getInstance(): static
    {
        static $instance = null;

        if ($instance === null) {
            $instance = new static();
        }

        return $instance;
    }
}
