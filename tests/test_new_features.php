<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Launch;
use vosaka\foroutines\Delay;
use vosaka\foroutines\Pause;
use vosaka\foroutines\Dispatchers;
use vosaka\foroutines\Thread;
use vosaka\foroutines\WorkerLifecycle;
use vosaka\foroutines\FiberPool;
use vosaka\foroutines\actor\Actor;
use vosaka\foroutines\actor\ActorBehavior;
use vosaka\foroutines\actor\AbstractActorBehavior;
use vosaka\foroutines\actor\ActorContext;
use vosaka\foroutines\actor\ActorSystem;
use vosaka\foroutines\actor\Message;
use vosaka\foroutines\supervisor\Supervisor;
use vosaka\foroutines\supervisor\RestartStrategy;
use vosaka\foroutines\supervisor\RestartType;
use vosaka\foroutines\supervisor\ChildSpec;

// ═══════════════════════════════════════════════════════════════════
//  Test helpers
// ═══════════════════════════════════════════════════════════════════

$passed = 0;
$failed = 0;
$errors = [];

function test_assert(bool $condition, string $label, array &$passed, array &$failed, array &$errors): void
{
    if ($condition) {
        $passed[0]++;
        echo "  ✅ {$label}\n";
    } else {
        $failed[0]++;
        $errors[] = $label;
        echo "  ❌ {$label}\n";
    }
}

// Wrap the counters in arrays so they can be passed by reference to the closure
$passedArr = [&$passed];
$failedArr = [&$failed];

function assert_true(bool $condition, string $label): void
{
    global $passed, $failed, $errors;
    if ($condition) {
        $passed++;
        echo "  ✅ {$label}\n";
    } else {
        $failed++;
        $errors[] = $label;
        echo "  ❌ {$label}\n";
    }
}

function assert_equals(mixed $expected, mixed $actual, string $label): void
{
    global $passed, $failed, $errors;
    if ($expected === $actual) {
        $passed++;
        echo "  ✅ {$label}\n";
    } else {
        $failed++;
        $detail = "{$label} (expected: " . var_export($expected, true) . ", got: " . var_export($actual, true) . ")";
        $errors[] = $detail;
        echo "  ❌ {$detail}\n";
    }
}

function section(string $title): void
{
    echo "\n" . str_repeat('═', 60) . "\n";
    echo "  {$title}\n";
    echo str_repeat('═', 60) . "\n";
}

// ═══════════════════════════════════════════════════════════════════
//  Test 1: Worker Respawn Backoff
// ═══════════════════════════════════════════════════════════════════

section('1. Worker Respawn Backoff');

// Test backoff configuration
WorkerLifecycle::setMaxRetries(5);
assert_equals(5, WorkerLifecycle::getMaxRetries(), 'setMaxRetries(5) stores correctly');

WorkerLifecycle::setMaxRetries(3);
assert_equals(3, WorkerLifecycle::getMaxRetries(), 'setMaxRetries(3) stores correctly');

// Test minimum clamp
WorkerLifecycle::setMaxRetries(0);
assert_equals(1, WorkerLifecycle::getMaxRetries(), 'setMaxRetries(0) clamps to 1');

WorkerLifecycle::setMaxRetries(5);

// Test backoff state tracking
assert_equals(0, WorkerLifecycle::getRespawnAttempts(999), 'Fresh worker has 0 attempts');
assert_true(!WorkerLifecycle::isPermanentlyDead(999), 'Fresh worker is not permanently dead');

// Test resetBackoff
WorkerLifecycle::resetBackoff(999);
assert_equals(0, WorkerLifecycle::getRespawnAttempts(999), 'resetBackoff clears attempts');
assert_true(!WorkerLifecycle::isPermanentlyDead(999), 'resetBackoff clears permanently dead flag');

// Test resetAllBackoff
WorkerLifecycle::resetAllBackoff();
assert_equals(0, WorkerLifecycle::getRespawnAttempts(0), 'resetAllBackoff clears all');
assert_equals(0, WorkerLifecycle::getRespawnAttempts(1), 'resetAllBackoff clears index 1');

echo "\n  [Backoff deferred-respawn behavior is verified in integration with actual WorkerPool]\n";

// ═══════════════════════════════════════════════════════════════════
//  Test 2: FiberPool — Basic functionality
// ═══════════════════════════════════════════════════════════════════

section('2. FiberPool — Basic');

RunBlocking::new(function () {
    // Create a pool with 5 fibers
    $pool = FiberPool::new(size: 5);

    assert_equals(5, $pool->size(), 'FiberPool initial size is 5');
    assert_equals(5, $pool->idleCount(), 'All fibers start idle');
    assert_equals(0, $pool->activeCount(), 'No active fibers initially');
    assert_equals(0, $pool->pendingCount(), 'No pending tasks initially');
    assert_true(!$pool->isShutdown(), 'Pool is not shut down');

    // Submit a simple task
    $ticket1 = $pool->submit(fn() => 42);
    assert_true($ticket1 > 0, 'submit() returns a positive ticket ID');

    // Get the result
    $result1 = $pool->getResult($ticket1);
    assert_equals(42, $result1, 'Simple task returns correct result');

    // Submit multiple tasks
    $ticket2 = $pool->submit(fn() => 'hello');
    $ticket3 = $pool->submit(fn() => [1, 2, 3]);
    $ticket4 = $pool->submit(fn() => true);

    $result2 = $pool->getResult($ticket2);
    $result3 = $pool->getResult($ticket3);
    $result4 = $pool->getResult($ticket4);

    assert_equals('hello', $result2, 'String result correct');
    assert_equals([1, 2, 3], $result3, 'Array result correct');
    assert_equals(true, $result4, 'Boolean result correct');

    // Close the pool
    $pool->close();
    assert_true($pool->isShutdown(), 'Pool is shut down after close()');

    // Verify submit throws after shutdown
    $threw = false;
    try {
        $pool->submit(fn() => 'should fail');
    } catch (RuntimeException $e) {
        $threw = true;
    }
    assert_true($threw, 'submit() throws after shutdown');
});

// ═══════════════════════════════════════════════════════════════════
//  Test 3: FiberPool — Computation-heavy tasks
// ═══════════════════════════════════════════════════════════════════

section('3. FiberPool — Computation');

RunBlocking::new(function () {
    $pool = FiberPool::new(size: 4);

    // Submit computation tasks
    $tickets = [];
    for ($i = 1; $i <= 10; $i++) {
        $val = $i;
        $tickets[$i] = $pool->submit(fn() => $val * $val);
    }

    // Collect results
    $results = [];
    foreach ($tickets as $i => $ticket) {
        $results[$i] = $pool->getResult($ticket);
    }

    // Verify all results
    $allCorrect = true;
    for ($i = 1; $i <= 10; $i++) {
        if ($results[$i] !== $i * $i) {
            $allCorrect = false;
            break;
        }
    }
    assert_true($allCorrect, '10 squared computations all correct');

    assert_equals(4, $pool->size(), 'Pool size unchanged after tasks (4 fibers reused)');

    $pool->close();
});

// ═══════════════════════════════════════════════════════════════════
//  Test 4: FiberPool — drainAll and awaitAll
// ═══════════════════════════════════════════════════════════════════

section('4. FiberPool — drainAll / awaitAll');

RunBlocking::new(function () {
    $pool = FiberPool::new(size: 3);

    // Submit with fluent chaining via submitChain
    $pool->submitChain(fn() => 'a')
         ->submitChain(fn() => 'b')
         ->submitChain(fn() => 'c');

    // drainAll should complete all tasks
    $pool->drainAll();

    assert_equals(3, $pool->idleCount(), 'All fibers idle after drainAll');
    assert_equals(0, $pool->pendingCount(), 'No pending tasks after drainAll');

    $pool->close();
});

// ═══════════════════════════════════════════════════════════════════
//  Test 5: FiberPool — Auto-scaling
// ═══════════════════════════════════════════════════════════════════

section('5. FiberPool — Auto-scaling');

RunBlocking::new(function () {
    $pool = FiberPool::new(size: 2, maxSize: 8, autoScaling: true);

    assert_equals(2, $pool->size(), 'Initial size is 2');
    assert_equals(8, $pool->getMaxSize(), 'Max size is 8');

    // Submit more tasks than initial pool size to trigger scale-up
    $tickets = [];
    for ($i = 0; $i < 6; $i++) {
        $val = $i;
        $tickets[] = $pool->submit(function () use ($val) {
            // Simulate some work
            $x = 0;
            for ($j = 0; $j < 100; $j++) {
                $x += $j;
            }
            return $val;
        });
    }

    // Collect all results
    foreach ($tickets as $ticket) {
        $pool->getResult($ticket);
    }

    // Pool should have scaled up (exact number depends on timing/cooldowns)
    assert_true($pool->size() >= 2, 'Pool size is at least initial (2)');
    assert_true($pool->size() <= 8, 'Pool size does not exceed max (8)');

    $pool->close();
});

// ═══════════════════════════════════════════════════════════════════
//  Test 6: FiberPool — Error handling
// ═══════════════════════════════════════════════════════════════════

section('6. FiberPool — Error handling');

RunBlocking::new(function () {
    $pool = FiberPool::new(size: 3);

    // Submit a task that throws
    $ticket = $pool->submit(function () {
        throw new RuntimeException('intentional test error');
    });

    $threw = false;
    try {
        $pool->getResult($ticket);
    } catch (RuntimeException $e) {
        $threw = true;
        assert_true(
            str_contains($e->getMessage(), 'intentional test error'),
            'Error message propagates correctly'
        );
    }
    assert_true($threw, 'getResult() throws on task error');

    // Pool should still be functional after error
    $ticket2 = $pool->submit(fn() => 'recovered');
    $result = $pool->getResult($ticket2);
    assert_equals('recovered', $result, 'Pool works after error recovery');

    $pool->close();
});

// ═══════════════════════════════════════════════════════════════════
//  Test 7: FiberPool — Configuration fluent API
// ═══════════════════════════════════════════════════════════════════

section('7. FiberPool — Configuration');

RunBlocking::new(function () {
    $pool = FiberPool::new(size: 5, autoScaling: false);

    $pool->setMaxSize(20)
         ->setAutoScaling(true)
         ->setScaleUpCooldown(0.1)
         ->setScaleDownCooldown(1.0)
         ->setIdleTimeout(5.0);

    assert_equals(20, $pool->getMaxSize(), 'setMaxSize(20) applied');
    assert_equals(5, $pool->size(), 'Size unchanged by config methods');

    $pool->close();
});

// ═══════════════════════════════════════════════════════════════════
//  Test 8: Message — creation and properties
// ═══════════════════════════════════════════════════════════════════

section('8. Actor Model — Message');

$msg = Message::of('ping', ['data' => 42]);
assert_equals('ping', $msg->type, 'Message::of sets type');
assert_equals(['data' => 42], $msg->payload, 'Message::of sets payload');
assert_true($msg->timestamp > 0.0, 'Message::of sets timestamp');
assert_true($msg->sender === null, 'Message::of has no sender by default');
assert_true($msg->correlationId === null, 'Message::of has no correlationId');
assert_true($msg->isType('ping'), 'isType("ping") returns true');
assert_true(!$msg->isType('pong'), 'isType("pong") returns false');
assert_true(!$msg->isCorrelated(), 'isCorrelated() false for simple message');
assert_true(!$msg->hasSender(), 'hasSender() false when no sender');

$msgWithSender = Message::of('greet', 'hello', sender: 'actorA');
assert_equals('actorA', $msgWithSender->sender, 'Message::of with sender');
assert_true($msgWithSender->hasSender(), 'hasSender() true when sender set');

// Request message (with correlation ID)
$request = Message::request('ask', 'question', sender: 'actorB');
assert_true($request->isCorrelated(), 'Request message is correlated');
assert_true($request->correlationId !== null, 'Request has correlationId');
assert_equals('actorB', $request->sender, 'Request sender is set');

// Reply message (preserves correlation ID)
$reply = Message::reply($request, 'answer', 'response', sender: 'actorC');
assert_equals($request->correlationId, $reply->correlationId, 'Reply preserves correlationId');
assert_equals('actorC', $reply->sender, 'Reply has own sender');

// __toString
$str = (string) $msg;
assert_true(str_contains($str, 'Message('), '__toString starts with Message(');
assert_true(str_contains($str, 'type=ping'), '__toString contains type');

// ═══════════════════════════════════════════════════════════════════
//  Test 9: Actor — Basic lifecycle
// ═══════════════════════════════════════════════════════════════════

section('9. Actor Model — Basic lifecycle');

// Define a simple counter behavior
class CounterBehavior extends AbstractActorBehavior
{
    public int $count = 0;
    public array $received = [];

    public function handle(Message $message, ActorContext $context): void
    {
        match ($message->type) {
            'increment' => $this->count++,
            'decrement' => $this->count--,
            'get' => $context->reply($message, 'count', $this->count),
            'record' => $this->received[] = $message->payload,
            'stop' => $context->stop(),
            default => $this->unhandled($message, $context),
        };
    }
}

RunBlocking::new(function () {
    $behavior = new CounterBehavior();
    $actor = new Actor('counter', $behavior, capacity: 10);

    assert_true(!$actor->isStarted(), 'Actor not started before start()');
    assert_true(!$actor->isAlive(), 'Actor not alive before start()');
    assert_true($actor->isStopped() === false, 'Actor not stopped initially');
    assert_equals('counter', $actor->name, 'Actor name is correct');

    $actor->start();

    assert_true($actor->isStarted(), 'Actor started after start()');
    assert_true($actor->isAlive(), 'Actor alive after start()');

    // Send messages
    $actor->send(Message::of('increment'));
    $actor->send(Message::of('increment'));
    $actor->send(Message::of('increment'));
    $actor->send(Message::of('decrement'));

    // Give the actor time to process
    Delay::new(50);

    assert_equals(2, $behavior->count, 'Counter is 2 (3 increments - 1 decrement)');

    // Record some data
    $actor->send(Message::of('record', 'hello'));
    $actor->send(Message::of('record', 'world'));

    Delay::new(50);

    assert_equals(['hello', 'world'], $behavior->received, 'Recorded messages correct');

    // Stop the actor
    $actor->send(Message::of('stop'));
    Delay::new(50);

    assert_true($actor->isStopped(), 'Actor stopped after stop message');
    assert_true(!$actor->isAlive(), 'Actor not alive after stop');
    assert_true($actor->getProcessedCount() > 0, 'Processed count > 0');
});

// ═══════════════════════════════════════════════════════════════════
//  Test 10: Actor — trySend
// ═══════════════════════════════════════════════════════════════════

section('10. Actor Model — trySend');

RunBlocking::new(function () {
    $behavior = new CounterBehavior();
    $actor = new Actor('trytest', $behavior, capacity: 2);
    $actor->start();

    $sent1 = $actor->trySend(Message::of('increment'));
    assert_true($sent1, 'trySend succeeds when mailbox has space');

    $actor->send(Message::of('stop'));
    Delay::new(50);

    $sent2 = $actor->trySend(Message::of('increment'));
    assert_true(!$sent2, 'trySend fails when mailbox is closed');
});

// ═══════════════════════════════════════════════════════════════════
//  Test 11: Actor — Stats
// ═══════════════════════════════════════════════════════════════════

section('11. Actor Model — Stats');

RunBlocking::new(function () {
    $behavior = new CounterBehavior();
    $actor = new Actor('stats-test', $behavior, capacity: 20);
    $actor->start();

    for ($i = 0; $i < 5; $i++) {
        $actor->send(Message::of('increment'));
    }

    Delay::new(50);

    $stats = $actor->getStats();
    assert_equals('stats-test', $stats['name'], 'Stats: name correct');
    assert_true($stats['started'], 'Stats: started=true');
    assert_true($stats['alive'], 'Stats: alive=true');
    assert_true($stats['processedCount'] >= 5, 'Stats: processedCount >= 5');
    assert_equals(0, $stats['errorCount'], 'Stats: errorCount=0');
    assert_equals(20, $stats['mailboxCapacity'], 'Stats: mailboxCapacity=20');

    $actor->requestStop();
    Delay::new(50);
});

// ═══════════════════════════════════════════════════════════════════
//  Test 12: ActorSystem — Basic
// ═══════════════════════════════════════════════════════════════════

section('12. ActorSystem — Basic');

RunBlocking::new(function () {
    $system = ActorSystem::create('test-system');

    assert_equals('test-system', $system->name, 'System name correct');
    assert_true(!$system->isShutdown(), 'System not shut down initially');
    assert_equals(0, $system->actorCount(), 'No actors initially');

    // Spawn actors
    $system->spawn('actor-a', new CounterBehavior(), capacity: 10);
    $system->spawn('actor-b', new CounterBehavior(), capacity: 10);

    assert_equals(2, $system->actorCount(), '2 actors after spawn');
    assert_equals(2, $system->aliveCount(), '2 alive actors');
    assert_true($system->hasActor('actor-a'), 'hasActor("actor-a") true');
    assert_true($system->hasActor('actor-b'), 'hasActor("actor-b") true');
    assert_true(!$system->hasActor('actor-c'), 'hasActor("actor-c") false');

    // Get actor
    $actorA = $system->getActor('actor-a');
    assert_true($actorA !== null, 'getActor returns non-null');
    assert_equals('actor-a', $actorA->name, 'getActor returns correct actor');

    // Send messages via system
    $sent = $system->tell('actor-a', Message::of('increment'));
    assert_true($sent, 'tell() returns true');

    $sent2 = $system->tell('nonexistent', Message::of('test'));
    assert_true(!$sent2, 'tell() to nonexistent returns false');

    // Get all names
    $names = $system->getAllActorNames();
    assert_true(in_array('actor-a', $names), 'getAllActorNames includes actor-a');
    assert_true(in_array('actor-b', $names), 'getAllActorNames includes actor-b');

    // Duplicate name should throw
    $threw = false;
    try {
        $system->spawn('actor-a', new CounterBehavior());
    } catch (RuntimeException) {
        $threw = true;
    }
    assert_true($threw, 'Duplicate name throws RuntimeException');

    // Shutdown
    $system->shutdown(gracePeriod: 2.0);
    assert_true($system->isShutdown(), 'System is shut down');
    assert_equals(0, $system->actorCount(), 'No actors after shutdown');
});

// ═══════════════════════════════════════════════════════════════════
//  Test 13: ActorSystem — Actor-to-Actor communication
// ═══════════════════════════════════════════════════════════════════

section('13. ActorSystem — Actor-to-Actor communication');

class EchoBehavior extends AbstractActorBehavior
{
    public array $echoed = [];

    public function handle(Message $message, ActorContext $context): void
    {
        match ($message->type) {
            'echo' => $this->handleEcho($message, $context),
            'echoed' => $this->echoed[] = $message->payload,
            'stop' => $context->stop(),
            default => null,
        };
    }

    private function handleEcho(Message $message, ActorContext $context): void
    {
        // Echo back to sender
        if ($message->hasSender()) {
            $context->reply($message, 'echoed', $message->payload);
        }
    }
}

RunBlocking::new(function () {
    $system = ActorSystem::create('echo-test');

    $senderBehavior = new EchoBehavior();
    $echoBehavior = new EchoBehavior();

    $system->spawn('sender', $senderBehavior, capacity: 20);
    $system->spawn('echoer', $echoBehavior, capacity: 20);

    // sender sends an 'echo' message to echoer
    $system->tell('echoer', Message::of('echo', 'hello!', sender: 'sender'));

    Delay::new(100);

    // The echoer should have replied to sender
    assert_true(
        count($senderBehavior->echoed) > 0,
        'Sender received echo reply'
    );

    if (count($senderBehavior->echoed) > 0) {
        assert_equals('hello!', $senderBehavior->echoed[0], 'Echo reply payload correct');
    }

    $system->shutdown(gracePeriod: 2.0);
});

// ═══════════════════════════════════════════════════════════════════
//  Test 14: ActorSystem — Dead letter handling
// ═══════════════════════════════════════════════════════════════════

section('14. ActorSystem — Dead letters');

RunBlocking::new(function () {
    $system = ActorSystem::create('dead-letter-test');
    $deadLetters = [];

    $system->setDeadLetterHandler(function (string $target, Message $msg) use (&$deadLetters) {
        $deadLetters[] = ['target' => $target, 'type' => $msg->type];
    });

    // Send to nonexistent actor
    $system->tell('ghost', Message::of('hello'));
    $system->tell('phantom', Message::of('world'));

    assert_equals(2, count($deadLetters), 'Dead letter handler received 2 messages');
    assert_equals('ghost', $deadLetters[0]['target'], 'First dead letter target correct');
    assert_equals('phantom', $deadLetters[1]['target'], 'Second dead letter target correct');
    assert_equals(2, $system->getDeadLetterCount(), 'Dead letter count is 2');

    $system->shutdown();
});

// ═══════════════════════════════════════════════════════════════════
//  Test 15: ActorSystem — Broadcast
// ═══════════════════════════════════════════════════════════════════

section('15. ActorSystem — Broadcast');

RunBlocking::new(function () {
    $system = ActorSystem::create('broadcast-test');

    $b1 = new CounterBehavior();
    $b2 = new CounterBehavior();
    $b3 = new CounterBehavior();

    $system->spawn('c1', $b1, capacity: 10);
    $system->spawn('c2', $b2, capacity: 10);
    $system->spawn('c3', $b3, capacity: 10);

    // Broadcast increment to all
    $system->broadcast(Message::of('increment'));

    Delay::new(100);

    assert_equals(1, $b1->count, 'c1 received broadcast');
    assert_equals(1, $b2->count, 'c2 received broadcast');
    assert_equals(1, $b3->count, 'c3 received broadcast');

    // Broadcast excluding c2
    $system->broadcast(Message::of('increment'), excludeName: 'c2');

    Delay::new(100);

    assert_equals(2, $b1->count, 'c1 received second broadcast');
    assert_equals(1, $b2->count, 'c2 excluded from second broadcast');
    assert_equals(2, $b3->count, 'c3 received second broadcast');

    $system->shutdown(gracePeriod: 2.0);
});

// ═══════════════════════════════════════════════════════════════════
//  Test 16: ActorSystem — Stats
// ═══════════════════════════════════════════════════════════════════

section('16. ActorSystem — Stats');

RunBlocking::new(function () {
    $system = ActorSystem::create('stats-system');

    $system->spawn('w1', new CounterBehavior(), capacity: 5);
    $system->spawn('w2', new CounterBehavior(), capacity: 5);

    $stats = $system->getStats();
    assert_equals('stats-system', $stats['name'], 'Stats: system name');
    assert_equals(2, $stats['actorCount'], 'Stats: actorCount=2');
    assert_equals(2, $stats['aliveCount'], 'Stats: aliveCount=2');
    assert_true($stats['uptimeSeconds'] >= 0.0, 'Stats: uptimeSeconds >= 0');
    assert_true(isset($stats['actors']['w1']), 'Stats: contains w1');
    assert_true(isset($stats['actors']['w2']), 'Stats: contains w2');

    $str = (string) $system;
    assert_true(str_contains($str, 'stats-system'), '__toString contains system name');
    assert_true(str_contains($str, 'RUNNING'), '__toString shows RUNNING');

    $system->shutdown(gracePeriod: 2.0);
});

// ═══════════════════════════════════════════════════════════════════
//  Test 17: AbstractActorBehavior — become/unbecome (FSM)
// ═══════════════════════════════════════════════════════════════════

section('17. Actor — Become/Unbecome (FSM)');

class FSMBehavior extends AbstractActorBehavior
{
    public string $state = 'idle';
    public array $log = [];

    public function handle(Message $message, ActorContext $context): void
    {
        match ($message->type) {
            'activate' => $this->activate($context),
            'stop' => $context->stop(),
            default => $this->log[] = "idle:{$message->type}",
        };
    }

    private function activate(ActorContext $context): void
    {
        $this->state = 'active';
        $this->become(function (Message $msg, ActorContext $ctx) {
            match ($msg->type) {
                'deactivate' => $this->deactivate(),
                'stop' => $ctx->stop(),
                default => $this->log[] = "active:{$msg->type}",
            };
        });
    }

    private function deactivate(): void
    {
        $this->state = 'idle';
        $this->unbecome();
    }
}

RunBlocking::new(function () {
    $behavior = new FSMBehavior();
    $actor = new Actor('fsm', $behavior, capacity: 20);
    $actor->start();

    // In idle state
    $actor->send(Message::of('test'));
    Delay::new(50);
    assert_true(in_array('idle:test', $behavior->log), 'Message processed in idle state');

    // Transition to active
    $actor->send(Message::of('activate'));
    $actor->send(Message::of('work'));
    Delay::new(50);
    assert_equals('active', $behavior->state, 'State is active');
    assert_true(in_array('active:work', $behavior->log), 'Message processed in active state');

    // Back to idle
    $actor->send(Message::of('deactivate'));
    $actor->send(Message::of('test2'));
    Delay::new(50);
    assert_equals('idle', $behavior->state, 'State is back to idle');
    assert_true(in_array('idle:test2', $behavior->log), 'Message processed in idle state again');

    $actor->send(Message::of('stop'));
    Delay::new(50);
});

// ═══════════════════════════════════════════════════════════════════
//  Test 18: AbstractActorBehavior — Stash/Unstash
// ═══════════════════════════════════════════════════════════════════

section('18. Actor — Stash/Unstash');

class StashBehavior extends AbstractActorBehavior
{
    public bool $ready = false;
    public array $processed = [];

    public function handle(Message $message, ActorContext $context): void
    {
        match ($message->type) {
            'init_complete' => $this->onInitComplete($context),
            'stop' => $context->stop(),
            default => $this->onMessage($message, $context),
        };
    }

    private function onMessage(Message $message, ActorContext $context): void
    {
        if (!$this->ready) {
            // Not ready yet — stash the message
            $this->stash($message);
        } else {
            $this->processed[] = $message->payload;
        }
    }

    private function onInitComplete(ActorContext $context): void
    {
        $this->ready = true;
        // Process stashed messages
        $this->unstashAll($context);
    }
}

RunBlocking::new(function () {
    $behavior = new StashBehavior();
    $actor = new Actor('stash-test', $behavior, capacity: 20);
    $actor->start();

    // Send messages before init — these should be stashed
    $actor->send(Message::of('data', 'msg1'));
    $actor->send(Message::of('data', 'msg2'));
    $actor->send(Message::of('data', 'msg3'));
    Delay::new(50);

    assert_equals(0, count($behavior->processed), 'No messages processed before init');

    // Complete init — stashed messages should be unstashed and processed
    $actor->send(Message::of('init_complete'));
    Delay::new(50);

    assert_true($behavior->ready, 'Behavior is ready');
    assert_equals(['msg1', 'msg2', 'msg3'], $behavior->processed, 'Stashed messages processed in order');

    // New messages should be processed immediately
    $actor->send(Message::of('data', 'msg4'));
    Delay::new(50);
    assert_true(in_array('msg4', $behavior->processed), 'New messages processed after init');

    $actor->send(Message::of('stop'));
    Delay::new(50);
});

// ═══════════════════════════════════════════════════════════════════
//  Test 19: RestartStrategy enum
// ═══════════════════════════════════════════════════════════════════

section('19. Supervisor — RestartStrategy');

assert_equals('one_for_one', RestartStrategy::ONE_FOR_ONE->value, 'ONE_FOR_ONE value');
assert_equals('one_for_all', RestartStrategy::ONE_FOR_ALL->value, 'ONE_FOR_ALL value');
assert_equals('rest_for_one', RestartStrategy::REST_FOR_ONE->value, 'REST_FOR_ONE value');

assert_true(!RestartStrategy::ONE_FOR_ONE->affectsOtherChildren(), 'ONE_FOR_ONE does not affect others');
assert_true(RestartStrategy::ONE_FOR_ALL->affectsOtherChildren(), 'ONE_FOR_ALL affects others');
assert_true(RestartStrategy::REST_FOR_ONE->affectsOtherChildren(), 'REST_FOR_ONE affects others');

assert_true(!RestartStrategy::ONE_FOR_ONE->isOrderSensitive(), 'ONE_FOR_ONE not order-sensitive');
assert_true(RestartStrategy::ONE_FOR_ALL->isOrderSensitive(), 'ONE_FOR_ALL is order-sensitive');
assert_true(RestartStrategy::REST_FOR_ONE->isOrderSensitive(), 'REST_FOR_ONE is order-sensitive');

$desc = RestartStrategy::ONE_FOR_ONE->description();
assert_true(strlen($desc) > 0, 'ONE_FOR_ONE has description');

// ═══════════════════════════════════════════════════════════════════
//  Test 20: RestartType enum
// ═══════════════════════════════════════════════════════════════════

section('20. Supervisor — RestartType');

assert_equals('permanent', RestartType::PERMANENT->value, 'PERMANENT value');
assert_equals('transient', RestartType::TRANSIENT->value, 'TRANSIENT value');
assert_equals('temporary', RestartType::TEMPORARY->value, 'TEMPORARY value');

// shouldRestart logic
$error = new RuntimeException('test');

assert_true(RestartType::PERMANENT->shouldRestart($error), 'PERMANENT restarts on error');
assert_true(RestartType::PERMANENT->shouldRestart(null), 'PERMANENT restarts on normal exit');
assert_true(RestartType::TRANSIENT->shouldRestart($error), 'TRANSIENT restarts on error');
assert_true(!RestartType::TRANSIENT->shouldRestart(null), 'TRANSIENT does NOT restart on normal');
assert_true(!RestartType::TEMPORARY->shouldRestart($error), 'TEMPORARY does NOT restart on error');
assert_true(!RestartType::TEMPORARY->shouldRestart(null), 'TEMPORARY does NOT restart on normal');

// canRestart
assert_true(RestartType::PERMANENT->canRestart(), 'PERMANENT canRestart');
assert_true(RestartType::TRANSIENT->canRestart(), 'TRANSIENT canRestart');
assert_true(!RestartType::TEMPORARY->canRestart(), 'TEMPORARY cannot restart');

// ═══════════════════════════════════════════════════════════════════
//  Test 21: ChildSpec — Builder API
// ═══════════════════════════════════════════════════════════════════

section('21. Supervisor — ChildSpec Builder');

// Closure-mode spec
$spec = ChildSpec::closure('worker-1', fn() => 'work')
    ->permanent()
    ->withMaxRestarts(5, withinSeconds: 30.0);

assert_equals('worker-1', $spec->name, 'Spec name correct');
assert_true($spec->isClosureMode(), 'Spec is closure-mode');
assert_true(!$spec->isActorMode(), 'Spec is not actor-mode');
assert_equals(RestartType::PERMANENT, $spec->getRestartType(), 'Spec restart type PERMANENT');
assert_equals(5, $spec->getMaxRestarts(), 'Spec maxRestarts=5');
assert_equals(30.0, $spec->getWithinSeconds(), 'Spec withinSeconds=30.0');
assert_equals(0, $spec->getTotalRestarts(), 'Spec totalRestarts=0 initially');
assert_true(!$spec->isRetired(), 'Spec not retired initially');

// Actor-mode spec
$actorSpec = ChildSpec::actor('actor-1', new CounterBehavior())
    ->transient()
    ->withCapacity(100)
    ->withDispatcher(Dispatchers::DEFAULT);

assert_true($actorSpec->isActorMode(), 'Actor spec is actor-mode');
assert_equals(RestartType::TRANSIENT, $actorSpec->getRestartType(), 'Actor spec TRANSIENT');
assert_equals(100, $actorSpec->getCapacity(), 'Actor spec capacity=100');

// shouldRestart + intensity
$testSpec = ChildSpec::closure('test', fn() => null)
    ->permanent()
    ->withMaxRestarts(2, withinSeconds: 60.0);

$err = new RuntimeException('test');
assert_true($testSpec->shouldRestart($err), 'shouldRestart true on first failure');

$testSpec->recordRestart();
assert_equals(1, $testSpec->getTotalRestarts(), 'totalRestarts=1 after recording');
assert_true($testSpec->shouldRestart($err), 'shouldRestart true on second failure');

$testSpec->recordRestart();
assert_true(!$testSpec->shouldRestart($err), 'shouldRestart false after exceeding maxRestarts');
assert_true($testSpec->isRetired(), 'Spec retired after exceeding maxRestarts');

// Stats
$stats = $testSpec->getStats();
assert_equals('test', $stats['name'], 'ChildSpec stats: name correct');
assert_true($stats['retired'], 'ChildSpec stats: retired=true');

// __toString
$str = (string) $testSpec;
assert_true(str_contains($str, 'ChildSpec'), '__toString contains ChildSpec');
assert_true(str_contains($str, 'RETIRED'), '__toString contains RETIRED');

// ═══════════════════════════════════════════════════════════════════
//  Test 22: Supervisor — ONE_FOR_ONE with closures
// ═══════════════════════════════════════════════════════════════════

section('22. Supervisor — ONE_FOR_ONE closures');

RunBlocking::new(function () {
    $executionLog = [];
    $crashCount = 0;

    $sup = Supervisor::new(
        strategy: RestartStrategy::ONE_FOR_ONE,
        supervisorMaxRestarts: 10,
        supervisorWithinSeconds: 60.0,
        name: 'test-ofo',
    );

    // A stable worker
    $sup->child('stable', function () use (&$executionLog) {
        $executionLog[] = 'stable:start';
        // Run for a while then exit
        Delay::new(200);
        $executionLog[] = 'stable:done';
    }, RestartType::TEMPORARY);

    // A worker that crashes once then succeeds
    $sup->child('crasher', function () use (&$executionLog, &$crashCount) {
        $executionLog[] = 'crasher:start';
        $crashCount++;
        if ($crashCount <= 1) {
            throw new RuntimeException('intentional crash');
        }
        Delay::new(100);
        $executionLog[] = 'crasher:done';
    }, RestartType::TRANSIENT);

    $sup->start();

    // Wait for everything to settle
    Delay::new(500);

    assert_true(in_array('stable:start', $executionLog), 'Stable worker started');
    assert_true(in_array('crasher:start', $executionLog), 'Crasher started');
    assert_true($crashCount >= 2, 'Crasher was restarted at least once');

    $supStats = $sup->getStats();
    assert_equals('test-ofo', $supStats['name'], 'Supervisor stats: name');
    assert_equals('one_for_one', $supStats['strategy'], 'Supervisor stats: strategy');
    assert_true($supStats['totalRestarts'] >= 1, 'Supervisor recorded at least 1 restart');

    $sup->shutdown(gracePeriod: 2.0);
});

// ═══════════════════════════════════════════════════════════════════
//  Test 23: Supervisor — with Actor children
// ═══════════════════════════════════════════════════════════════════

section('23. Supervisor — Actor children');

class CrashingBehavior extends AbstractActorBehavior
{
    public int $handleCount = 0;

    public function handle(Message $message, ActorContext $context): void
    {
        $this->handleCount++;

        if ($message->type === 'crash') {
            throw new RuntimeException('Actor intentional crash');
        }

        if ($message->type === 'stop') {
            $context->stop();
        }
    }
}

RunBlocking::new(function () {
    $system = ActorSystem::create('sup-actor-test');

    $sup = Supervisor::new(
        strategy: RestartStrategy::ONE_FOR_ONE,
        system: $system,
        name: 'actor-supervisor',
    );

    $sup->actorChild('worker', new CrashingBehavior(), capacity: 20);

    $sup->start();

    Delay::new(100);

    // Verify actor is alive
    $worker = $system->getActor('worker');
    assert_true($worker !== null, 'Worker actor exists in system');

    if ($worker !== null) {
        assert_true($worker->isAlive(), 'Worker actor is alive');

        // Send a normal message
        $worker->send(Message::of('work'));
        Delay::new(50);

        // Crash the actor — supervisor should restart it
        $worker->send(Message::of('crash'));
        Delay::new(200);

        // After restart, there should be a new worker actor in the system
        $newWorker = $system->getActor('worker');
        // The worker might have been re-registered
        assert_true(
            $sup->getTotalRestarts() >= 1,
            'Supervisor restarted the crashed actor'
        );
    }

    $sup->shutdown(gracePeriod: 2.0);
});

// ═══════════════════════════════════════════════════════════════════
//  Test 24: Supervisor — TEMPORARY children not restarted
// ═══════════════════════════════════════════════════════════════════

section('24. Supervisor — TEMPORARY children');

RunBlocking::new(function () {
    $startCount = 0;

    $sup = Supervisor::new(
        strategy: RestartStrategy::ONE_FOR_ONE,
        name: 'temp-test',
    );

    $sup->child('temp-worker', function () use (&$startCount) {
        $startCount++;
        throw new RuntimeException('crash');
    }, RestartType::TEMPORARY);

    $sup->start();
    Delay::new(200);

    assert_equals(1, $startCount, 'TEMPORARY child started exactly once (not restarted)');

    $sup->shutdown(gracePeriod: 1.0);
});

// ═══════════════════════════════════════════════════════════════════
//  Test 25: Supervisor — Max restart intensity
// ═══════════════════════════════════════════════════════════════════

section('25. Supervisor — Max restart intensity');

RunBlocking::new(function () {
    $startCount = 0;

    $sup = Supervisor::new(
        strategy: RestartStrategy::ONE_FOR_ONE,
        supervisorMaxRestarts: 3,
        supervisorWithinSeconds: 60.0,
        name: 'intensity-test',
    );

    $spec = ChildSpec::closure('crasher', function () use (&$startCount) {
        $startCount++;
        throw new RuntimeException('always crash');
    })
        ->permanent()
        ->withMaxRestarts(2, withinSeconds: 60.0);

    $sup->childSpec($spec);
    $sup->start();

    Delay::new(500);

    // The child should have been restarted up to maxRestarts (2) then retired
    assert_true($startCount >= 2, 'Child started at least 2 times');
    assert_true($spec->isRetired(), 'Child spec is retired after exceeding max restarts');

    $sup->shutdown(gracePeriod: 1.0);
});

// ═══════════════════════════════════════════════════════════════════
//  Test 26: Supervisor — Builder fluent API & toString
// ═══════════════════════════════════════════════════════════════════

section('26. Supervisor — Introspection');

RunBlocking::new(function () {
    $sup = Supervisor::new(
        strategy: RestartStrategy::REST_FOR_ONE,
        name: 'intro-test',
    );

    $sup->child('a', fn() => Delay::new(1000))
        ->child('b', fn() => Delay::new(1000))
        ->child('c', fn() => Delay::new(1000));

    assert_equals(3, $sup->childCount(), 'childCount() is 3');
    assert_equals('intro-test', $sup->getName(), 'getName() correct');
    assert_equals(RestartStrategy::REST_FOR_ONE, $sup->getStrategy(), 'getStrategy() correct');
    assert_true(!$sup->isRunning(), 'Not running before start');

    $sup->start();
    assert_true($sup->isRunning(), 'Running after start');

    $specA = $sup->getChildSpec('a');
    assert_true($specA !== null, 'getChildSpec("a") returns non-null');
    assert_equals('a', $specA->name, 'ChildSpec name correct');

    $allSpecs = $sup->getChildSpecs();
    assert_equals(3, count($allSpecs), 'getChildSpecs() returns 3');

    $stats = $sup->getStats();
    assert_equals('rest_for_one', $stats['strategy'], 'Stats: strategy');
    assert_equals(3, $stats['childCount'], 'Stats: childCount');

    $str = (string) $sup;
    assert_true(str_contains($str, 'Supervisor'), '__toString contains Supervisor');
    assert_true(str_contains($str, 'intro-test'), '__toString contains name');
    assert_true(str_contains($str, 'rest_for_one'), '__toString contains strategy');

    $sup->shutdown(gracePeriod: 2.0);
});

// ═══════════════════════════════════════════════════════════════════
//  Test 27: Supervisor — IO Dispatcher support
// ═══════════════════════════════════════════════════════════════════

section('27. Supervisor — IO Dispatcher');

RunBlocking::new(function () {
    $ioStarted = false;

    $sup = Supervisor::new(
        strategy: RestartStrategy::ONE_FOR_ONE,
        name: 'io-test',
    );

    // This tests that children can be configured with IO dispatcher
    // The child runs in the WorkerPool instead of default fiber scheduler
    $spec = ChildSpec::closure('io-worker', function () use (&$ioStarted) {
        $ioStarted = true;
        return 'done';
    })
        ->temporary()
        ->withDispatcher(Dispatchers::IO);

    $sup->childSpec($spec);

    $sup->start();
    Delay::new(500);

    // IO dispatcher may not be available on all platforms,
    // but the configuration should not throw
    assert_true($spec->getDispatcher() === Dispatchers::IO, 'Spec has IO dispatcher configured');

    $sup->shutdown(gracePeriod: 2.0);
});

// ═══════════════════════════════════════════════════════════════════
//  Test 28: Supervisor — Duplicate child name prevention
// ═══════════════════════════════════════════════════════════════════

section('28. Supervisor — Duplicate child names');

$sup = Supervisor::new(strategy: RestartStrategy::ONE_FOR_ONE);
$sup->child('worker', fn() => null);

$threw = false;
try {
    $sup->child('worker', fn() => null);
} catch (RuntimeException) {
    $threw = true;
}
assert_true($threw, 'Duplicate child name throws RuntimeException');

// ═══════════════════════════════════════════════════════════════════
//  Test 29: Supervisor — Cannot start with no children
// ═══════════════════════════════════════════════════════════════════

section('29. Supervisor — No children guard');

$emptySup = Supervisor::new(strategy: RestartStrategy::ONE_FOR_ONE);
$threw = false;
try {
    $emptySup->start();
} catch (RuntimeException) {
    $threw = true;
}
assert_true($threw, 'Cannot start supervisor with no children');

// ═══════════════════════════════════════════════════════════════════
//  Test 30: ActorContext — createMessage and createRequest
// ═══════════════════════════════════════════════════════════════════

section('30. ActorContext — Message factories');

class ContextTestBehavior extends AbstractActorBehavior
{
    public ?Message $createdMessage = null;
    public ?Message $createdRequest = null;

    public function handle(Message $message, ActorContext $context): void
    {
        match ($message->type) {
            'test_create' => $this->testCreate($context),
            'stop' => $context->stop(),
            default => null,
        };
    }

    private function testCreate(ActorContext $context): void
    {
        $this->createdMessage = $context->createMessage('hello', 'world');
        $this->createdRequest = $context->createRequest('ask', 'question');
    }
}

RunBlocking::new(function () {
    $behavior = new ContextTestBehavior();
    $system = ActorSystem::create('ctx-test');
    $system->spawn('ctx-actor', $behavior, capacity: 10);

    $system->tell('ctx-actor', Message::of('test_create'));
    Delay::new(100);

    assert_true($behavior->createdMessage !== null, 'createMessage produced a message');
    if ($behavior->createdMessage !== null) {
        assert_equals('hello', $behavior->createdMessage->type, 'Created message type correct');
        assert_equals('world', $behavior->createdMessage->payload, 'Created message payload correct');
        assert_equals('ctx-actor', $behavior->createdMessage->sender, 'Created message sender is self');
    }

    assert_true($behavior->createdRequest !== null, 'createRequest produced a message');
    if ($behavior->createdRequest !== null) {
        assert_true($behavior->createdRequest->isCorrelated(), 'Created request is correlated');
        assert_equals('ctx-actor', $behavior->createdRequest->sender, 'Created request sender is self');
    }

    $system->shutdown(gracePeriod: 2.0);
});

// ═══════════════════════════════════════════════════════════════════
//  Test 31: Actor — Error handling via onError
// ═══════════════════════════════════════════════════════════════════

section('31. Actor — onError continue');

class ContinueOnErrorBehavior extends AbstractActorBehavior
{
    public int $processedCount = 0;
    public int $errorCount = 0;

    public function handle(Message $message, ActorContext $context): void
    {
        $this->processedCount++;

        if ($message->type === 'crash') {
            throw new RuntimeException('handled crash');
        }

        if ($message->type === 'stop') {
            $context->stop();
        }
    }

    public function onError(Message $message, \Throwable $error, ActorContext $context): bool
    {
        $this->errorCount++;
        // Continue processing — don't let it crash
        return true;
    }
}

RunBlocking::new(function () {
    $behavior = new ContinueOnErrorBehavior();
    $actor = new Actor('resilient', $behavior, capacity: 20);
    $actor->start();

    $actor->send(Message::of('ok'));
    $actor->send(Message::of('crash'));
    $actor->send(Message::of('ok'));
    $actor->send(Message::of('crash'));
    $actor->send(Message::of('ok'));

    Delay::new(150);

    assert_equals(5, $behavior->processedCount, 'All 5 messages processed');
    assert_equals(2, $behavior->errorCount, '2 errors handled');
    assert_true($actor->isAlive(), 'Actor still alive after handled errors');

    $actor->send(Message::of('stop'));
    Delay::new(50);
});

// ═══════════════════════════════════════════════════════════════════
//  Test 32: ChildSpec — resetRestartState and retire
// ═══════════════════════════════════════════════════════════════════

section('32. ChildSpec — Reset and retire');

$spec = ChildSpec::closure('reset-test', fn() => null)
    ->permanent()
    ->withMaxRestarts(2, withinSeconds: 60.0);

// Exhaust restarts
$spec->recordRestart();
$spec->recordRestart();
$spec->shouldRestart(new RuntimeException('test')); // This should mark as retired
assert_true($spec->isRetired(), 'Spec retired after max restarts');

// Reset
$spec->resetRestartState();
assert_true(!$spec->isRetired(), 'Spec not retired after reset');
assert_true($spec->shouldRestart(new RuntimeException('test')), 'Spec can restart after reset');

// Manual retire
$spec->retire();
assert_true($spec->isRetired(), 'Spec retired after manual retire()');
assert_true(!$spec->shouldRestart(new RuntimeException('test')), 'Cannot restart after manual retire');

// ═══════════════════════════════════════════════════════════════════
//  Test 33: FiberPool — Generator support
// ═══════════════════════════════════════════════════════════════════

section('33. FiberPool — Generator tasks');

RunBlocking::new(function () {
    $pool = FiberPool::new(size: 3);

    // Submit a generator-returning callable
    $ticket = $pool->submit(function () {
        $gen = (function () {
            yield 1;
            yield 2;
            yield 3;
            return 'gen_done';
        })();

        // Exhaust the generator manually
        while ($gen->valid()) {
            $gen->next();
        }
        return $gen->getReturn();
    });

    $result = $pool->getResult($ticket);
    assert_equals('gen_done', $result, 'Generator task returns correct final value');

    $pool->close();
});

// ═══════════════════════════════════════════════════════════════════
//  Test 34: ActorSystem — tryTell and stop/unregister
// ═══════════════════════════════════════════════════════════════════

section('34. ActorSystem — tryTell, stop, unregister');

RunBlocking::new(function () {
    $system = ActorSystem::create('tts-test');
    $system->spawn('w', new CounterBehavior(), capacity: 5);

    $ok = $system->tryTell('w', Message::of('increment'));
    assert_true($ok, 'tryTell succeeds for existing actor');

    $fail = $system->tryTell('nonexistent', Message::of('test'));
    assert_true(!$fail, 'tryTell fails for nonexistent actor');

    // stop + unregister
    $stopped = $system->stopAndUnregister('w');
    assert_true($stopped, 'stopAndUnregister returns true');
    assert_true(!$system->hasActor('w'), 'Actor unregistered after stopAndUnregister');

    $system->shutdown();
});

// ═══════════════════════════════════════════════════════════════════
//  Test 35: Supervisor — defaultDispatcher
// ═══════════════════════════════════════════════════════════════════

section('35. Supervisor — Default dispatcher');

RunBlocking::new(function () {
    $ran = false;

    $sup = Supervisor::new(
        strategy: RestartStrategy::ONE_FOR_ONE,
        defaultDispatcher: Dispatchers::DEFAULT,
        name: 'default-disp-test',
    );

    $sup->child('default-child', function () use (&$ran) {
        $ran = true;
    }, RestartType::TEMPORARY);

    $sup->start();
    Delay::new(200);

    assert_true($ran, 'Child ran with default dispatcher');

    $sup->shutdown(gracePeriod: 1.0);
});

// ═══════════════════════════════════════════════════════════════════
//  Test 36: FiberPool — Metrics under load
// ═══════════════════════════════════════════════════════════════════

section('36. FiberPool — Metrics under load');

RunBlocking::new(function () {
    $pool = FiberPool::new(size: 3, maxSize: 10, autoScaling: true);

    $tickets = [];
    for ($i = 0; $i < 20; $i++) {
        $val = $i;
        $tickets[] = $pool->submit(fn() => $val + 1);
    }

    // Drain all
    $pool->drainAll();

    assert_equals(0, $pool->pendingCount(), 'No pending after drainAll');
    assert_true($pool->size() >= 3, 'Pool has at least 3 fibers');
    assert_true($pool->size() <= 10, 'Pool does not exceed max 10');

    $pool->close();
});

// ═══════════════════════════════════════════════════════════════════
//  Final Summary
// ═══════════════════════════════════════════════════════════════════

echo "\n" . str_repeat('═', 60) . "\n";
echo "  TEST SUMMARY\n";
echo str_repeat('═', 60) . "\n";
echo "  ✅ Passed: {$passed}\n";
echo "  ❌ Failed: {$failed}\n";

if ($failed > 0) {
    echo "\n  Failed tests:\n";
    foreach ($errors as $error) {
        echo "    - {$error}\n";
    }
}

echo "\n  Total: " . ($passed + $failed) . " tests\n";
echo str_repeat('═', 60) . "\n";

exit($failed > 0 ? 1 : 0);
