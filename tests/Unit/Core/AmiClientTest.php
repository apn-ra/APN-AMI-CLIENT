<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use Apn\AmiClient\Core\AmiClient;
use Apn\AmiClient\Correlation\CorrelationManager;
use Apn\AmiClient\Core\Contracts\MetricsCollectorInterface;
use Apn\AmiClient\Core\Contracts\TransportInterface;
use Apn\AmiClient\Correlation\ActionIdGenerator;
use Apn\AmiClient\Correlation\CorrelationRegistry;
use Apn\AmiClient\Events\AmiEvent;
use Apn\AmiClient\Health\HealthStatus;
use Apn\AmiClient\Protocol\GenericAction;
use Apn\AmiClient\Protocol\Response;
use Apn\AmiClient\Protocol\Event;
use Apn\AmiClient\Protocol\Parser;
use Apn\AmiClient\Protocol\Logoff;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;
use Apn\AmiClient\Exceptions\InvalidConnectionStateException;
use Psr\Log\AbstractLogger;

class AmiClientTest extends TestCase
{
    public function testGetServerKey(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry());
        
        $client = new AmiClient('node1', $transport, $correlation);
        
        $this->assertEquals('node1', $client->getServerKey());
    }

    public function testGetHealthStatus(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry());
        
        $client = new AmiClient('node1', $transport, $correlation);
        
        $this->assertEquals(HealthStatus::DISCONNECTED, $client->getHealthStatus());
        
        $transport->method('isConnected')->willReturn(true);
        $client->processTick(); // Transitions from DISCONNECTED to READY (since no credentials)
        
        $this->assertEquals(HealthStatus::READY, $client->getHealthStatus());
    }

    public function testReconnectionAttempt(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry());
        
        $client = new AmiClient('node1', $transport, $correlation);
        
        $transport->method('isConnected')->willReturn(false);
        $transport->expects($this->once())->method('open');
        
        $client->processTick(); // Trigger reconnection attempt
        $this->assertEquals(HealthStatus::CONNECTING, $client->getHealthStatus());
    }

    public function testHeartbeatFailureEscalation(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry());
        
        $client = new AmiClient('node1', $transport, $correlation);
        
        $transport->method('isConnected')->willReturn(true);
        $client->processTick();
        $this->assertEquals(HealthStatus::READY, $client->getHealthStatus());

        // We need to trigger heartbeats. ConnectionManager default interval is 15s.
        // Let's use a custom ConnectionManager with short interval.
        $cm = new \Apn\AmiClient\Health\ConnectionManager(heartbeatInterval: 0.001);
        $client = new AmiClient('node1', $transport, $correlation, null, $cm);
        $client->processTick();
        
        // We'll just manually trigger the failure
        $cm->recordHeartbeatFailure();
        $this->assertEquals(HealthStatus::READY_DEGRADED, $client->getHealthStatus());

        $cm->recordHeartbeatFailure();
        $this->assertEquals(HealthStatus::READY_DEGRADED, $client->getHealthStatus());

        $cm->recordHeartbeatFailure();
        $this->assertEquals(HealthStatus::DISCONNECTED, $client->getHealthStatus());
    }

    public function testOnEventWrapsInAmiEvent(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry());
        
        $onDataCallback = null;
        $transport->method('onData')->willReturnCallback(function($callback) use (&$onDataCallback) {
            $onDataCallback = $callback;
        });
        
        $parser = new Parser();
        $client = new AmiClient('node1', $transport, $correlation, $parser);
        
        // Make the client healthy for event dispatch
        $transport->method('isConnected')->willReturn(true);
        $client->processTick(); // Transitions to READY since no credentials
        
        $receivedEvent = null;
        $client->onAnyEvent(function(AmiEvent $event) use (&$receivedEvent) {
            $receivedEvent = $event;
        });
        
        $onDataCallback("Event: TestEvent\r\n\r\n");
        $client->processTick();
        
        $this->assertInstanceOf(AmiEvent::class, $receivedEvent);
        $this->assertEquals('node1', $receivedEvent->serverKey);
        $this->assertEquals('TestEvent', $receivedEvent->getName());
    }

    public function testLoginTimeout(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry());
        
        // Use very short login timeout
        $cm = new \Apn\AmiClient\Health\ConnectionManager(loginTimeout: 0.001);
        $client = new AmiClient('node1', $transport, $correlation, null, $cm);
        
        $transport->method('isConnected')->willReturn(true);
        $client->setCredentials('user', 'pass');
        
        // Trigger login
        $client->processTick();
        $this->assertEquals(HealthStatus::AUTHENTICATING, $client->getHealthStatus());
        
        // Wait and tick again
        usleep(2000);
        
        // Should trigger close() and transition to DISCONNECTED
        $transport->expects($this->once())->method('close');
        $client->processTick();
        
        $this->assertEquals(HealthStatus::DISCONNECTED, $client->getHealthStatus());
    }

    public function testConnectTimeoutSchedulesReconnect(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry());

        $cm = new \Apn\AmiClient\Health\ConnectionManager(connectTimeout: 0.001);
        $cm->setStatus(HealthStatus::CONNECTING);

        $client = new AmiClient('node1', $transport, $correlation, null, $cm);

        $transport->method('isConnected')->willReturn(false);
        $transport->expects($this->once())->method('close');
        $transport->expects($this->never())->method('open');

        usleep(2000);
        $client->processTick();

        $this->assertEquals(HealthStatus::DISCONNECTED, $client->getHealthStatus());
    }

    public function testReadTimeoutSchedulesReconnect(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry());

        $cm = new \Apn\AmiClient\Health\ConnectionManager(readTimeout: 0.001);
        $cm->setStatus(HealthStatus::READY);

        $client = new AmiClient('node1', $transport, $correlation, null, $cm);

        $transport->method('isConnected')->willReturn(true);
        $transport->expects($this->once())->method('close');

        usleep(2000);
        $client->processTick();

        $this->assertEquals(HealthStatus::DISCONNECTED, $client->getHealthStatus());
    }

    public function testEventDispatchBlockedDuringAuthentication(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationManager(new ActionIdGenerator('node1', 'testinst'), new CorrelationRegistry());
        
        $onDataCallback = null;
        $transport->method('onData')->willReturnCallback(function($callback) use (&$onDataCallback) {
            $onDataCallback = $callback;
        });
        
        $parser = new Parser();
        $client = new AmiClient('node1', $transport, $correlation, $parser);
        
        $receivedEvent = null;
        $client->onAnyEvent(function(AmiEvent $event) use (&$receivedEvent) {
            $receivedEvent = $event;
        });
        
        $transport->method('isConnected')->willReturn(true);
        $client->setCredentials('user', 'pass');
        
        // Trigger login, status becomes AUTHENTICATING
        $client->processTick();
        $this->assertEquals(HealthStatus::AUTHENTICATING, $client->getHealthStatus());
        
        // Receive an event during authentication
        $onDataCallback("Event: TestEvent\r\n\r\n");
        $client->processTick();
        
        // Should NOT be dispatched
        $this->assertNull($receivedEvent);
        
        // Now succeed login
        // Simulate Login response
        $onDataCallback("Response: Success\r\nActionID: node1:testinst:1\r\n\r\n");
        $client->processTick();
        $this->assertEquals(HealthStatus::READY, $client->getHealthStatus());
        
        // Receive another event
        $onDataCallback("Event: PostLoginEvent\r\n\r\n");
        $client->processTick();
        
        // Should be dispatched
        $this->assertNotNull($receivedEvent);
        $this->assertEquals('PostLoginEvent', $receivedEvent->getName());
    }

    public function testListenerExceptionsDoNotBlockOtherListeners(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry());
        $client = new AmiClient('node1', $transport, $correlation, logger: new NullLogger());

        $transport->method('isConnected')->willReturn(true);
        $client->processTick(); // Move to READY

        $specificCalls = 0;
        $anyCalls = 0;

        $client->onEvent('TestEvent', function () {
            throw new \RuntimeException('boom');
        });
        $client->onEvent('TestEvent', function () use (&$specificCalls) {
            $specificCalls++;
        });
        $client->onAnyEvent(function () use (&$anyCalls) {
            $anyCalls++;
        });

        $ref = new \ReflectionProperty(AmiClient::class, 'eventQueue');
        $eventQueue = $ref->getValue($client);
        $eventQueue->push(AmiEvent::create(new Event(['event' => 'TestEvent']), 'node1'));
        $eventQueue->push(AmiEvent::create(new Event(['event' => 'TestEvent']), 'node1'));

        $client->processTick();

        $this->assertEquals(2, $specificCalls);
        $this->assertEquals(2, $anyCalls);
    }

    public function testSendFailsFastWhenNotReady(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry());
        $client = new AmiClient('node1', $transport, $correlation);

        $this->expectException(InvalidConnectionStateException::class);
        try {
            $client->send(new GenericAction('Ping'));
        } catch (InvalidConnectionStateException $e) {
            $this->assertEquals('node1', $e->getServerKey());
            $this->assertEquals(HealthStatus::DISCONNECTED->value, $e->getState());
            throw $e;
        }
    }

    public function testSendFailsFastDuringAuthentication(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry());
        $cm = new \Apn\AmiClient\Health\ConnectionManager();
        $cm->setStatus(HealthStatus::AUTHENTICATING);
        $client = new AmiClient('node1', $transport, $correlation, null, $cm);

        $this->expectException(InvalidConnectionStateException::class);
        try {
            $client->send(new GenericAction('Ping'));
        } catch (InvalidConnectionStateException $e) {
            $this->assertEquals('node1', $e->getServerKey());
            $this->assertEquals(HealthStatus::AUTHENTICATING->value, $e->getState());
            throw $e;
        }
    }

    public function testBackpressureLogsQueueDepth(): void
    {
        $logger = new class extends AbstractLogger {
            public array $records = [];
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };

        $transport = $this->createMock(TransportInterface::class);
        $registry = new CorrelationRegistry(0);
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), $registry);
        $cm = new \Apn\AmiClient\Health\ConnectionManager();
        $cm->setStatus(HealthStatus::READY);

        $client = new AmiClient('node1', $transport, $correlation, null, $cm, logger: $logger);

        try {
            $client->send(new GenericAction('Ping'));
            $this->fail('Expected backpressure exception');
        } catch (\Apn\AmiClient\Exceptions\BackpressureException) {
            // expected
        }

        $record = $logger->records[0] ?? null;
        $this->assertNotNull($record);
        $this->assertEquals('Action rejected due to backpressure', $record['message']);
        $this->assertArrayHasKey('queue_depth', $record['context']);
        $this->assertArrayHasKey('queue_type', $record['context']);
    }

    public function testHealthReporting(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry());
        
        $client = new AmiClient('node1', $transport, $correlation);
        
        $health = $client->health();
        
        $this->assertEquals('node1', $health['server_key']);
        $this->assertEquals(HealthStatus::DISCONNECTED->value, $health['status']);
        $this->assertFalse($health['connected']);
        $this->assertIsInt($health['memory_usage_bytes']);
        $this->assertEquals(0, $health['pending_actions']);
        $this->assertEquals(0, $health['dropped_events']);
    }

    public function testMetricsRecording(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry());
        $metrics = $this->createMock(MetricsCollectorInterface::class);
        
        $cm = new \Apn\AmiClient\Health\ConnectionManager();
        $cm->setStatus(HealthStatus::READY);
        $client = new AmiClient('node1', $transport, $correlation, null, $cm, metrics: $metrics, host: 'localhost');
        
        // Test latency recording
        $metrics->expects($this->once())
            ->method('record')
            ->with(
                'ami_action_latency_ms',
                $this->isType('float'),
                $this->callback(function($labels) {
                    return $labels['server_key'] === 'node1' && $labels['server_host'] === 'localhost';
                })
            );
        
        $action = new GenericAction('Ping');
        $pending = $client->send($action);
        $pending->resolve(new Response(['Response' => 'Success']));
    }

    public function testCloseSendsLogoff(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry());
        
        $client = new AmiClient('node1', $transport, $correlation);
        
        $transport->method('isConnected')->willReturn(true);
        
        // We expect transport to receive the Logoff action string
        $transport->expects($this->once())
            ->method('send')
            ->with($this->stringContains('Action: Logoff'));
            
        $transport->expects($this->once())->method('close');
        
        $client->close();
    }
}
