<?php

declare(strict_types=1);

namespace Tests\Unit\Laravel;

use Apn\AmiClient\Cluster\AmiClientManager;
use Apn\AmiClient\Laravel\Commands\ListenCommand;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ListenCommandTest extends TestCase
{
    public function testRequiresServerOrAll(): void
    {
        $manager = $this->createMock(AmiClientManager::class);
        $command = $this->getMockBuilder(ListenCommand::class)
            ->onlyMethods(['option', 'error'])
            ->getMock();

        $command->method('option')->willReturn(false);
        $command->expects($this->once())->method('error')->with('You must specify a --server or use --all');

        $result = $command->handle($manager);
        $this->assertEquals(1, $result);
    }

    public function testHandleWithAllOption(): void
    {
        $manager = $this->createMock(AmiClientManager::class);
        $manager->expects($this->once())->method('registerSignalHandlers');
        $manager->expects($this->once())
            ->method('tickAll')
            ->with(25);

        $command = $this->getMockBuilder(ListenCommand::class)
            ->onlyMethods(['option', 'info'])
            ->getMock();

        $command->method('option')->willReturnCallback(function ($key) {
            if ($key === 'all') {
                return true;
            }
            if ($key === 'once') {
                return true;
            }
            if ($key === 'tick-timeout-ms') {
                return null;
            }
            if ($key === 'max-iterations') {
                return null;
            }
            return null;
        });

        $result = $command->handle($manager);
        $this->assertSame(0, $result);
    }

    public function testHandleWithServerOption(): void
    {
        $manager = $this->createMock(AmiClientManager::class);
        $manager->expects($this->once())->method('registerSignalHandlers');
        $manager->expects($this->once())
            ->method('tick')
            ->with('node1', 40);

        $command = $this->getMockBuilder(ListenCommand::class)
            ->onlyMethods(['option', 'info'])
            ->getMock();

        $command->method('option')->willReturnCallback(function ($key) {
            if ($key === 'server') {
                return 'node1';
            }
            if ($key === 'all') {
                return false;
            }
            if ($key === 'once') {
                return true;
            }
            if ($key === 'tick-timeout-ms') {
                return '40';
            }
            if ($key === 'max-iterations') {
                return null;
            }
            return null;
        });

        $result = $command->handle($manager);
        $this->assertSame(0, $result);
    }

    public function testInvalidTickCadenceIsRejectedWithTypedConfigurationError(): void
    {
        $manager = $this->createMock(AmiClientManager::class);
        $manager->expects($this->never())->method('registerSignalHandlers');

        $command = $this->getMockBuilder(ListenCommand::class)
            ->onlyMethods(['option', 'error'])
            ->getMock();

        $command->method('option')->willReturnCallback(function ($key) {
            if ($key === 'server') {
                return 'node1';
            }
            if ($key === 'all') {
                return false;
            }
            if ($key === 'tick-timeout-ms') {
                return '0';
            }
            if ($key === 'once') {
                return false;
            }
            if ($key === 'max-iterations') {
                return null;
            }
            return null;
        });

        $command->expects($this->once())
            ->method('error')
            ->with('Invalid listen loop cadence: tick-timeout-ms must be between 1 and 1000.');

        $result = $command->handle($manager);
        $this->assertSame(1, $result);
    }
}
