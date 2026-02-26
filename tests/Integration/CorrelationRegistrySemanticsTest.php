<?php

declare(strict_types=1);

namespace Tests\Integration;

use Apn\AmiClient\Core\Contracts\CompletionStrategyInterface;
use Apn\AmiClient\Core\Contracts\EventOnlyCompletionStrategyInterface;
use Apn\AmiClient\Correlation\CorrelationRegistry;
use Apn\AmiClient\Exceptions\MissingResponseException;
use Apn\AmiClient\Protocol\Action;
use Apn\AmiClient\Protocol\Event;
use Apn\AmiClient\Protocol\Response;
use Apn\AmiClient\Protocol\Strategies\MultiEventStrategy;
use PHPUnit\Framework\TestCase;

final class CorrelationRegistrySemanticsTest extends TestCase
{
    public function test_non_event_only_strategy_fails_without_response(): void
    {
        $registry = new CorrelationRegistry();
        $action = new SemanticsMockAction('semantics:missing', new MultiEventStrategy('Done'));
        $pending = $registry->register($action);

        $captured = null;
        $pending->onComplete(function ($e) use (&$captured) {
            $captured = $e;
        });

        $registry->handleEvent(new Event(['event' => 'Done', 'actionid' => 'semantics:missing']));

        $this->assertInstanceOf(MissingResponseException::class, $captured);
        $this->assertSame(0, $registry->count());
    }

    public function test_event_only_strategy_completes_without_response(): void
    {
        $registry = new CorrelationRegistry();
        $action = new SemanticsMockAction('semantics:event-only', new SemanticsEventOnlyStrategy('Done'));
        $pending = $registry->register($action);

        $resolved = false;
        $resolvedResponse = null;
        $pending->onComplete(function ($e, $r, $events) use (&$resolved, &$resolvedResponse) {
            $this->assertNull($e);
            $this->assertCount(1, $events);
            $resolved = true;
            $resolvedResponse = $r;
        });

        $registry->handleEvent(new Event(['event' => 'Done', 'actionid' => 'semantics:event-only']));

        $this->assertTrue($resolved);
        $this->assertInstanceOf(Response::class, $resolvedResponse);
        $this->assertTrue($resolvedResponse->isSuccess());
        $this->assertSame(0, $registry->count());
    }
}

readonly class SemanticsMockAction extends Action
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

final class SemanticsEventOnlyStrategy implements EventOnlyCompletionStrategyInterface
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

