<?php

declare(strict_types=1);

namespace Tests\Performance;

use Apn\AmiClient\Core\AmiClient;
use Apn\AmiClient\Core\Contracts\TransportInterface;
use Apn\AmiClient\Core\EventQueue;
use Apn\AmiClient\Correlation\ActionIdGenerator;
use Apn\AmiClient\Correlation\CorrelationRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class FloodSimulationTest extends TestCase
{
    public function test_flood_simulation(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $onDataCallback = null;
        $transport->method('onData')->willReturnCallback(function($callback) use (&$onDataCallback) {
            $onDataCallback = $callback;
        });
        
        // Mock logger to avoid spamming stdout during flood
        $logger = $this->createMock(\Apn\AmiClient\Core\Logger::class);
        
        $client = new AmiClient(
            'node1',
            $transport,
            new CorrelationRegistry(100),
            new ActionIdGenerator('node1'),
            logger: $logger
        );
        
        $client->onAnyEvent(function() { /* do nothing */ });

        // Simulate 1000 events
        for ($i = 0; $i < 1000; $i++) {
            $onDataCallback("Event: TestEvent\r\n\r\n");
        }
        
        /** @var EventQueue $queue */
        $queue = $this->getProperty($client, 'eventQueue');
        
        // Default capacity is 10000, so none should be dropped yet.
        $this->assertEquals(1000, $queue->count());
        $this->assertEquals(0, $queue->getDroppedEventsCount());
        
        // Replace with a new client with low capacity queue to test drop policy
        $lowCapacityQueue = new EventQueue(100);
        $client = new AmiClient(
            'node1',
            $transport,
            new CorrelationRegistry(100),
            new ActionIdGenerator('node1'),
            eventQueue: $lowCapacityQueue,
            logger: $logger
        );
        
        // The transport onData was registered in constructor, we need to get the new one
        $reflection = new ReflectionClass($client);
        $onRawDataMethod = $reflection->getMethod('onRawData');
        $onDataCallback = $onRawDataMethod->getClosure($client);

        // We expect warnings for each dropped event
        $logger->expects($this->exactly(200))->method('warning');
        
        for ($i = 0; $i < 300; $i++) {
             $onDataCallback("Event: Overflow\r\n\r\n");
        }
        
        $this->assertEquals(100, $lowCapacityQueue->count());
        $this->assertEquals(200, $lowCapacityQueue->getDroppedEventsCount());
    }

    private function getProperty(object $object, string $propertyName)
    {
        $reflection = new ReflectionClass($object);
        $property = $reflection->getProperty($propertyName);
        return $property->getValue($object);
    }
    
    private function setProperty(object $object, string $propertyName, mixed $value): void
    {
        $reflection = new ReflectionClass($object);
        $property = $reflection->getProperty($propertyName);
        $property->setValue($object, $value);
    }
}
