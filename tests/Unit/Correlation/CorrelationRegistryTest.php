<?php

declare(strict_types=1);

namespace tests\Unit\Correlation;

use Apn\AmiClient\Correlation\CorrelationRegistry;
use Apn\AmiClient\Correlation\Strategies\SingleResponseStrategy;
use Apn\AmiClient\Correlation\Strategies\MultiResponseStrategy;
use Apn\AmiClient\Protocol\Action;
use Apn\AmiClient\Protocol\Response;
use Apn\AmiClient\Protocol\Event;
use Apn\AmiClient\Exceptions\AmiTimeoutException;
use Apn\AmiClient\Exceptions\ConnectionLostException;
use Apn\AmiClient\Exceptions\BackpressureException;
use Apn\AmiClient\Core\Contracts\CompletionStrategyInterface;
use PHPUnit\Framework\TestCase;

class CorrelationRegistryTest extends TestCase
{
    public function test_it_registers_and_resolves_single_response_action(): void
    {
        $registry = new CorrelationRegistry();
        $action = $this->createMockAction('server:1:1', new SingleResponseStrategy());
        
        $pending = $registry->register($action);
        $this->assertEquals(1, $registry->count());
        
        $resolved = false;
        $pending->onComplete(function ($e, $r, $ev) use (&$resolved) {
            $resolved = true;
            $this->assertNull($e);
            $this->assertInstanceOf(Response::class, $r);
            $this->assertEquals('Success', $r->getHeader('Response'));
        });
        
        $response = new Response(['response' => 'Success', 'actionid' => 'server:1:1']);
        $registry->handleResponse($response);
        
        $this->assertTrue($resolved);
        $this->assertEquals(0, $registry->count());
    }

    public function test_it_handles_multi_response_actions(): void
    {
        $registry = new CorrelationRegistry();
        $strategy = new MultiResponseStrategy('TestComplete');
        $action = $this->createMockAction('server:1:1', $strategy);
        
        $pending = $registry->register($action);
        
        $resolved = false;
        $collectedEvents = [];
        $pending->onComplete(function ($e, $r, $ev) use (&$resolved, &$collectedEvents) {
            $resolved = true;
            $collectedEvents = $ev;
        });
        
        $response = new Response(['response' => 'Success', 'actionid' => 'server:1:1']);
        $registry->handleResponse($response);
        $this->assertFalse($resolved);
        
        $event1 = new Event(['event' => 'TestData', 'actionid' => 'server:1:1']);
        $registry->handleEvent($event1);
        $this->assertFalse($resolved);
        
        $eventComplete = new Event(['event' => 'TestComplete', 'actionid' => 'server:1:1']);
        $registry->handleEvent($eventComplete);
        
        $this->assertTrue($resolved);
        $this->assertCount(2, $collectedEvents);
        $this->assertEquals(0, $registry->count());
    }

    public function test_it_sweeps_timed_out_actions(): void
    {
        $registry = new CorrelationRegistry();
        $action = $this->createMockAction('server:1:1', new SingleResponseStrategy());
        
        // Register with -1 second timeout to ensure immediate timeout
        $pending = $registry->register($action, -1.0);
        
        $failed = false;
        $pending->onComplete(function ($e, $r, $ev) use (&$failed) {
            $failed = true;
            $this->assertInstanceOf(AmiTimeoutException::class, $e);
        });
        
        $registry->sweep();
        
        $this->assertTrue($failed);
        $this->assertEquals(0, $registry->count());
    }

    public function test_it_fails_all_actions_on_connection_lost(): void
    {
        $registry = new CorrelationRegistry();
        $action1 = $this->createMockAction('id1', new SingleResponseStrategy());
        $action2 = $this->createMockAction('id2', new SingleResponseStrategy());
        
        $pending1 = $registry->register($action1);
        $pending2 = $registry->register($action2);
        
        $failedCount = 0;
        $pending1->onComplete(function ($e) use (&$failedCount) {
            if ($e instanceof ConnectionLostException) $failedCount++;
        });
        $pending2->onComplete(function ($e) use (&$failedCount) {
            if ($e instanceof ConnectionLostException) $failedCount++;
        });
        
        $registry->failAll();
        
        $this->assertEquals(2, $failedCount);
        $this->assertEquals(0, $registry->count());
    }

    public function test_it_enforces_max_pending_limit(): void
    {
        $registry = new CorrelationRegistry(1);
        $action1 = $this->createMockAction('id1', new SingleResponseStrategy());
        $action2 = $this->createMockAction('id2', new SingleResponseStrategy());
        
        $registry->register($action1);
        
        $this->expectException(BackpressureException::class);
        $registry->register($action2);
    }

    public function test_it_handles_follows_response_actions(): void
    {
        $registry = new CorrelationRegistry();
        $strategy = new \Apn\AmiClient\Correlation\Strategies\FollowsResponseStrategy();
        $action = $this->createMockAction('server:1:1', $strategy);
        
        $pending = $registry->register($action);
        
        $resolved = false;
        $pending->onComplete(function ($e, $r, $ev) use (&$resolved) {
            $resolved = true;
        });
        
        $response = new Response([
            'response' => 'Follows',
            'actionid' => 'server:1:1',
            'output' => ['line1', 'line2', '--END COMMAND--']
        ]);
        $registry->handleResponse($response);
        
        $this->assertTrue($resolved);
        $this->assertEquals(0, $registry->count());
    }

    public function test_it_enforces_registry_isolation(): void
    {
        $regA = new CorrelationRegistry();
        $regB = new CorrelationRegistry();
        
        $actionA = $this->createMockAction('idA', new SingleResponseStrategy());
        $actionB = $this->createMockAction('idB', new SingleResponseStrategy());
        
        $pendingA = $regA->register($actionA);
        $pendingB = $regB->register($actionB);
        
        $resolvedA = false;
        $pendingA->onComplete(function () use (&$resolvedA) { $resolvedA = true; });
        
        $resolvedB = false;
        $pendingB->onComplete(function () use (&$resolvedB) { $resolvedB = true; });
        
        // Response for A sent to B
        $responseA = new Response(['response' => 'Success', 'actionid' => 'idA']);
        $regB->handleResponse($responseA);
        
        $this->assertFalse($resolvedA);
        $this->assertFalse($resolvedB);
        
        // Response for A sent to A
        $regA->handleResponse($responseA);
        $this->assertTrue($resolvedA);
        $this->assertFalse($resolvedB);
    }

    public function test_it_handles_response_with_unknown_action_id(): void
    {
        $registry = new CorrelationRegistry();
        $response = new Response(['response' => 'Success', 'actionid' => 'unknown']);
        
        // Should not throw and not affect anything
        $registry->handleResponse($response);
        $this->assertEquals(0, $registry->count());
    }

    public function test_it_handles_event_with_unknown_action_id(): void
    {
        $registry = new CorrelationRegistry();
        $event = new Event(['event' => 'Test', 'actionid' => 'unknown']);
        
        $registry->handleEvent($event);
        $this->assertEquals(0, $registry->count());
    }

    public function test_it_enforces_event_collection_limit(): void
    {
        $registry = new CorrelationRegistry();
        $strategy = new MultiResponseStrategy('Complete');
        $action = $this->createMockAction('id', $strategy);
        $pending = $registry->register($action);
        
        $registry->handleResponse(new Response(['response' => 'Success', 'actionid' => 'id']));
        
        for ($i = 0; $i < 10005; $i++) {
            $registry->handleEvent(new Event(['event' => 'Data', 'actionid' => 'id']));
        }
        
        $resolved = false;
        $events = [];
        $pending->onComplete(function ($e, $r, $ev) use (&$resolved, &$events) {
            $resolved = true;
            $events = $ev;
        });
        
        $registry->handleEvent(new Event(['event' => 'Complete', 'actionid' => 'id']));
        
        $this->assertTrue($resolved);
        $this->assertCount(10000, $events); // Capped at 10000
    }

    public function test_register_throws_on_missing_action_id(): void
    {
        $registry = new CorrelationRegistry();
        $action = new \Apn\AmiClient\Protocol\GenericAction('Ping'); // GenericAction doesn't have ActionID by default
        
        $this->expectException(\InvalidArgumentException::class);
        $registry->register($action);
    }

    private function createMockAction(string $actionId, \Apn\AmiClient\Core\Contracts\CompletionStrategyInterface $strategy): Action
    {
        return new MockAction($actionId, $strategy);
    }
}

/**
 * Mock action for testing purposes.
 */
readonly class MockAction extends Action
{
    public function __construct(
        ?string $actionId,
        private CompletionStrategyInterface $strategy
    ) {
        parent::__construct([], $actionId);
    }

    public function getActionName(): string
    {
        return 'Mock';
    }

    public function getCompletionStrategy(): CompletionStrategyInterface
    {
        return $this->strategy;
    }

    public function withActionId(string $actionId): static
    {
        return new self($actionId, $this->strategy);
    }
}
