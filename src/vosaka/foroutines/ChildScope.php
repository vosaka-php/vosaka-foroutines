<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Fiber;

final class ChildScope
{
    public int $id = 0;
    public ?Fiber $fiber = null;
}
