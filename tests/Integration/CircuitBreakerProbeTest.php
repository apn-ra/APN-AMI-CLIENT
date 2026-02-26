<?php

declare(strict_types=1);

namespace Tests\Integration;

use Apn\AmiClient\Cluster\AmiClientManager;
use Apn\AmiClient\Core\AmiClient;
use Apn\AmiClient\Core\Contracts\TransportInterface;
use Apn\AmiClient\Correlation\ActionIdGenerator;
use Apn\AmiClient\Correlation\CorrelationManager;
use Apn\AmiClient\Correlation\CorrelationRegistry;
use Apn\AmiClient\Health\ConnectionManager;
use Apn\AmiClient\Health\CircuitState;
use Apn\AmiClient\Health\HealthStatus;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

class CircuitBreakerProbeTest extends TestCase
{
    public function testHalfOpenProbeAndTransitionLogging(): void
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

        $manager = new AmiClientManager(logger: $logger);

        $transport = new class implements TransportInterface {
            public function open(): void { throw new \RuntimeException('fail'); }
            public function close(bool $graceful = true): void {}
            public function send(string $data): void {}
            public function tick(int $timeoutMs = 0): void {}
            public function onData(callable $callback): void {}
            public function isConnected(): bool { return false; }
            public function getPendingWriteBytes(): int { return 0; }
            public function terminate(): void {}
        };

        $cm = new ConnectionManager(
            minReconnectDelay: 0.0,
            maxReconnectDelay: 0.0,
            jitterFactor: 0.0,
            circuitFailureThreshold: 1,
            circuitCooldown: 0.05,
            circuitHalfOpenMaxProbes: 1,
            logger: $logger
        );
        $cm->setStatus(HealthStatus::DISCONNECTED);

        $client = new AmiClient(
            'node1',
            $transport,
            new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry()),
            connectionManager: $cm,
            logger: $logger
        );

        $manager->addClient('node1', $client);

        // First attempt -> failure -> circuit opens
        $manager->tickAll();
        $this->assertNotEquals(CircuitState::CLOSED, $cm->getCircuitState());

        usleep(60000);

        // Cooldown elapsed -> HALF_OPEN probe -> failure -> OPEN again
        $manager->tickAll();
        $this->assertNotEquals(CircuitState::CLOSED, $cm->getCircuitState());

        $reasons = [];
        foreach ($logger->records as $record) {
            if ($record['message'] === 'Circuit breaker transition') {
                $reasons[] = $record['context']['reason'] ?? '';
                $this->assertArrayHasKey('consecutive_failures', $record['context']);
                $this->assertArrayHasKey('probe_count', $record['context']);
                $this->assertArrayHasKey('failure_threshold', $record['context']);
                $this->assertArrayHasKey('max_half_open_probes', $record['context']);
                $this->assertArrayHasKey('cooldown_seconds', $record['context']);
            }
        }

        $this->assertContains('failure_threshold', $reasons);
        $this->assertContains('cooldown_elapsed', $reasons);
        $this->assertContains('probe_failed', $reasons);
    }
}
