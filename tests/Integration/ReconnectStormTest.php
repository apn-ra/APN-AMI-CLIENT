<?php

declare(strict_types=1);

namespace Tests\Integration;

use Apn\AmiClient\Cluster\AmiClientManager;
use Apn\AmiClient\Cluster\ClientOptions;
use Apn\AmiClient\Cluster\ServerRegistry;
use Apn\AmiClient\Core\AmiClient;
use Apn\AmiClient\Correlation\CorrelationManager;
use Apn\AmiClient\Core\Contracts\AmiClientInterface;
use Apn\AmiClient\Core\Contracts\TransportInterface;
use Apn\AmiClient\Core\TickSummary;
use Apn\AmiClient\Correlation\ActionIdGenerator;
use Apn\AmiClient\Correlation\CorrelationRegistry;
use Apn\AmiClient\Health\ConnectionManager;
use Apn\AmiClient\Health\HealthStatus;
use Apn\AmiClient\Protocol\Action;
use Apn\AmiClient\Correlation\PendingAction;
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

    public function test_cluster_connect_attempt_ceiling_per_tick_is_enforced(): void
    {
        $manager = new AmiClientManager(
            new ServerRegistry(),
            new ClientOptions(maxConnectAttemptsPerTick: 2)
        );

        for ($i = 0; $i < 10; $i++) {
            $manager->addClient("node-{$i}", new class("node-{$i}") implements AmiClientInterface {
                public int $attempts = 0;
                public function __construct(private readonly string $serverKey)
                {
                }
                public function open(): void {}
                public function close(): void {}
                public function send(Action $action): PendingAction
                {
                    throw new \RuntimeException('not used');
                }
                public function onEvent(string $name, callable $listener): void {}
                public function onAnyEvent(callable $listener): void {}
                public function tick(int $timeoutMs = 0): TickSummary
                {
                    return TickSummary::empty();
                }
                public function poll(): void {}
                public function processTick(bool $canAttemptConnect = true): bool
                {
                    if ($canAttemptConnect) {
                        $this->attempts++;
                        return true;
                    }
                    return false;
                }
                public function isConnected(): bool
                {
                    return false;
                }
                public function getServerKey(): string
                {
                    return $this->serverKey;
                }
                public function getHealthStatus(): HealthStatus
                {
                    return HealthStatus::DISCONNECTED;
                }
                public function health(): array
                {
                    return [
                        'server_key' => $this->serverKey,
                        'status' => 'disconnected',
                        'connected' => false,
                        'memory_usage_bytes' => 0,
                        'pending_actions' => 0,
                        'dropped_events' => 0,
                    ];
                }
            });
        }

        $summary = $manager->tickAll(0);

        $this->assertSame(2, $summary->connectAttempts);
        $this->assertSame(2, $manager->getLastTickConnectAttempts());
        $health = $manager->health();
        $this->assertSame(2, $health['_cluster']['connect_attempts_last_tick']);
        $this->assertSame(2, $health['_cluster']['connect_attempt_budget_per_tick']);
        $this->assertSame(10, $health['_cluster']['reconnect_candidates_last_tick']);
        $this->assertSame(8, $health['_cluster']['reconnect_skipped_due_budget_last_tick']);
        $this->assertArrayHasKey('reconnect_cursor_start', $health['_cluster']);
        $this->assertArrayHasKey('reconnect_cursor_next', $health['_cluster']);
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
