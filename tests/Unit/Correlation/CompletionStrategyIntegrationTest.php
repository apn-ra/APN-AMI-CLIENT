<?php

declare(strict_types=1);

namespace Tests\Unit\Correlation;

use Apn\AmiClient\Correlation\CorrelationRegistry;
use Apn\AmiClient\Protocol\Action;
use Apn\AmiClient\Protocol\Event;
use Apn\AmiClient\Protocol\Response;
use Apn\AmiClient\Protocol\Strategies\MultiEventStrategy;
use Apn\AmiClient\Exceptions\AmiTimeoutException;
use Apn\AmiClient\Core\Contracts\CompletionStrategyInterface;
use PHPUnit\Framework\TestCase;

class CompletionStrategyIntegrationTest extends TestCase
{
    public function test_it_uses_strategy_default_timeout(): void
    {
        $registry = new CorrelationRegistry();
        
        $strategy = new class implements CompletionStrategyInterface {
            public function onResponse(Response $r): bool { return false; }
            public function onEvent(Event $e): bool { return false; }
            public function isComplete(): bool { return false; }
            public function getMaxDurationMs(): int { return 100; } // 100ms
            public function getMaxMessages(): int { return 10; }
            public function getTerminalEventNames(): array { return []; }
        };
        
        $action = new ConcreteMockAction('test-id', $strategy);
        
        $pending = $registry->register($action);
        
        $failed = false;
        $pending->onComplete(function ($e) use (&$failed) {
            if ($e instanceof AmiTimeoutException) $failed = true;
        });
        
        // Wait 200ms
        usleep(200000);
        $registry->sweep();
        
        $this->assertTrue($failed, "Action should have timed out based on strategy duration");
    }

    public function test_it_enforces_max_messages_from_strategy(): void
    {
        $registry = new CorrelationRegistry();
        
        $strategy = new MultiEventStrategy('Complete', maxMessages: 5);
        $action = new ConcreteMockAction('test-id', $strategy);
        
        $pending = $registry->register($action);
        $registry->handleResponse(new Response(['response' => 'Success', 'actionid' => 'test-id']));
        
        for ($i = 0; $i < 10; $i++) {
            $registry->handleEvent(new Event(['event' => 'Data', 'actionid' => 'test-id']));
        }
        
        $collectedEvents = [];
        $pending->onComplete(function ($e, $r, $ev) use (&$collectedEvents) {
            $collectedEvents = $ev;
        });
        
        $registry->handleEvent(new Event(['event' => 'Complete', 'actionid' => 'test-id']));
        
        $this->assertCount(5, $collectedEvents, "Should respect maxMessages from strategy");
    }
}

readonly class ConcreteMockAction extends Action
{
    public function __construct(string $actionId, CompletionStrategyInterface $strategy)
    {
        parent::__construct([], $actionId, $strategy);
    }

    public function getActionName(): string { return 'Mock'; }
    public function withActionId(string $actionId): static { return new self($actionId, $this->strategy); }
}
