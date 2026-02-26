<?php

declare(strict_types=1);

namespace Tests\Integration;

use Apn\AmiClient\Cluster\AmiClientManager;
use Apn\AmiClient\Cluster\ClientOptions;
use Apn\AmiClient\Core\AmiClient;
use Apn\AmiClient\Core\Contracts\TransportInterface;
use Apn\AmiClient\Correlation\ActionIdGenerator;
use Apn\AmiClient\Correlation\CorrelationManager;
use Apn\AmiClient\Correlation\CorrelationRegistry;
use Apn\AmiClient\Health\ConnectionManager;
use Apn\AmiClient\Health\HealthStatus;
use PHPUnit\Framework\TestCase;

class ReconnectFairnessTest extends TestCase
{
    public function test_round_robin_reconnect_cursor_prevents_starvation(): void
    {
        $options = new ClientOptions(maxConnectAttemptsPerTick: 1);
        $manager = new AmiClientManager(options: $options);

        $attempts = [
            'n1' => 0,
            'n2' => 0,
            'n3' => 0,
        ];

        foreach (array_keys($attempts) as $key) {
            $transport = new class($attempts, $key) implements TransportInterface {
                public function __construct(private array &$attempts, private string $key) {}
                public function open(): void
                {
                    $this->attempts[$this->key]++;
                    throw new \RuntimeException('connect failed');
                }
                public function close(): void {}
                public function send(string $data): void {}
                public function tick(int $timeoutMs = 0): void {}
                public function onData(callable $callback): void {}
                public function isConnected(): bool { return false; }
                public function getPendingWriteBytes(): int { return 0; }
                public function terminate(): void {}
            };

            $cm = new ConnectionManager(minReconnectDelay: 0.0);
            $cm->setStatus(HealthStatus::DISCONNECTED);

            $client = new AmiClient(
                serverKey: $key,
                transport: $transport,
                correlation: new CorrelationManager(new ActionIdGenerator($key), new CorrelationRegistry()),
                connectionManager: $cm
            );

            $manager->addClient($key, $client);
        }

        $manager->tickAll();
        $manager->tickAll();
        $manager->tickAll();

        $this->assertEquals(1, $attempts['n1']);
        $this->assertEquals(1, $attempts['n2']);
        $this->assertEquals(1, $attempts['n3']);
    }
}
