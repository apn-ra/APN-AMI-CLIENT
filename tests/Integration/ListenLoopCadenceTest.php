<?php

declare(strict_types=1);

namespace Tests\Integration;

use Apn\AmiClient\Cluster\AmiClientManager;
use Apn\AmiClient\Laravel\Commands\ListenCommand;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class ListenLoopCadenceTest extends TestCase
{
    public function test_idle_listen_loop_uses_bounded_tick_cadence_and_does_not_busy_spin(): void
    {
        $manager = $this->createMock(AmiClientManager::class);
        $manager->expects($this->once())->method('registerSignalHandlers');
        $manager->expects($this->exactly(3))
            ->method('tickAll')
            ->with(20);

        $command = $this->getMockBuilder(ListenCommand::class)
            ->onlyMethods(['option', 'info'])
            ->getMock();

        $command->method('option')->willReturnCallback(static function (string $key): mixed {
            return match ($key) {
                'server' => null,
                'all' => true,
                'once' => false,
                'max-iterations' => '3',
                'tick-timeout-ms' => '20',
                default => null,
            };
        });

        $result = $command->handle($manager);
        $this->assertSame(0, $result);
    }
}
