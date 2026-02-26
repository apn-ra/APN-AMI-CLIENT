<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use Apn\AmiClient\Core\AmiClient;
use Apn\AmiClient\Correlation\CorrelationManager;
use Apn\AmiClient\Core\Contracts\TransportInterface;
use Apn\AmiClient\Core\EventFilter;
use Apn\AmiClient\Core\EventQueue;
use Apn\AmiClient\Correlation\ActionIdGenerator;
use Apn\AmiClient\Correlation\CorrelationRegistry;
use Apn\AmiClient\Events\AmiEvent;
use Apn\AmiClient\Exceptions\BackpressureException;
use Apn\AmiClient\Protocol\Parser;
use PHPUnit\Framework\TestCase;

class FloodControlTest extends TestCase
{
    public function test_event_queue_drops_oldest_when_full(): void
    {
        $queue = new EventQueue(2);
        
        $event1 = AmiEvent::create(new \Apn\AmiClient\Protocol\Event(['event' => 'Event1']), 'node1');
        $event2 = AmiEvent::create(new \Apn\AmiClient\Protocol\Event(['event' => 'Event2']), 'node1');
        $event3 = AmiEvent::create(new \Apn\AmiClient\Protocol\Event(['event' => 'Event3']), 'node1');
        
        $queue->push($event1);
        $queue->push($event2);
        $this->assertEquals(2, $queue->count());
        
        $queue->push($event3); // Should drop event1
        $this->assertEquals(2, $queue->count());
        $this->assertEquals(1, $queue->getDroppedEventsCount());
        
        $this->assertSame($event2, $queue->pop());
        $this->assertSame($event3, $queue->pop());
    }

    public function test_event_filter_blocks_events(): void
    {
        $filter = new EventFilter(blockedEvents: ['BlockedEvent']);
        
        $event1 = AmiEvent::create(new \Apn\AmiClient\Protocol\Event(['event' => 'AllowedEvent']), 'node1');
        $event2 = AmiEvent::create(new \Apn\AmiClient\Protocol\Event(['event' => 'BlockedEvent']), 'node1');
        
        $this->assertTrue($filter->shouldKeep($event1));
        $this->assertFalse($filter->shouldKeep($event2));
    }

    public function test_event_filter_allows_only_whitelisted(): void
    {
        $filter = new EventFilter(allowedEvents: ['OnlyThis']);
        
        $event1 = AmiEvent::create(new \Apn\AmiClient\Protocol\Event(['event' => 'OnlyThis']), 'node1');
        $event2 = AmiEvent::create(new \Apn\AmiClient\Protocol\Event(['event' => 'SomethingElse']), 'node1');
        
        $this->assertTrue($filter->shouldKeep($event1));
        $this->assertFalse($filter->shouldKeep($event2));
    }

    public function test_ami_client_queues_and_dispatches_events(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $onDataCallback = null;
        $transport->method('onData')->willReturnCallback(function($callback) use (&$onDataCallback) {
            $onDataCallback = $callback;
        });
        $transport->method('isConnected')->willReturn(true);
        
        $client = new AmiClient(
            'node1',
            $transport,
            new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry())
        );
        
        // Move to healthy first, otherwise events are dropped (Phase 4 Task 5)
        $client->open();
        $onDataCallback("Asterisk Call Manager/5.0.1\r\n");
        $client->processTick();
        
        $received = [];
        $client->onEvent('TestEvent', function(AmiEvent $e) use (&$received) {
            $received[] = $e->getName();
        });
        
        $client->onAnyEvent(function(AmiEvent $e) use (&$received) {
            $received[] = 'Any:' . $e->getName();
        });
        
        $onDataCallback("Event: TestEvent\r\n\r\n");
        $onDataCallback("Event: OtherEvent\r\n\r\n");
        
        $this->assertEmpty($received); // Still in queue
        
        $client->processTick();
        
        $this->assertEquals(['TestEvent', 'Any:TestEvent', 'Any:OtherEvent'], $received);
    }

    public function test_backpressure_exception_on_max_pending(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry(1));
        $client = new AmiClient(
            'node1',
            $transport,
            $correlation
        );
        
        $action1 = new \Apn\AmiClient\Protocol\GenericAction('Action1');
        $action2 = new \Apn\AmiClient\Protocol\GenericAction('Action2');
        
        $client->send($action1);
        
        $this->expectException(BackpressureException::class);
        $client->send($action2);
    }

    public function test_oom_protection_terminates_client(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $client = new AmiClient(
            'node1',
            $transport,
            new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry())
        );
        
        $client->setMemoryLimit(100); // Very low limit
        
        $transport->expects($this->once())->method('close');
        $transport->expects($this->once())->method('terminate');
        
        $client->processTick();
    }
}
