<?php

declare(strict_types=1);

namespace tests\Unit\Protocol;

use Apn\AmiClient\Protocol\GenericAction;
use Apn\AmiClient\Correlation\Strategies\SingleResponseStrategy;
use Apn\AmiClient\Core\Contracts\CompletionStrategyInterface;
use PHPUnit\Framework\TestCase;

class GenericActionTest extends TestCase
{
    public function test_it_correctly_builds_generic_action(): void
    {
        $action = new GenericAction(
            'Ping',
            ['Header1' => 'Value1', 'Header2' => ['V1', 'V2']],
            'server:1:1'
        );

        $this->assertEquals('Ping', $action->getActionName());
        $this->assertEquals('server:1:1', $action->getActionId());
        $this->assertEquals(['Header1' => 'Value1', 'Header2' => ['V1', 'V2']], $action->getParameters());
        $this->assertInstanceOf(SingleResponseStrategy::class, $action->getCompletionStrategy());
    }

    public function test_with_action_id_returns_new_instance(): void
    {
        $action = new GenericAction('Ping');
        $newAction = $action->withActionId('new:id');

        $this->assertNotSame($action, $newAction);
        $this->assertEquals('new:id', $newAction->getActionId());
    }

    public function test_it_supports_custom_strategy(): void
    {
        $strategy = $this->createMock(CompletionStrategyInterface::class);
        $action = new GenericAction('Ping', [], null, $strategy);

        $this->assertSame($strategy, $action->getCompletionStrategy());
    }
}
