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
        
        // We want to test that tickAll is called.
        // Since it's an infinite loop, we might need a way to break it.
        // For testing purposes, we can mock tickAll to throw an exception to break the loop.
        $manager->expects($this->once())
            ->method('tickAll')
            ->with(100)
            ->willThrowException(new \RuntimeException('Break Loop'));

        $command = $this->getMockBuilder(ListenCommand::class)
            ->onlyMethods(['option', 'info'])
            ->getMock();

        $command->method('option')->willReturnCallback(function($key) {
            return $key === 'all';
        });

        try {
            $command->handle($manager);
        } catch (\RuntimeException $e) {
            $this->assertEquals('Break Loop', $e->getMessage());
        }
    }

    public function testHandleWithServerOption(): void
    {
        $manager = $this->createMock(AmiClientManager::class);
        $manager->expects($this->once())->method('registerSignalHandlers');
        
        $manager->expects($this->once())
            ->method('tick')
            ->with('node1', 100)
            ->willThrowException(new \RuntimeException('Break Loop'));

        $command = $this->getMockBuilder(ListenCommand::class)
            ->onlyMethods(['option', 'info'])
            ->getMock();

        $command->method('option')->willReturnCallback(function($key) {
            if ($key === 'server') return 'node1';
            if ($key === 'all') return false;
            return null;
        });

        try {
            $command->handle($manager);
        } catch (\RuntimeException $e) {
            $this->assertEquals('Break Loop', $e->getMessage());
        }
    }
}
