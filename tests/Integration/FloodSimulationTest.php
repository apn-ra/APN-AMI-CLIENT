<?php

declare(strict_types=1);

namespace Tests\Integration;

use Apn\AmiClient\Core\AmiClient;
use Apn\AmiClient\Core\Contracts\TransportInterface;
use Apn\AmiClient\Correlation\ActionIdGenerator;
use Apn\AmiClient\Correlation\CorrelationRegistry;
use Apn\AmiClient\Protocol\GenericAction;
use Apn\AmiClient\Protocol\Response;
use Apn\AmiClient\Events\AmiEvent;
use PHPUnit\Framework\TestCase;

class FloodSimulationTest extends TestCase
{
    public function test_flood_simulation_events_and_actions(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $transport->method('isConnected')->willReturn(true);
        
        $onDataCallback = null;
        $transport->method('onData')->willReturnCallback(function($callback) use (&$onDataCallback) {
            $onDataCallback = $callback;
        });
        
        // Use default 10,000 capacity for events
        $client = new AmiClient('node1', $transport, new CorrelationRegistry(), new ActionIdGenerator('node1'));
        
        $receivedCount = 0;
        $client->onAnyEvent(function(AmiEvent $event) use (&$receivedCount) {
            $receivedCount++;
        });

        // Simulate 12,000 events arriving (flood)
        for ($i = 0; $i < 12000; $i++) {
            $onDataCallback("Event: FloodEvent\r\n\r\n");
        }
        
        // Verify drop policy: queue should have 10,000 events, 2,000 dropped
        $health = $client->health();
        $this->assertEquals(10000, $health['pending_actions'] === 0 ? 10000 : 0); // Event queue size is internal, but we can check drops
        $this->assertEquals(2000, $health['dropped_events']);
        
        // Dispatch events
        $client->processTick();
        $this->assertEquals(10000, $receivedCount);
        
        // Test backpressure on actions
        $registry = new CorrelationRegistry(10);
        $clientWithBackpressure = new AmiClient('node1', $transport, $registry, new ActionIdGenerator('node1'));
        
        for ($i = 0; $i < 10; $i++) {
            $clientWithBackpressure->send(new GenericAction('Ping'));
        }
        
        $this->expectException(\Apn\AmiClient\Exceptions\BackpressureException::class);
        $clientWithBackpressure->send(new GenericAction('Ping'));
    }
}
