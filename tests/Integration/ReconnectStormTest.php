<?php

declare(strict_types=1);

namespace Tests\Integration;

use Apn\AmiClient\Core\AmiClient;
use Apn\AmiClient\Correlation\CorrelationManager;
use Apn\AmiClient\Core\Contracts\TransportInterface;
use Apn\AmiClient\Correlation\ActionIdGenerator;
use Apn\AmiClient\Correlation\CorrelationRegistry;
use Apn\AmiClient\Health\ConnectionManager;
use Apn\AmiClient\Health\HealthStatus;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ReconnectStormTest extends TestCase
{
    public function test_reconnect_storm_backoff_and_jitter(): void
    {
        $clients = [];
        $transports = [];
        $firstDelays = [];
        $secondDelays = [];
        
        // Simulate 10 clients disconnecting at once
        for ($i = 0; $i < 10; $i++) {
            $transport = $this->createStub(TransportInterface::class);
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
                new CorrelationManager(new ActionIdGenerator("node$i"), new CorrelationRegistry()),
                connectionManager: $cm,
                logger: $this->createStub(LoggerInterface::class)
            );
            
            $clients[] = $client;
            $transports[] = $transport;
        }

        // Trigger first reconnect attempt for all
        $reconnectTimes = [];
        foreach ($clients as $client) {
            $start = microtime(true);
            $client->processTick();
            $this->assertEquals(HealthStatus::CONNECTING, $client->getHealthStatus());
            
            // Access private property nextReconnectTime via reflection to verify spread
            $reconnectTimes[] = $this->getPrivateProperty($client->getConnectionManager(), 'nextReconnectTime');
            $firstDelays[] = end($reconnectTimes) - $start;
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

        // Force a second reconnect attempt and verify backoff increases
        foreach ($clients as $index => $client) {
            $client->getConnectionManager()->setStatus(HealthStatus::DISCONNECTED);
            $this->setPrivateProperty($client->getConnectionManager(), 'nextReconnectTime', microtime(true) - 0.001);
            $client->resetTickBudgets();

            $start = microtime(true);
            $client->processTick();
            $secondTime = $this->getPrivateProperty($client->getConnectionManager(), 'nextReconnectTime');
            $secondDelays[] = $secondTime - $start;

            $this->assertGreaterThan(
                $firstDelays[$index],
                $secondDelays[$index],
                'Backoff should increase on subsequent reconnect attempts'
            );
        }
    }

    private function getPrivateProperty($object, $propertyName)
    {
        $reflection = new \ReflectionClass($object);
        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);
        return $property->getValue($object);
    }

    private function setPrivateProperty(object $object, string $propertyName, mixed $value): void
    {
        $reflection = new \ReflectionClass($object);
        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }
}
