<?php

declare(strict_types=1);

namespace Tests\Integration;

use Apn\AmiClient\Core\AmiClient;
use Apn\AmiClient\Core\Contracts\MetricsCollectorInterface;
use Apn\AmiClient\Core\Contracts\TransportInterface;
use Apn\AmiClient\Core\Logger;
use Apn\AmiClient\Correlation\ActionIdGenerator;
use Apn\AmiClient\Correlation\CorrelationManager;
use Apn\AmiClient\Correlation\CorrelationRegistry;
use Apn\AmiClient\Health\ConnectionManager;
use Apn\AmiClient\Health\HealthStatus;
use PHPUnit\Framework\TestCase;

final class LoggerBackpressureIsolationTest extends TestCase
{
    public function testListenerExceptionLoggingUnderBlockedSinkDoesNotBlockProcessing(): void
    {
        $transport = new class implements TransportInterface {
            private bool $connected = true;
            private ?\Closure $onData = null;

            public function open(): void
            {
                $this->connected = true;
            }

            public function close(bool $graceful = true): void
            {
                $this->connected = false;
            }

            public function send(string $payload): void
            {
            }

            public function tick(int $timeoutMs = 0): void
            {
            }

            public function onData(callable $callback): void
            {
                $this->onData = \Closure::fromCallable($callback);
            }

            public function isConnected(): bool
            {
                return $this->connected;
            }

            public function getPendingWriteBytes(): int
            {
                return 0;
            }

            public function terminate(): void
            {
                $this->connected = false;
            }

            public function receive(string $data): void
            {
                if ($this->onData !== null) {
                    ($this->onData)($data);
                }
            }
        };

        $increments = [];
        $metrics = new class ($increments) implements MetricsCollectorInterface {
            public function __construct(private array &$increments)
            {
            }

            public function increment(string $name, array $labels = [], int $amount = 1): void
            {
                $this->increments[] = ['name' => $name, 'labels' => $labels, 'amount' => $amount];
            }

            public function record(string $name, float $value, array $labels = []): void
            {
            }

            public function set(string $name, float $value, array $labels = []): void
            {
            }
        };

        $logger = new Logger(
            metrics: $metrics,
            sinkCapacity: 1,
            sinkWriter: static fn (string $line): int => 0
        );

        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry());
        $connectionManager = new ConnectionManager();
        $connectionManager->setStatus(HealthStatus::READY);

        $client = new AmiClient(
            'node1',
            $transport,
            $correlation,
            connectionManager: $connectionManager,
            logger: $logger,
            metrics: $metrics,
            host: '127.0.0.1'
        );

        $processed = 0;
        $client->onAnyEvent(function () use (&$processed): void {
            $processed++;
            throw new \RuntimeException('listener failure');
        });

        $transport->receive("Event: TestA\r\n\r\nEvent: TestB\r\n\r\n");
        $client->processTick();

        $this->assertSame(2, $processed, 'Subsequent events must continue under logger backpressure.');

        $dropMetricSeen = false;
        foreach ($increments as $increment) {
            if ($increment['name'] === 'ami_log_sink_dropped_total') {
                $dropMetricSeen = true;
                break;
            }
        }
        $this->assertTrue($dropMetricSeen, 'Expected log sink drop metric under blocked sink.');
    }
}

