<?php

declare(strict_types=1);

namespace Tests\Unit\Cluster;

use Apn\AmiClient\Cluster\Routing\ExplicitRoutingStrategy;
use Apn\AmiClient\Cluster\Routing\FailoverRoutingStrategy;
use Apn\AmiClient\Cluster\Routing\RoundRobinRoutingStrategy;
use Apn\AmiClient\Core\Contracts\AmiClientInterface;
use Apn\AmiClient\Exceptions\AmiException;
use Apn\AmiClient\Health\HealthStatus;
use PHPUnit\Framework\TestCase;

class RoutingTest extends TestCase
{
    public function testRoundRobin(): void
    {
        $strategy = new RoundRobinRoutingStrategy();
        
        $c1 = $this->createMock(AmiClientInterface::class);
        $c1->method('getHealthStatus')->willReturn(HealthStatus::READY);
        $c2 = $this->createMock(AmiClientInterface::class);
        $c2->method('getHealthStatus')->willReturn(HealthStatus::READY);
        
        $clients = ['n1' => $c1, 'n2' => $c2];
        
        $this->assertSame($c1, $strategy->select($clients));
        $this->assertSame($c2, $strategy->select($clients));
        $this->assertSame($c1, $strategy->select($clients));
    }

    public function testRoundRobinSkipsDisconnected(): void
    {
        $strategy = new RoundRobinRoutingStrategy();
        
        $c1 = $this->createMock(AmiClientInterface::class);
        $c1->method('getHealthStatus')->willReturn(HealthStatus::DISCONNECTED);
        $c2 = $this->createMock(AmiClientInterface::class);
        $c2->method('getHealthStatus')->willReturn(HealthStatus::READY);
        
        $clients = ['n1' => $c1, 'n2' => $c2];
        
        $this->assertSame($c2, $strategy->select($clients));
        $this->assertSame($c2, $strategy->select($clients));
    }

    public function testRoundRobinSkipsDegraded(): void
    {
        $strategy = new RoundRobinRoutingStrategy();
        
        $c1 = $this->createMock(AmiClientInterface::class);
        $c1->method('getHealthStatus')->willReturn(HealthStatus::READY_DEGRADED);
        $c2 = $this->createMock(AmiClientInterface::class);
        $c2->method('getHealthStatus')->willReturn(HealthStatus::READY);
        
        $clients = ['n1' => $c1, 'n2' => $c2];
        
        $this->assertSame($c2, $strategy->select($clients));
    }

    public function testFailover(): void
    {
        $strategy = new FailoverRoutingStrategy();
        
        $c1 = $this->createMock(AmiClientInterface::class);
        $c1->method('getHealthStatus')->willReturn(HealthStatus::DISCONNECTED);
        $c2 = $this->createMock(AmiClientInterface::class);
        $c2->method('getHealthStatus')->willReturn(HealthStatus::READY);
        
        $clients = ['n1' => $c1, 'n2' => $c2];
        
        $this->assertSame($c2, $strategy->select($clients));
    }

    public function testExplicit(): void
    {
        $strategy = new ExplicitRoutingStrategy('n2');
        
        $c1 = $this->createMock(AmiClientInterface::class);
        $c1->method('getHealthStatus')->willReturn(HealthStatus::READY);
        $c2 = $this->createMock(AmiClientInterface::class);
        $c2->method('getHealthStatus')->willReturn(HealthStatus::READY);
        
        $clients = ['n1' => $c1, 'n2' => $c2];
        
        $this->assertSame($c2, $strategy->select($clients));
    }

    public function testRoundRobinThrowsOnNoHealthy(): void
    {
        $strategy = new RoundRobinRoutingStrategy();
        
        $c1 = $this->createMock(AmiClientInterface::class);
        $c1->method('getHealthStatus')->willReturn(HealthStatus::DISCONNECTED);
        
        $clients = ['n1' => $c1];
        
        $this->expectException(AmiException::class);
        $this->expectExceptionMessage("No healthy servers available for routing");
        
        $strategy->select($clients);
    }

    public function testFailoverThrowsOnNoHealthy(): void
    {
        $strategy = new FailoverRoutingStrategy();
        
        $c1 = $this->createMock(AmiClientInterface::class);
        $c1->method('getHealthStatus')->willReturn(HealthStatus::DISCONNECTED);
        
        $clients = ['n1' => $c1];
        
        $this->expectException(AmiException::class);
        $this->expectExceptionMessage("No healthy servers available for routing");
        
        $strategy->select($clients);
    }

    public function testExplicitThrowsOnMissingServer(): void
    {
        $strategy = new ExplicitRoutingStrategy('n3');
        $clients = ['n1' => $this->createMock(AmiClientInterface::class)];
        
        $this->expectException(AmiException::class);
        $this->expectExceptionMessage("Server 'n3' not found");
        
        $strategy->select($clients);
    }
}
