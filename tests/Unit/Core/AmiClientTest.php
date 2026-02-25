<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use Apn\AmiClient\Core\AmiClient;
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
use PHPUnit\Framework\TestCase;

class AmiClientTest extends TestCase
{
    public function testGetServerKey(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationRegistry();
        $generator = new ActionIdGenerator('node1');
        
        $client = new AmiClient('node1', $transport, $correlation, $generator);
        
        $this->assertEquals('node1', $client->getServerKey());
    }

    public function testGetHealthStatus(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationRegistry();
        $generator = new ActionIdGenerator('node1');
        
        $client = new AmiClient('node1', $transport, $correlation, $generator);
        
        $this->assertEquals(HealthStatus::DISCONNECTED, $client->getHealthStatus());
        
        $transport->method('isConnected')->willReturn(true);
        $client->processTick(); // Transitions from DISCONNECTED to CONNECTED_HEALTHY (since no credentials)
        
        $this->assertEquals(HealthStatus::CONNECTED_HEALTHY, $client->getHealthStatus());
    }

    public function testReconnectionAttempt(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationRegistry();
        $generator = new ActionIdGenerator('node1');
        
        $client = new AmiClient('node1', $transport, $correlation, $generator);
        
        $transport->method('isConnected')->willReturn(false);
        $transport->expects($this->once())->method('open');
        
        $client->processTick(); // Trigger reconnection attempt
        $this->assertEquals(HealthStatus::CONNECTING, $client->getHealthStatus());
    }

    public function testHeartbeatFailureEscalation(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationRegistry();
        $generator = new ActionIdGenerator('node1');
        
        $client = new AmiClient('node1', $transport, $correlation, $generator);
        
        $transport->method('isConnected')->willReturn(true);
        $client->processTick();
        $this->assertEquals(HealthStatus::CONNECTED_HEALTHY, $client->getHealthStatus());

        // We need to trigger heartbeats. ConnectionManager default interval is 15s.
        // Let's use a custom ConnectionManager with short interval.
        $cm = new \Apn\AmiClient\Health\ConnectionManager(heartbeatInterval: 0.001);
        $client = new AmiClient('node1', $transport, $correlation, $generator, null, $cm);
        $client->processTick();
        
        // We'll just manually trigger the failure
        $cm->recordHeartbeatFailure();
        $this->assertEquals(HealthStatus::CONNECTED_DEGRADED, $client->getHealthStatus());

        $cm->recordHeartbeatFailure();
        $this->assertEquals(HealthStatus::CONNECTED_DEGRADED, $client->getHealthStatus());

        $cm->recordHeartbeatFailure();
        $this->assertEquals(HealthStatus::DISCONNECTED, $client->getHealthStatus());
    }

    public function testOnEventWrapsInAmiEvent(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationRegistry();
        $generator = new ActionIdGenerator('node1');
        
        $onDataCallback = null;
        $transport->method('onData')->willReturnCallback(function($callback) use (&$onDataCallback) {
            $onDataCallback = $callback;
        });
        
        $parser = new Parser();
        $client = new AmiClient('node1', $transport, $correlation, $generator, $parser);
        
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

    public function testHealthReporting(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationRegistry();
        $generator = new ActionIdGenerator('node1');
        
        $client = new AmiClient('node1', $transport, $correlation, $generator);
        
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
        $correlation = new CorrelationRegistry();
        $generator = new ActionIdGenerator('node1');
        $metrics = $this->createMock(MetricsCollectorInterface::class);
        
        $client = new AmiClient('node1', $transport, $correlation, $generator, metrics: $metrics, host: 'localhost');
        
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
        $correlation = new CorrelationRegistry();
        $generator = new ActionIdGenerator('node1');
        
        $client = new AmiClient('node1', $transport, $correlation, $generator);
        
        $transport->method('isConnected')->willReturn(true);
        
        // We expect transport to receive the Logoff action string
        $transport->expects($this->once())
            ->method('send')
            ->with($this->stringContains('Action: Logoff'));
            
        $transport->expects($this->once())->method('close');
        
        $client->close();
    }
}
