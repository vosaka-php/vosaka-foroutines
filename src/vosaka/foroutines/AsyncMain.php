<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Attribute;

/**
 * This attribute is used to mark the main function of the application.
 * It is used to identify the entry point of the application.
 * 
 * Example:
 * ```php
 * #[AsyncMain]
 * function main()
 * {
 *    // code
 * }
 * ```
 */
#[Attribute(Attribute::TARGET_FUNCTION)]
final class AsyncMain
{
    // Nope
}
