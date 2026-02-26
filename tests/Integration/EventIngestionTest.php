<?php

declare(strict_types=1);

namespace Tests\Integration;

use Apn\AmiClient\Core\AmiClient;
use Apn\AmiClient\Correlation\CorrelationManager;
use Apn\AmiClient\Core\Contracts\TransportInterface;
use Apn\AmiClient\Correlation\ActionIdGenerator;
use Apn\AmiClient\Correlation\CorrelationRegistry;
use Apn\AmiClient\Events\AmiEvent;
use Apn\AmiClient\Core\EventQueue;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class EventIngestionTest extends TestCase
{
    public function test_it_ingests_and_delivers_events_from_transport(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry());
        
        $onDataCallback = null;
        $transport->method('onData')->willReturnCallback(function($callback) use (&$onDataCallback) {
            $onDataCallback = $callback;
        });
        
        $client = new AmiClient('node1', $transport, $correlation);
        
        $received = [];
        $client->onAnyEvent(function(AmiEvent $event) use (&$received) {
            $received[] = $event->getName();
        });
        
        $onDataCallback("Event: PeerStatus\r\nPeer: PJSIP/101\r\n\r\n");
        $client->processTick();
        
        $this->assertCount(1, $received);
        $this->assertEquals('PeerStatus', $received[0]);
    }

    public function test_it_filters_and_caps_events(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry());
        
        $onDataCallback = null;
        $transport->method('onData')->willReturnCallback(function($callback) use (&$onDataCallback) {
            $onDataCallback = $callback;
        });
        
        $eventQueue = new EventQueue(2);
        
        $client = new AmiClient('node1', $transport, $correlation, eventQueue: $eventQueue);
        
        $received = [];
        $client->onAnyEvent(function(AmiEvent $event) use (&$received) {
            $received[] = $event->getName();
        });
        
        $onDataCallback("Event: E1\r\n\r\n");
        $onDataCallback("Event: E2\r\n\r\n");
        $onDataCallback("Event: E3\r\n\r\n");
        
        $client->processTick();
        
        // After processTick, queue is drained but dropped events count remains
        $this->assertEquals(0, $eventQueue->count());
        $this->assertEquals(1, $eventQueue->getDroppedEventsCount());
        
        $this->assertCount(2, $received);
        $this->assertEquals(['E2', 'E3'], $received);
    }
}
