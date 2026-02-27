<?php

declare(strict_types=1);

namespace Tests\Integration;

use Apn\AmiClient\Cluster\AmiClientManager;
use Apn\AmiClient\Core\TickSummary;
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
            ->with(20)
            ->willReturn(TickSummary::empty());
        $manager->expects($this->exactly(2))
            ->method('recordIdleYield')
            ->with('all');

        $command = $this->getMockBuilder(ListenCommand::class)
            ->onlyMethods(['option', 'info', 'nowMs', 'sleepMs'])
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

        $command->expects($this->exactly(2))
            ->method('sleepMs')
            ->with(20);

        $command->method('nowMs')->willReturnOnConsecutiveCalls(
            1000.0, 1000.0,
            1001.0, 1001.0,
            1002.0, 1002.0
        );

        $result = $command->handle($manager);
        $this->assertSame(0, $result);
    }

    public function test_active_listen_loop_skips_idle_sleep_when_progress_detected(): void
    {
        $manager = $this->createMock(AmiClientManager::class);
        $manager->expects($this->once())->method('registerSignalHandlers');
        $manager->expects($this->exactly(2))
            ->method('tickAll')
            ->with(10)
            ->willReturn(new TickSummary(bytesRead: 1));
        $manager->expects($this->never())->method('recordIdleYield');

        $command = $this->getMockBuilder(ListenCommand::class)
            ->onlyMethods(['option', 'info', 'sleepMs'])
            ->getMock();

        $command->method('option')->willReturnCallback(static function (string $key): mixed {
            return match ($key) {
                'server' => null,
                'all' => true,
                'once' => false,
                'max-iterations' => '2',
                'tick-timeout-ms' => '10',
                default => null,
            };
        });

        $command->expects($this->never())->method('sleepMs');

        $result = $command->handle($manager);
        $this->assertSame(0, $result);
    }
}
