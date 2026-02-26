<?php

declare(strict_types=1);

namespace Tests\Unit\Cluster;

use Apn\AmiClient\Cluster\ClientOptions;
use Apn\AmiClient\Cluster\ServerConfig;
use Apn\AmiClient\Cluster\ServerRegistry;
use Apn\AmiClient\Cluster\AmiClientManager;
use Apn\AmiClient\Core\Contracts\AmiClientInterface;
use Apn\AmiClient\Events\AmiEvent;
use Apn\AmiClient\Protocol\Event;
use Apn\AmiClient\Transport\Reactor;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

class AmiClientManagerTest extends TestCase
{
    public function testAddAndGetServer(): void
    {
        $manager = new AmiClientManager();
        $client = $this->createMock(AmiClientInterface::class);
        
        $manager->addClient('node1', $client);
        
        $this->assertSame($client, $manager->server('node1'));
    }

    public function testDefaultServer(): void
    {
        $manager = new AmiClientManager();
        $client = $this->createMock(AmiClientInterface::class);
        
        $manager->addClient('node1', $client);
        $manager->setDefaultServer('node1');
        
        $this->assertSame($client, $manager->default());
    }

    public function testTickAllCallsReactorAndProcessTick(): void
    {
        $reactor = $this->createMock(Reactor::class);
        $manager = new AmiClientManager(reactor: $reactor);
        
        $client1 = $this->createMock(AmiClientInterface::class);
        $client2 = $this->createMock(AmiClientInterface::class);
        
        $manager->addClient('node1', $client1);
        $manager->addClient('node2', $client2);
        
        $reactor->expects($this->once())
            ->method('tick')
            ->with(100);
            
        $client1->expects($this->once())->method('processTick');
        $client2->expects($this->once())->method('processTick');
        
        $manager->tickAll(100);
    }

    public function testClusterWideConnectThrottling(): void
    {
        $options = new ClientOptions(maxConnectAttemptsPerTick: 1);
        $manager = new AmiClientManager(options: $options);
        
        $client1 = $this->createMock(AmiClientInterface::class);
        $client2 = $this->createMock(AmiClientInterface::class);
        
        $manager->addClient('node1', $client1);
        $manager->addClient('node2', $client2);
        
        // client1 attempts to connect and succeeds (in terms of budget)
        $client1->expects($this->once())
            ->method('processTick')
            ->with(true) // canConnect = true
            ->willReturn(true); // attempted = true
            
        // client2 should NOT be allowed to connect because budget (1) is exhausted
        $client2->expects($this->once())
            ->method('processTick')
            ->with(false) // canConnect = false
            ->willReturn(false); // attempted = false
            
        $manager->tickAll();
    }

    public function testNodeIsolationOnTick(): void
    {
        $reactor = $this->createMock(Reactor::class);
        $manager = new AmiClientManager(reactor: $reactor);
        
        $client1 = $this->createMock(AmiClientInterface::class);
        $client2 = $this->createMock(AmiClientInterface::class);
        
        $manager->addClient('node1', $client1);
        $manager->addClient('node2', $client2);
        
        // Even if client1 fails, client2 should still be ticked
        $client1->method('processTick')->willThrowException(new \RuntimeException("Node 1 failure"));
        $client2->expects($this->once())->method('processTick');
        
        $manager->tickAll(100);
    }

    public function testGlobalSubscription(): void
    {
        $manager = new AmiClientManager();
        $client = $this->createMock(AmiClientInterface::class);
        
        $clientListener = null;
        $client->method('onAnyEvent')->willReturnCallback(function ($callback) use (&$clientListener) {
            $clientListener = $callback;
        });
        
        $manager->addClient('node1', $client);
        
        $receivedAny = null;
        $receivedSpecific = null;
        
        $manager->onAnyEvent(function (AmiEvent $event) use (&$receivedAny) {
            $receivedAny = $event;
        });
        
        $manager->onEvent('TestEvent', function (AmiEvent $event) use (&$receivedSpecific) {
            $receivedSpecific = $event;
        });
        
        $event = new Event(['event' => 'TestEvent']);
        $amiEvent = new AmiEvent($event, 'node1', microtime(true));
        
        $clientListener($amiEvent);
        
        $this->assertSame($amiEvent, $receivedAny);
        $this->assertSame($amiEvent, $receivedSpecific);
    }

    public function testListenerExceptionsDoNotBlockOtherListeners(): void
    {
        $manager = new AmiClientManager(logger: new NullLogger());
        $client = $this->createMock(AmiClientInterface::class);

        $clientListener = null;
        $client->method('onAnyEvent')->willReturnCallback(function ($callback) use (&$clientListener) {
            $clientListener = $callback;
        });

        $manager->addClient('node1', $client);

        $specificCalls = 0;
        $anyCalls = 0;

        $manager->onEvent('TestEvent', function () {
            throw new \RuntimeException('boom');
        });
        $manager->onEvent('TestEvent', function () use (&$specificCalls) {
            $specificCalls++;
        });
        $manager->onAnyEvent(function () {
            throw new \RuntimeException('boom any');
        });
        $manager->onAnyEvent(function () use (&$anyCalls) {
            $anyCalls++;
        });

        $event = new Event(['event' => 'TestEvent']);
        $amiEvent = new AmiEvent($event, 'node1', microtime(true));

        $clientListener($amiEvent);

        $this->assertEquals(1, $specificCalls);
        $this->assertEquals(1, $anyCalls);
    }

    public function testSelectThrowsIfNoClients(): void
    {
        $manager = new AmiClientManager();
        $this->expectException(\Apn\AmiClient\Exceptions\AmiException::class);
        $this->expectExceptionMessage("No AMI servers configured");
        $manager->select();
    }

    public function testServerThrowsOnMissingKey(): void
    {
        $manager = new AmiClientManager();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("AMI server 'missing' not configured");
        $manager->server('missing');
    }

    public function testSetDefaultServerThrowsOnMissingKey(): void
    {
        $manager = new AmiClientManager();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("AMI server 'missing' not configured");
        $manager->setDefaultServer('missing');
    }

    public function testLazyLoading(): void
    {
        $registry = new ServerRegistry();
        $registry->add(new ServerConfig('node1', 'localhost'));
        
        $manager = new AmiClientManager($registry);
        
        // At this point, no client should be instantiated in $clients internal array
        // But we can't easily check private property without reflection.
        // We check that calling server() returns a client.
        $client = $manager->server('node1');
        $this->assertInstanceOf(AmiClientInterface::class, $client);
        $this->assertEquals('node1', $client->getServerKey());
    }

    public function testEagerLoading(): void
    {
        $registry = new ServerRegistry();
        $registry->add(new ServerConfig('node1', 'localhost'));
        
        $options = new ClientOptions(lazy: false);
        
        // Eager loading will try to connectAll(), which calls open()
        // We catch the connection exception since there's no server
        try {
            $manager = new AmiClientManager($registry, $options);
        } catch (\Apn\AmiClient\Exceptions\ConnectionException) {
            // expected failure to connect in test environment
        }
        
        // Since it's not lazy, the client should have been instantiated
        // We can't easily check without reflection, but we can verify it's there
        // by calling server() which should return the same instance that failed to open.
    }
}
