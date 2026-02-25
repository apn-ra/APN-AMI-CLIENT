<?php

declare(strict_types=1);

namespace Tests\Unit\Laravel;

use Apn\AmiClient\Cluster\AmiClientManager;
use Apn\AmiClient\Laravel\Ami;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class AmiFacadeTest extends TestCase
{
    protected Container $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Container();
        Facade::setFacadeApplication($this->app);
    }

    protected function tearDown(): void
    {
        Facade::setFacadeApplication(null);
        Facade::clearResolvedInstances('ami');
        parent::tearDown();
    }

    public function testFacadeAccessorIsAmi(): void
    {
        $manager = $this->createMock(AmiClientManager::class);
        $this->app->instance('ami', $manager);

        // This is a bit tricky to test directly as it's protected, 
        // but we can check if it resolves correctly.
        $this->assertSame($manager, Ami::getFacadeRoot());
    }

    public function testFacadeProxyCalls(): void
    {
        $manager = $this->createMock(AmiClientManager::class);
        $manager->expects($this->once())->method('health')->willReturn(['status' => 'ok']);
        
        $this->app->instance('ami', $manager);

        $result = Ami::health();
        $this->assertEquals(['status' => 'ok'], $result);
    }
}
