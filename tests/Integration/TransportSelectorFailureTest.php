<?php

declare(strict_types=1);

namespace Tests\Integration;

use Apn\AmiClient\Cluster\AmiClientManager;
use Apn\AmiClient\Core\AmiClient;
use Apn\AmiClient\Core\Contracts\MetricsCollectorInterface;
use Apn\AmiClient\Correlation\ActionIdGenerator;
use Apn\AmiClient\Correlation\CorrelationManager;
use Apn\AmiClient\Correlation\CorrelationRegistry;
use Apn\AmiClient\Health\ConnectionManager;
use Apn\AmiClient\Health\HealthStatus;
use Apn\AmiClient\Transport\Reactor;
use Apn\AmiClient\Transport\TcpTransport;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

class TransportSelectorFailureTest extends TestCase
{
    public function testSelectorFailureLogsAndSchedulesReconnect(): void
    {
        $logger = new class extends AbstractLogger {
            public array $records = [];
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };

        $metrics = new class implements MetricsCollectorInterface {
            public array $increments = [];
            public function increment(string $name, array $labels = [], int $amount = 1): void
            {
                $this->increments[] = [
                    'name' => $name,
                    'labels' => $labels,
                    'amount' => $amount,
                ];
            }
            public function record(string $name, float $value, array $labels = []): void {}
            public function set(string $name, float $value, array $labels = []): void {}
        };

        $reactor = new Reactor($logger, $metrics);

        $transport = new class ($logger, $metrics) extends TcpTransport {
            public bool $openCalled = false;
            public bool $closeCalled = false;
            private $fakeResource;
            private bool $connected = true;

            public function __construct($logger, $metrics)
            {
                parent::__construct(
                    '127.0.0.1',
                    5038,
                    logger: $logger,
                    metrics: $metrics,
                    labels: [
                        'server_key' => 'node1',
                        'server_host' => '127.0.0.1',
                    ]
                );
                $this->fakeResource = stream_context_create();
            }

            public function open(): void
            {
                $this->openCalled = true;
            }

            public function close(): void
            {
                $this->closeCalled = true;
                $this->connected = false;
            }

            public function isConnected(): bool
            {
                return $this->connected;
            }

            public function isConnecting(): bool
            {
                return false;
            }

            public function hasPendingWrites(): bool
            {
                return false;
            }

            public function getResource()
            {
                return $this->fakeResource;
            }

            public function read(): void
            {
            }

            public function handleWriteReady(): void
            {
            }
        };

        $cm = new ConnectionManager(
            minReconnectDelay: 0.0,
            maxReconnectDelay: 0.0,
            jitterFactor: 0.0,
            logger: $logger
        );
        $cm->setStatus(HealthStatus::DISCONNECTED);

        $client = new AmiClient(
            'node1',
            $transport,
            new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry()),
            connectionManager: $cm,
            logger: $logger,
            metrics: $metrics,
            host: '127.0.0.1'
        );

        $manager = new AmiClientManager(logger: $logger, reactor: $reactor, metrics: $metrics);
        $manager->addClient('node1', $client);

        $manager->tickAll();

        $this->assertTrue($transport->closeCalled);
        $this->assertTrue($transport->openCalled);

        $logRecord = null;
        foreach ($logger->records as $record) {
            if ($record['message'] === 'Reactor stream_select failed') {
                $logRecord = $record;
                break;
            }
        }

        $this->assertNotNull($logRecord);
        $this->assertSame('node1', $logRecord['context']['server_key'] ?? null);
        $this->assertSame('stream_select', $logRecord['context']['operation'] ?? null);

        $foundMetric = false;
        foreach ($metrics->increments as $increment) {
            if ($increment['name'] === 'ami_transport_errors_total'
                && ($increment['labels']['server_key'] ?? '') === 'node1') {
                $foundMetric = true;
                break;
            }
        }
        $this->assertTrue($foundMetric);
    }
}
