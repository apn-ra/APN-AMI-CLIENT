<?php

declare(strict_types=1);

namespace Tests\Unit\Cluster;

use Apn\AmiClient\Cluster\ClientOptions;
use Apn\AmiClient\Cluster\ServerConfig;
use Apn\AmiClient\Cluster\ServerRegistry;
use Apn\AmiClient\Cluster\AmiClientManager;
use Apn\AmiClient\Core\AmiClient;
use Apn\AmiClient\Core\Contracts\AmiClientInterface;
use Apn\AmiClient\Core\Contracts\MetricsCollectorInterface;
use Apn\AmiClient\Events\AmiEvent;
use Apn\AmiClient\Exceptions\BackpressureException;
use Apn\AmiClient\Health\HealthStatus;
use Apn\AmiClient\Protocol\GenericAction;
use Apn\AmiClient\Protocol\Event;
use Apn\AmiClient\Transport\Reactor;
use Psr\Log\NullLogger;
use Psr\Log\LoggerInterface;
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
        $manager = new AmiClientManager($registry, $options);
        $client = $manager->server('node1');
        $this->assertInstanceOf(AmiClientInterface::class, $client);
    }

    public function testDefaultLoggerUsesClientOptionsRedactionPolicy(): void
    {
        $options = new ClientOptions(
            redactionKeys: ['custom_secret'],
            redactionKeyPatterns: ['/^x-.+$/i']
        );
        $manager = new AmiClientManager(options: $options);

        $reflection = new \ReflectionClass($manager);
        $loggerProperty = $reflection->getProperty('logger');
        /** @var LoggerInterface $logger */
        $logger = $loggerProperty->getValue($manager);

        ob_start();
        $logger->warning('redaction-check', [
            'custom_secret' => 'a',
            'x-auth-header' => 'b',
            'safe' => 'c',
        ]);
        $output = ob_get_clean();

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertSame('********', $decoded['custom_secret']);
        $this->assertSame('********', $decoded['x-auth-header']);
        $this->assertSame('c', $decoded['safe']);
    }

    public function testInjectedMetricsCollectorIsPropagatedThroughCreateClientStack(): void
    {
        $registry = new ServerRegistry();
        $registry->add(new ServerConfig('node1', '127.0.0.1'));

        $options = new ClientOptions(
            eventQueueCapacity: 1,
            maxPendingActions: 0
        );

        $metrics = new class implements MetricsCollectorInterface {
            /** @var array<int, array{name: string, labels: array<string, string>, amount: int}> */
            public array $increments = [];
            public function increment(string $name, array $labels = [], int $amount = 1): void
            {
                $this->increments[] = ['name' => $name, 'labels' => $labels, 'amount' => $amount];
            }
            public function record(string $name, float $value, array $labels = []): void {}
            public function set(string $name, float $value, array $labels = []): void {}
        };

        $manager = new AmiClientManager(
            registry: $registry,
            options: $options,
            logger: new NullLogger(),
            metrics: $metrics
        );

        $client = $manager->server('node1');
        $this->assertInstanceOf(AmiClient::class, $client);

        $eventQueueRef = new \ReflectionProperty(AmiClient::class, 'eventQueue');
        /** @var \Apn\AmiClient\Core\EventQueue $eventQueue */
        $eventQueue = $eventQueueRef->getValue($client);
        $eventQueue->push(new AmiEvent(new Event(['event' => 'DropTest']), 'node1', microtime(true)));
        $eventQueue->push(new AmiEvent(new Event(['event' => 'DropTest']), 'node1', microtime(true)));

        $client->getConnectionManager()->setStatus(HealthStatus::READY);
        try {
            $client->send(new GenericAction('Ping'));
            $this->fail('Expected backpressure exception');
        } catch (BackpressureException) {
            // expected
        }

        $metricNames = array_map(static fn (array $row): string => $row['name'], $metrics->increments);
        $this->assertContains('ami_dropped_events_total', $metricNames);
        $this->assertContains('ami_write_buffer_backpressure_events_total', $metricNames);
    }
}
