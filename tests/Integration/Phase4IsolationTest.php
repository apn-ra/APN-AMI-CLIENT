<?php

declare(strict_types=1);

namespace Tests\Integration;

use Apn\AmiClient\Core\AmiClient;
use Apn\AmiClient\Correlation\ActionIdGenerator;
use Apn\AmiClient\Correlation\CorrelationManager;
use Apn\AmiClient\Correlation\CorrelationRegistry;
use Apn\AmiClient\Events\AmiEvent;
use Apn\AmiClient\Health\HealthStatus;
use Apn\AmiClient\Protocol\Parser;
use Apn\AmiClient\Transport\TcpTransport;
use PHPUnit\Framework\TestCase;

class Phase4IsolationTest extends TestCase
{
    private function createFakeTransport()
    {
        return new class implements \Apn\AmiClient\Core\Contracts\TransportInterface {
            public bool $connected = false;
            public $dataCallback = null;
            public function isConnected(): bool { return $this->connected; }
            public function getPendingWriteBytes(): int { return 0; }
            public function open(): void { $this->connected = true; }
            public function close(): void { $this->connected = false; }
            public function onData(callable $callback): void { $this->dataCallback = $callback; }
            public function send(string $data): void {}
            public function tick(int $timeoutMs = 0): void {}
            public function getStream() { return fopen('php://memory', 'r+'); }
            public function terminate(): void { $this->close(); }
        };
    }

    public function testLoginFailureAndRecovery(): void
    {
        $transport = $this->createFakeFakeTransport();
        $correlation = new CorrelationManager(new ActionIdGenerator('node1', 'inst1'), new CorrelationRegistry());
        
        $client = new AmiClient('node1', $transport, $correlation);
        $client->setCredentials('user', 'pass');
        
        // 0. Connect and Simulate Banner
        $client->open();
        $this->assertTrue($transport->connected);
        ($transport->dataCallback)("Asterisk Call Manager/5.0.1\r\n");
        
        // 1. Initial login attempt
        $client->processTick();
        $this->assertEquals(HealthStatus::AUTHENTICATING, $client->getHealthStatus());
        
        // 2. Simulate login failure
        ($transport->dataCallback)("Response: Error\r\nActionID: node1:inst1:1\r\nMessage: Authentication failed\r\n\r\n");
        $client->processTick();
        
        // The first tick after failure will call close() and remain DISCONNECTED
        $this->assertEquals(HealthStatus::DISCONNECTED, $client->getHealthStatus());
        $this->assertFalse($transport->connected);
        
        // 3. Wait for backoff (default min delay 100ms)
        usleep(150000);
        
        // 4. Next tick should trigger retry (recordReconnectAttempt and open())
        $client->processTick();
        $this->assertEquals(HealthStatus::CONNECTING, $client->getHealthStatus());
        $this->assertTrue($transport->connected);
        
        // 5. Simulate Banner again for the new connection
        ($transport->dataCallback)("Asterisk Call Manager/5.0.1\r\n");
        $client->processTick();
        $this->assertEquals(HealthStatus::AUTHENTICATING, $client->getHealthStatus());
        
        // 6. Simulate successful login response
        $registry = $this->getPrivateProperty($correlation, 'registry');
        $pending = $this->getPrivateProperty($registry, 'pending');
        $ids = array_keys($pending);
        $this->assertNotEmpty($ids);
        $actionIdNow = $ids[0];
        
        ($transport->dataCallback)("Response: Success\r\nActionID: {$actionIdNow}\r\nMessage: Authentication accepted\r\n\r\n");
        $client->processTick();
        $this->assertEquals(HealthStatus::READY, $client->getHealthStatus());
    }

    private function createFakeFakeTransport() { return $this->createFakeTransport(); }

    public function testParserCorruptionIsolation(): void
    {
        $transport = $this->createFakeTransport();
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry());
        
        $parser = new Parser();
        $client = new AmiClient('node1', $transport, $correlation, $parser);
        
        // Connect first
        $client->open();
        $this->assertTrue($transport->connected);
        
        // Simulate banner so it becomes healthy (no credentials)
        ($transport->dataCallback)("Asterisk Call Manager/5.0.1\r\n");
        $client->processTick();
        $this->assertEquals(HealthStatus::READY, $client->getHealthStatus());
        
        $receivedEvents = [];
        $client->onAnyEvent(function(AmiEvent $e) use (&$receivedEvents) {
            $receivedEvents[] = $e->getName();
        });
        
        // Inject garbage
        ($transport->dataCallback)("Garbage Data without delimiters and colons that should be ignored or cause desync\r\n");
        $client->processTick();
        
        // Inject valid event after garbage
        ($transport->dataCallback)("\r\n\r\nEvent: ValidEvent\r\n\r\n");
        $client->processTick();
        
        // Parser should have recovered and picked up the ValidEvent
        $this->assertContains('ValidEvent', $receivedEvents);
    }

    private function getPrivateProperty($object, $propertyName)
    {
        $reflection = new \ReflectionClass($object);
        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);
        return $property->getValue($object);
    }
}
