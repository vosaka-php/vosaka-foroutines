<?php

declare(strict_types=1);

namespace vosaka\foroutines\supervisor;

/**
 * RestartType — Defines when a supervised child should be restarted.
 *
 * Inspired by Erlang/OTP's child_spec restart field, this enum categorizes
 * children by their restart importance:
 *
 * ┌─────────────┬────────────────────────────────────────────────────────┐
 * │ Type        │ Restart behavior                                       │
 * ├─────────────┼────────────────────────────────────────────────────────┤
 * │ PERMANENT   │ Always restart, regardless of exit reason.             │
 * │             │ Whether the child exits normally (graceful stop) or    │
 * │             │ abnormally (uncaught exception), it is restarted.      │
 * │             │                                                        │
 * │             │ Use for: long-running services, daemons, message       │
 * │             │ consumers, HTTP servers — anything that should "always  │
 * │             │ be running" for the lifetime of the supervisor.        │
 * ├─────────────┼────────────────────────────────────────────────────────┤
 * │ TRANSIENT   │ Restart only on abnormal exit (error/exception).       │
 * │             │ If the child exits normally (completes its work or is  │
 * │             │ stopped gracefully), it is NOT restarted.              │
 * │             │                                                        │
 * │             │ Use for: batch jobs, one-time tasks that must succeed, │
 * │             │ initialization routines that should retry on failure   │
 * │             │ but not repeat after success.                          │
 * ├─────────────┼────────────────────────────────────────────────────────┤
 * │ TEMPORARY   │ Never restart, regardless of exit reason.              │
 * │             │ Once the child stops (normally or abnormally), it is   │
 * │             │ gone and will not be recreated by the supervisor.      │
 * │             │                                                        │
 * │             │ Use for: fire-and-forget tasks, optional background    │
 * │             │ work where failure is acceptable and handled elsewhere │
 * │             │ (e.g. via invokeOnCompletion callbacks or logging).    │
 * └─────────────┴────────────────────────────────────────────────────────┘
 *
 * The shouldRestart() method encapsulates the decision logic so that the
 * Supervisor and ChildSpec don't need to switch on the enum value directly.
 *
 * Usage:
 *   // In a ChildSpec builder:
 *   ChildSpec::actor('worker', new WorkerBehavior())->permanent();
 *   ChildSpec::closure('job', fn() => runJob())->transient();
 *   ChildSpec::closure('optional', fn() => doOptional())->temporary();
 *
 *   // Or explicitly:
 *   $spec->withRestartType(RestartType::TRANSIENT);
 *
 *   // Checking restart decision:
 *   if ($restartType->shouldRestart($errorOrNull)) { ... }
 *
 * @see ChildSpec        Uses RestartType to configure child restart behavior.
 * @see Supervisor       Consults shouldRestart() when a child exits.
 * @see RestartStrategy  Determines WHICH children to restart (orthogonal to this enum).
 */
enum RestartType: string
{
    /**
     * Always restart the child, regardless of exit reason.
     *
     * Normal exit → restart.
     * Abnormal exit (exception) → restart.
     * Explicit stop (requestStop) → restart.
     *
     * This is the default for most supervised children and is the safest
     * choice when in doubt. The restart intensity limit (maxRestarts within
     * withinSeconds, configured on the ChildSpec) still applies as a safety
     * net to prevent infinite crash loops.
     */
    case PERMANENT = 'permanent';

    /**
     * Restart the child only on abnormal exit (when an error/exception caused the stop).
     *
     * Normal exit (null error) → do NOT restart.
     * Abnormal exit (non-null Throwable) → restart.
     *
     * This is the right choice for tasks that have a natural completion
     * point: they should run until they finish, but if they crash before
     * finishing, they should be retried.
     *
     * Examples:
     *   - A database migration actor: runs once, succeeds → done.
     *     Crashes mid-migration → retry.
     *   - A file-processing job: processes all files → done.
     *     Encounters a corrupt file and throws → retry.
     */
    case TRANSIENT = 'transient';

    /**
     * Never restart the child, regardless of exit reason.
     *
     * Normal exit → do NOT restart.
     * Abnormal exit → do NOT restart.
     *
     * The child runs exactly once. If it crashes, the error is logged
     * (by the supervisor) but no restart is attempted. The ChildSpec
     * remains registered with the supervisor so its stats can be
     * inspected, but its retired flag is effectively always true.
     *
     * Use this for:
     *   - Optional background tasks where failure is acceptable.
     *   - One-shot initialization that has fallback logic elsewhere.
     *   - Children that manage their own retry logic internally.
     */
    case TEMPORARY = 'temporary';

    // ═════════════════════════════════════════════════════════════════
    //  Decision logic
    // ═════════════════════════════════════════════════════════════════

    /**
     * Determine whether a child with this restart type should be restarted
     * given the exit reason.
     *
     * This is the core decision method. It encapsulates the restart policy
     * so that callers (ChildSpec, Supervisor) don't need to switch on the
     * enum value themselves.
     *
     * @param \Throwable|null $error The error that caused the child to stop,
     *                               or null if the child exited normally
     *                               (graceful stop, completed its work).
     * @return bool True if the child should be restarted, false otherwise.
     */
    public function shouldRestart(?\Throwable $error): bool
    {
        return match ($this) {
            self::PERMANENT => true,
            self::TRANSIENT => $error !== null,
            self::TEMPORARY => false,
        };
    }

    // ═════════════════════════════════════════════════════════════════
    //  Introspection / utility
    // ═════════════════════════════════════════════════════════════════

    /**
     * Get a human-readable description of this restart type.
     *
     * @return string A brief explanation of the restart behavior.
     */
    public function description(): string
    {
        return match ($this) {
            self::PERMANENT => 'Always restart, regardless of exit reason.',
            self::TRANSIENT => 'Restart only on abnormal exit (error/exception).',
            self::TEMPORARY => 'Never restart.',
        };
    }

    /**
     * Check if this restart type can ever result in a restart.
     *
     * TEMPORARY always returns false from shouldRestart(), so it can
     * never trigger a restart. This method lets callers skip restart
     * preparation work (e.g. recording timestamps) for TEMPORARY children.
     *
     * @return bool True if restarts are possible under some circumstances.
     */
    public function canRestart(): bool
    {
        return match ($this) {
            self::PERMANENT => true,
            self::TRANSIENT => true,
            self::TEMPORARY => false,
        };
    }

    /**
     * Check if a normal (graceful, error-free) exit triggers a restart.
     *
     * Only PERMANENT restarts on normal exit. TRANSIENT and TEMPORARY
     * both let normal exits pass without restarting.
     *
     * @return bool True if a normal exit would trigger a restart.
     */
    public function restartsOnNormalExit(): bool
    {
        return $this === self::PERMANENT;
    }

    /**
     * Check if an abnormal exit (error) triggers a restart.
     *
     * PERMANENT and TRANSIENT both restart on errors.
     * TEMPORARY never restarts.
     *
     * @return bool True if an abnormal exit would trigger a restart.
     */
    public function restartsOnAbnormalExit(): bool
    {
        return match ($this) {
            self::PERMANENT => true,
            self::TRANSIENT => true,
            self::TEMPORARY => false,
        };
    }
}
