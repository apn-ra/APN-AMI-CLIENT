<?php

declare(strict_types=1);

namespace tests\Unit\Protocol;

use Apn\AmiClient\Protocol\Command;
use Apn\AmiClient\Correlation\Strategies\FollowsResponseStrategy;
use PHPUnit\Framework\TestCase;

class CommandTest extends TestCase
{
    public function test_it_correctly_builds_command_action(): void
    {
        $action = new Command('core show channels', 'server:1:1');

        $this->assertEquals('Command', $action->getActionName());
        $this->assertEquals('server:1:1', $action->getActionId());
        $this->assertEquals(['Command' => 'core show channels'], $action->getParameters());
        $this->assertInstanceOf(FollowsResponseStrategy::class, $action->getCompletionStrategy());
    }

    public function test_with_action_id_returns_new_instance(): void
    {
        $action = new Command('ping');
        $newAction = $action->withActionId('new:id');

        $this->assertNotSame($action, $newAction);
        $this->assertEquals('new:id', $newAction->getActionId());
    }
}
