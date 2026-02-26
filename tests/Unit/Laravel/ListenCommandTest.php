<?php

declare(strict_types=1);

namespace Tests\Unit\Laravel;

use Apn\AmiClient\Cluster\AmiClientManager;
use Apn\AmiClient\Laravel\Commands\ListenCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Illuminate\Container\Container;

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
        $manager->expects($this->once())->method('pollAll');

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
            ->method('poll')
            ->with('node1');

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
            return null;
        });

        $result = $command->handle($manager);
        $this->assertSame(0, $result);
    }
}
