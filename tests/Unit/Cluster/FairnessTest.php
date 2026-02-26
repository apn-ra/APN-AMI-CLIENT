<?php

declare(strict_types=1);

namespace Tests\Unit\Cluster;

use Apn\AmiClient\Cluster\AmiClientManager;
use Apn\AmiClient\Cluster\ClientOptions;
use Apn\AmiClient\Cluster\ServerRegistry;
use Apn\AmiClient\Core\AmiClient;
use Apn\AmiClient\Core\Contracts\TransportInterface;
use Apn\AmiClient\Correlation\ActionIdGenerator;
use Apn\AmiClient\Correlation\CorrelationManager;
use Apn\AmiClient\Correlation\CorrelationRegistry;
use Apn\AmiClient\Events\AmiEvent;
use Apn\AmiClient\Transport\TcpTransport;
use PHPUnit\Framework\TestCase;

class FairnessTest extends TestCase
{
    public function test_max_frames_per_tick_budget(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $client = new AmiClient(
            serverKey: 'node1',
            transport: $transport,
            correlation: new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry()),
            maxFramesPerTick: 2
        );

        $processed = 0;
        $client->onAnyEvent(function() use (&$processed) {
            $processed++;
        });

        // Push 5 events into parser
        $ref = new \ReflectionProperty(AmiClient::class, 'parser');
        $parser = $ref->getValue($client);
        $parser->push("Event: E1\r\n\r\nEvent: E2\r\n\r\nEvent: E3\r\n\r\nEvent: E4\r\n\r\nEvent: E5\r\n\r\n");

        // First tick - should process only 2
        $client->processTick();
        $this->assertEquals(2, $processed);

        // Second tick - should process next 2
        $client->processTick();
        $this->assertEquals(4, $processed);

        // Third tick - should process last 1
        $client->processTick();
        $this->assertEquals(5, $processed);
    }

    public function test_max_events_per_tick_budget(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $client = new AmiClient(
            serverKey: 'node1',
            transport: $transport,
            correlation: new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry()),
            maxEventsPerTick: 3
        );

        $processed = 0;
        $client->onAnyEvent(function() use (&$processed) {
            $processed++;
        });

        // Queue 10 events directly into event queue
        $ref = new \ReflectionProperty(AmiClient::class, 'eventQueue');
        $eventQueue = $ref->getValue($client);
        for ($i = 0; $i < 10; $i++) {
            $eventQueue->push(AmiEvent::create(new \Apn\AmiClient\Protocol\Event(['event' => 'Test']), 'node1'));
        }

        // First tick - should process 3
        $client->processTick();
        $this->assertEquals(3, $processed);

        // Second tick - should process next 3
        $client->processTick();
        $this->assertEquals(6, $processed);
    }

    public function test_max_bytes_read_per_tick(): void
    {
        // We need a real-ish TcpTransport but mocked resource
        $transport = new TcpTransport('localhost', 5038, 30, 1024*1024, 10); // 10 bytes limit
        
        $resource = fopen('php://temp', 'r+');
        fwrite($resource, "1234567890ABCDEFGHIJ");
        rewind($resource);
        
        $ref = new \ReflectionProperty(TcpTransport::class, 'resource');
        $ref->setValue($transport, $resource);

        $received = '';
        $transport->onData(function($data) use (&$received) {
            $received .= $data;
        });

        // Read 1 - should only get 10 bytes
        $transport->read();
        $this->assertEquals("1234567890", $received);

        // Read 2 - should get next 10 bytes
        $transport->read();
        $this->assertEquals("1234567890ABCDEFGHIJ", $received);
        
        fclose($resource);
    }

    public function test_max_connect_attempts_per_tick(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $transport->method('isConnected')->willReturn(false);
        
        // Create ConnectionManager with 0 delay to allow multiple attempts in same tick
        $cm = new \Apn\AmiClient\Health\ConnectionManager(
            minReconnectDelay: 0.0,
            maxConnectAttemptsPerTick: 2
        );
        $cm->setStatus(\Apn\AmiClient\Health\HealthStatus::DISCONNECTED);

        $client = new AmiClient(
            serverKey: 'node1',
            transport: $transport,
            correlation: new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry()),
            connectionManager: $cm,
            maxConnectAttemptsPerTick: 2
        );

        // Mock transport->open to throw to reset status to DISCONNECTED
        $transport->expects($this->exactly(2))->method('open')->willThrowException(new \Exception('fail'));

        // Tick 1 - should attempt open
        $client->processTick(); 
        // Tick 2 - should attempt open
        $client->processTick();
        // Tick 3 - should NOT attempt open (budget hit)
        $client->processTick();
    }
}
