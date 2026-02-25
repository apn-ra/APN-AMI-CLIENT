<?php

declare(strict_types=1);

namespace Tests\Integration;

use Apn\AmiClient\Core\AmiClient;
use Apn\AmiClient\Core\Contracts\TransportInterface;
use Apn\AmiClient\Correlation\ActionIdGenerator;
use Apn\AmiClient\Correlation\CorrelationRegistry;
use Apn\AmiClient\Health\ConnectionManager;
use Apn\AmiClient\Health\HealthStatus;
use PHPUnit\Framework\TestCase;

class ReconnectStormTest extends TestCase
{
    public function test_reconnect_storm_backoff_and_jitter(): void
    {
        $clients = [];
        $transports = [];
        
        // Simulate 10 clients disconnecting at once
        for ($i = 0; $i < 10; $i++) {
            $transport = $this->createMock(TransportInterface::class);
            $transport->method('isConnected')->willReturn(false);
            
            // ConnectionManager with short delays for testing
            $cm = new ConnectionManager(
                minReconnectDelay: 0.1, // 100ms
                maxReconnectDelay: 2.0,
                jitterFactor: 0.5
            );
            
            $client = new AmiClient(
                "node$i", 
                $transport, 
                new CorrelationRegistry(), 
                new ActionIdGenerator("node$i"),
                connectionManager: $cm
            );
            
            $clients[] = $client;
            $transports[] = $transport;
        }

        // Trigger first reconnect attempt for all
        $reconnectTimes = [];
        foreach ($clients as $client) {
            $client->processTick();
            $this->assertEquals(HealthStatus::CONNECTING, $client->getHealthStatus());
            
            // Access private property nextReconnectTime via reflection to verify spread
            $reconnectTimes[] = $this->getPrivateProperty($client->getConnectionManager(), 'nextReconnectTime');
        }

        // Verify that they are not all scheduled for the exact same time (due to jitter)
        $uniqueTimes = array_unique($reconnectTimes);
        $this->assertGreaterThan(1, count($uniqueTimes), "Reconnect times should have jitter-induced variation");
        
        // Verify they are within expected range [now + 0.1, now + 0.15]
        $now = microtime(true);
        foreach ($reconnectTimes as $time) {
            $this->assertGreaterThanOrEqual($now + 0.05, $time); // 0.1 * 1 + random jitter (0 to 0.05)
            $this->assertLessThanOrEqual($now + 0.25, $time); // 0.1 * 1 + max jitter (0.1 * 0.5 = 0.05) plus some buffer for execution time
        }
    }

    private function getPrivateProperty($object, $propertyName)
    {
        $reflection = new \ReflectionClass($object);
        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);
        return $property->getValue($object);
    }
}
