<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Attribute;

#[Attribute(Attribute::TARGET_FUNCTION)]
final class AsyncMain {}
