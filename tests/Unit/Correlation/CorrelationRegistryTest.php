<?php

declare(strict_types=1);

namespace tests\Unit\Correlation;

use Apn\AmiClient\Correlation\CorrelationRegistry;
use Apn\AmiClient\Protocol\Strategies\SingleResponseStrategy;
use Apn\AmiClient\Protocol\Strategies\MultiEventStrategy;
use Apn\AmiClient\Protocol\Action;
use Apn\AmiClient\Protocol\Response;
use Apn\AmiClient\Protocol\Event;
use Apn\AmiClient\Exceptions\AmiTimeoutException;
use Apn\AmiClient\Exceptions\ConnectionLostException;
use Apn\AmiClient\Exceptions\BackpressureException;
use Apn\AmiClient\Exceptions\MissingResponseException;
use Apn\AmiClient\Exceptions\ProtocolException;
use Apn\AmiClient\Core\Contracts\CompletionStrategyInterface;
use Apn\AmiClient\Core\Contracts\EventOnlyCompletionStrategyInterface;
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
        $strategy = new MultiEventStrategy('TestComplete');
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
        $strategy = new \Apn\AmiClient\Protocol\Strategies\FollowsResponseStrategy();
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
        $strategy = new MultiEventStrategy('Complete');
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

    public function test_it_fails_when_completion_event_arrives_without_response(): void
    {
        $registry = new CorrelationRegistry();
        $strategy = new MultiEventStrategy('Complete');
        $action = $this->createMockAction('id-missing-response', $strategy);
        $pending = $registry->register($action);

        $capturedException = null;
        $pending->onComplete(function ($e) use (&$capturedException) {
            $capturedException = $e;
        });

        $registry->handleEvent(new Event(['event' => 'Complete', 'actionid' => 'id-missing-response']));

        $this->assertInstanceOf(MissingResponseException::class, $capturedException);
        $this->assertSame(0, $registry->count());
    }

    public function test_it_allows_explicit_event_only_strategy_without_response(): void
    {
        $registry = new CorrelationRegistry();
        $strategy = new EventOnlyMockStrategy('Done');
        $action = $this->createMockAction('id-event-only', $strategy);
        $pending = $registry->register($action);

        $resolved = false;
        $resolvedResponse = null;
        $resolvedEvents = [];
        $pending->onComplete(function ($e, $r, $ev) use (&$resolved, &$resolvedResponse, &$resolvedEvents) {
            $this->assertNull($e);
            $resolved = true;
            $resolvedResponse = $r;
            $resolvedEvents = $ev;
        });

        $registry->handleEvent(new Event(['event' => 'Done', 'actionid' => 'id-event-only']));

        $this->assertTrue($resolved);
        $this->assertInstanceOf(Response::class, $resolvedResponse);
        $this->assertTrue($resolvedResponse->isSuccess());
        $this->assertCount(1, $resolvedEvents);
        $this->assertSame(0, $registry->count());
    }

    public function test_register_throws_on_missing_action_id(): void
    {
        $registry = new CorrelationRegistry();
        $action = new \Apn\AmiClient\Protocol\GenericAction('Ping'); // GenericAction doesn't have ActionID by default
        
        $this->expectException(\InvalidArgumentException::class);
        $registry->register($action);
    }

    public function test_rollback_rejects_and_removes_pending_action(): void
    {
        $registry = new CorrelationRegistry();
        $action = $this->createMockAction('rollback:1', new SingleResponseStrategy());
        $pending = $registry->register($action);

        $captured = null;
        $pending->onComplete(function ($e) use (&$captured) {
            $captured = $e;
        });

        $rolledBack = $registry->rollback('rollback:1', new ProtocolException('send failed'));

        $this->assertTrue($rolledBack);
        $this->assertInstanceOf(ProtocolException::class, $captured);
        $this->assertSame(0, $registry->count());
    }

    public function test_callback_exception_handler_receives_callback_failures_and_other_callbacks_continue(): void
    {
        $reported = [];
        $registry = new CorrelationRegistry(
            callbackExceptionHandler: function (string $callbackIdentity, \Throwable $e, Action $action) use (&$reported): void {
                $reported[] = [
                    'callback' => $callbackIdentity,
                    'exception' => $e::class,
                    'action' => $action->getActionName(),
                ];
            }
        );

        $action = $this->createMockAction('cb:1', new SingleResponseStrategy());
        $pending = $registry->register($action);

        $completed = false;
        $pending->onComplete(function (): void {
            throw new \RuntimeException('boom');
        });
        $pending->onComplete(function (?Throwable $e, ?Response $r) use (&$completed): void {
            $completed = $e === null && $r?->isSuccess() === true;
        });

        $registry->handleResponse(new Response(['response' => 'Success', 'actionid' => 'cb:1']));

        $this->assertTrue($completed);
        $this->assertCount(1, $reported);
        $this->assertStringEndsWith('RuntimeException', $reported[0]['exception']);
        $this->assertSame('Mock', $reported[0]['action']);
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
        CompletionStrategyInterface $strategy
    ) {
        parent::__construct([], $actionId, $strategy);
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

final class EventOnlyMockStrategy implements EventOnlyCompletionStrategyInterface
{
    private bool $complete = false;

    public function __construct(private readonly string $terminalEventName)
    {
    }

    public function onResponse(Response $response): bool
    {
        return false;
    }

    public function onEvent(Event $event): bool
    {
        if (strcasecmp($event->getName(), $this->terminalEventName) === 0) {
            $this->complete = true;
            return true;
        }

        return false;
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }

    public function getMaxDurationMs(): int
    {
        return 30000;
    }

    public function getMaxMessages(): int
    {
        return 10;
    }

    public function getTerminalEventNames(): array
    {
        return [$this->terminalEventName];
    }
}
