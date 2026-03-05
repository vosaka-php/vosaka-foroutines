<?php

declare(strict_types=1);

namespace vosaka\foroutines\supervisor;

/**
 * RestartStrategy — Defines how a Supervisor reacts when a child actor crashes.
 *
 * Inspired by Erlang/OTP supervision strategies, these three strategies
 * cover the most common fault-tolerance patterns in concurrent systems.
 *
 * Each strategy answers the question: "When child X crashes, which
 * children should the supervisor restart?"
 *
 * ┌─────────────────┬────────────────────────────────────────────────────┐
 * │ Strategy        │ Behavior on child X crash                         │
 * ├─────────────────┼────────────────────────────────────────────────────┤
 * │ ONE_FOR_ONE     │ Restart only child X.                             │
 * │                 │ Other children are unaffected.                     │
 * │                 │ Use when children are independent of each other.   │
 * ├─────────────────┼────────────────────────────────────────────────────┤
 * │ ONE_FOR_ALL     │ Stop ALL children, then restart ALL of them.      │
 * │                 │ Use when children are tightly coupled and cannot   │
 * │                 │ function correctly without each other (e.g. a     │
 * │                 │ producer + consumer pair, or a pipeline where      │
 * │                 │ shared state must be consistent across all nodes). │
 * ├─────────────────┼────────────────────────────────────────────────────┤
 * │ REST_FOR_ONE    │ Stop child X and all children started AFTER X,    │
 * │                 │ then restart them in their original start order.   │
 * │                 │ Use when children have a dependency chain: later   │
 * │                 │ children depend on earlier ones (e.g. a database   │
 * │                 │ connection actor followed by a repository actor    │
 * │                 │ that depends on it).                               │
 * └─────────────────┴────────────────────────────────────────────────────┘
 *
 * Usage:
 *   use vosaka\foroutines\supervisor\RestartStrategy;
 *   use vosaka\foroutines\supervisor\Supervisor;
 *
 *   // Independent workers — restart only the one that crashed
 *   Supervisor::new(strategy: RestartStrategy::ONE_FOR_ONE)
 *       ->child('worker-a', fn() => workerA())
 *       ->child('worker-b', fn() => workerB())
 *       ->start();
 *
 *   // Tightly coupled pipeline — restart everything if one fails
 *   Supervisor::new(strategy: RestartStrategy::ONE_FOR_ALL)
 *       ->child('producer', fn() => produce())
 *       ->child('transformer', fn() => transform())
 *       ->child('consumer', fn() => consume())
 *       ->start();
 *
 *   // Dependency chain — restart the crashed child and everything after it
 *   Supervisor::new(strategy: RestartStrategy::REST_FOR_ONE)
 *       ->child('db-conn', fn() => dbConnect())
 *       ->child('repo', fn() => repository())      // depends on db-conn
 *       ->child('api', fn() => apiHandler())        // depends on repo
 *       ->start();
 *
 * @see Supervisor          The supervisor that uses these strategies.
 * @see SupervisorSpec      The child specification that defines restart behavior.
 */
enum RestartStrategy: string
{
    /**
     * Restart only the crashed child.
     *
     * The simplest and most common strategy. Each child is treated as
     * an independent unit — a failure in one does not affect the others.
     *
     * Use when:
     *   - Children are stateless or have independent state.
     *   - Children do not communicate with each other or only via
     *     well-defined message protocols that tolerate restarts.
     *   - You want maximum availability — only the broken part restarts.
     *
     * Example: A pool of HTTP request handler actors where each handles
     * requests independently.
     */
    case ONE_FOR_ONE = 'one_for_one';

    /**
     * Stop ALL children, then restart ALL of them in their original order.
     *
     * The nuclear option — used when children are so tightly coupled that
     * the failure of any single child renders the others' state invalid.
     * All children are stopped (in reverse start order for clean teardown),
     * then restarted (in original start order for proper initialization).
     *
     * Use when:
     *   - Children share mutable state that must be consistent.
     *   - A failure in one child can leave others in an inconsistent state.
     *   - The group of children represents a single logical unit of work.
     *
     * Example: A producer-consumer pair where the consumer holds buffered
     * data from the producer. If the producer crashes, the consumer's
     * buffer is stale and must be discarded.
     *
     * Warning: This is the most disruptive strategy. All in-progress work
     * across all children is lost on every restart. Use sparingly.
     */
    case ONE_FOR_ALL = 'one_for_all';

    /**
     * Stop the crashed child and all children started AFTER it, then
     * restart them in their original start order.
     *
     * A middle ground between ONE_FOR_ONE and ONE_FOR_ALL. Assumes that
     * children have a sequential dependency: child N+1 may depend on
     * child N, but not vice versa. When child N crashes, children N+1,
     * N+2, ... are also stopped (in reverse order) and restarted
     * (in forward order), but children 0..N-1 are left untouched.
     *
     * Use when:
     *   - Children form a pipeline or dependency chain.
     *   - Later children depend on earlier ones being in a valid state.
     *   - Earlier children are independent of later ones.
     *
     * Example: [DBConnection] → [Repository] → [APIHandler]
     *   If Repository crashes, APIHandler (which depends on it) must also
     *   restart, but DBConnection can keep running since it's independent.
     */
    case REST_FOR_ONE = 'rest_for_one';

    // ═════════════════════════════════════════════════════════════════
    //  Utility methods
    // ═════════════════════════════════════════════════════════════════

    /**
     * Get a human-readable description of this strategy.
     *
     * @return string A brief explanation of the strategy's behavior.
     */
    public function description(): string
    {
        return match ($this) {
            self::ONE_FOR_ONE => 'Restart only the crashed child.',
            self::ONE_FOR_ALL => 'Stop all children, then restart all.',
            self::REST_FOR_ONE => 'Stop crashed child and all after it, then restart them.',
        };
    }

    /**
     * Check if this strategy requires stopping other children when one crashes.
     *
     * @return bool True if other children may be affected by a single crash.
     */
    public function affectsOtherChildren(): bool
    {
        return match ($this) {
            self::ONE_FOR_ONE => false,
            self::ONE_FOR_ALL => true,
            self::REST_FOR_ONE => true,
        };
    }

    /**
     * Check if this strategy requires ordering awareness.
     *
     * ONE_FOR_ONE doesn't care about child order.
     * ONE_FOR_ALL and REST_FOR_ONE need to know the original start order
     * to stop in reverse and restart in forward order.
     *
     * @return bool True if the supervisor must track child start order.
     */
    public function isOrderSensitive(): bool
    {
        return match ($this) {
            self::ONE_FOR_ONE => false,
            self::ONE_FOR_ALL => true,
            self::REST_FOR_ONE => true,
        };
    }
}
