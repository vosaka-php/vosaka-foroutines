<?php

declare(strict_types=1);

namespace vosaka\foroutines\supervisor;

/**
 * Restart strategy for Supervisor trees.
 *
 * Determines what happens when a supervised child crashes:
 *
 *   - ONE_FOR_ONE:  Only the crashed child is restarted.
 *                   Other children continue running undisturbed.
 *                   Best for independent children.
 *
 *   - ONE_FOR_ALL:  ALL children are stopped and restarted.
 *                   Best when children are interdependent and
 *                   a crash in one invalidates the state of others.
 *
 *   - REST_FOR_ONE: The crashed child and all children started
 *                   AFTER it are stopped and restarted.
 *                   Best for ordered dependencies (child B depends
 *                   on child A, so if A crashes, B must restart too).
 *
 * Inspired by Erlang/OTP Supervisor strategies.
 */
enum RestartStrategy
{
    case ONE_FOR_ONE;
    case ONE_FOR_ALL;
    case REST_FOR_ONE;
}
