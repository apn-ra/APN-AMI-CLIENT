<?php

declare(strict_types=1);

namespace Tests\Integration;

use Apn\AmiClient\Core\AmiClient;
use Apn\AmiClient\Correlation\CorrelationManager;
use Apn\AmiClient\Core\Contracts\TransportInterface;
use Apn\AmiClient\Correlation\ActionIdGenerator;
use Apn\AmiClient\Correlation\CorrelationRegistry;
use Apn\AmiClient\Protocol\GenericAction;
use Apn\AmiClient\Events\AmiEvent;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

#[AllowMockObjectsWithoutExpectations]
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
        
        // Use default 10,000 capacity for events, but large tick budget for testing
        $client = new AmiClient(
            'node1', 
            $transport, 
            new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry()),
            maxFramesPerTick: 20000,
            maxEventsPerTick: 20000
        );
        
        $receivedCount = 0;
        $client->onAnyEvent(function(AmiEvent $event) use (&$receivedCount) {
            $receivedCount++;
        });

        // Simulate 12,000 events arriving (flood)
        for ($i = 0; $i < 12000; $i++) {
            $onDataCallback("Event: FloodEvent\r\n\r\n");
        }
        
        // Dispatch events
        $client->processTick();
        
        // Verify drop policy
        $health = $client->health();
        $this->assertEquals(2000, $health['dropped_events']);
        $this->assertEquals(10000, $receivedCount);
        
        // Test backpressure on actions
        $registry = new CorrelationRegistry(10);
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), $registry);
        $clientWithBackpressure = new AmiClient('node1', $transport, $correlation);
        $clientWithBackpressure->processTick();
        
        for ($i = 0; $i < 10; $i++) {
            $clientWithBackpressure->send(new GenericAction('Ping'));
        }
        
        $this->expectException(\Apn\AmiClient\Exceptions\BackpressureException::class);
        $clientWithBackpressure->send(new GenericAction('Ping'));
    }

    public function test_sustained_flood_uses_rate_limited_drop_logging_and_keeps_processing(): void
    {
        $transport = new class implements TransportInterface {
            private ?\Closure $onData = null;

            public function open(): void {}
            public function close(bool $graceful = true): void {}
            public function send(string $data): void {}
            public function tick(int $timeoutMs = 0): void {}
            public function isConnected(): bool { return true; }
            public function getPendingWriteBytes(): int { return 0; }
            public function terminate(): void {}

            public function onData(callable $callback): void
            {
                $this->onData = \Closure::fromCallable($callback);
            }

            public function receive(string $data): void
            {
                if ($this->onData !== null) {
                    ($this->onData)($data);
                }
            }
        };

        $logger = new class extends AbstractLogger {
            public array $records = [];
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => strtoupper((string) $level),
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };

        $now = 2000.0;
        $client = new AmiClient(
            'node1',
            $transport,
            new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry()),
            eventQueue: new \Apn\AmiClient\Core\EventQueue(100),
            logger: $logger,
            maxFramesPerTick: 1000,
            maxEventsPerTick: 20,
            eventDropLogIntervalMs: 250,
            clock: static function () use (&$now): float {
                return $now;
            }
        );

        $processed = 0;
        $client->onAnyEvent(function (AmiEvent $event) use (&$processed): void {
            $processed++;
        });

        for ($tick = 0; $tick < 10; $tick++) {
            for ($i = 0; $i < 300; $i++) {
                $transport->receive("Event: FloodEvent\r\n\r\n");
            }
            $client->processTick();
            $now += 0.1;
        }

        $summaryWarnings = 0;
        foreach ($logger->records as $record) {
            if ($record['message'] === 'Event drops summary due to queue capacity') {
                $summaryWarnings++;
            }
        }

        $durationMs = 1000;
        $intervalMs = 250;
        $maxExpectedWarnings = intdiv($durationMs, $intervalMs) + 1;
        $this->assertLessThanOrEqual($maxExpectedWarnings, $summaryWarnings);
        $this->assertGreaterThan(0, $summaryWarnings);
        $this->assertGreaterThan(0, $processed);
        $this->assertGreaterThan(0, $client->health()['dropped_events']);
    }
}
