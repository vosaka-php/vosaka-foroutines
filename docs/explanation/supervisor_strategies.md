# Explanation: Supervisor Strategies

Fault tolerance is a core pillar of structured concurrency. A **Supervisor** monitors a set of child tasks and decides how to react when one of them fails.

## Restart Strategies

### 1. `ONE_FOR_ONE` (Default)
If a child task crashes, only that specific task is restarted. Other siblings are unaffected.
*Use case: Independent workers that don't rely on each other's state.*

### 2. `ONE_FOR_ALL`
If one child crashes, all other children are stopped and then all are restarted together.
*Use case: A group of tasks that must remain in sync or depend on a shared initialization.*

### 3. `REST_FOR_ONE`
If a child crashes, that task and all tasks started *after* it are restarted.
*Use case: Linear dependencies where Task B depends on Task A, but Task A doesn't depend on Task B.*

## Restart Budget
Supervisors track the number of restarts within a timeframe. If a task crashes too frequently (e.g., 5 times in 10 seconds), the supervisor gives up and fails itself, escalating the error up the tree.
