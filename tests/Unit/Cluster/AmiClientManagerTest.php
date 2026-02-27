<?php

declare(strict_types=1);

namespace Tests\Unit\Cluster;

use Apn\AmiClient\Cluster\AmiClientManager;
use Apn\AmiClient\Cluster\ClientOptions;
use Apn\AmiClient\Cluster\ServerConfig;
use Apn\AmiClient\Cluster\ServerRegistry;
use Apn\AmiClient\Core\Contracts\AmiClientInterface;
use Apn\AmiClient\Core\Contracts\TransportInterface;
use Apn\AmiClient\Core\TickSummary;
use Apn\AmiClient\Health\HealthStatus;
use Apn\AmiClient\Exceptions\InvalidConfigurationException;
use Apn\AmiClient\Protocol\Action;
use Apn\AmiClient\Correlation\PendingAction;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class AmiClientManagerTest extends TestCase
{
    public function testRejectsHostnameWhenIpOnlyPolicyIsEnabled(): void
    {
        $registry = new ServerRegistry();
        $registry->add(new ServerConfig(
            key: 'node1',
            host: 'localhost',
            port: 5038
        ));

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('enforce_ip_endpoints is enabled');

        new AmiClientManager($registry, new ClientOptions(enforceIpEndpoints: true));
    }

    public function testRejectsHostnameWithoutResolverWhenIpPolicyDisabled(): void
    {
        $registry = new ServerRegistry();
        $registry->add(new ServerConfig(
            key: 'node1',
            host: 'example.test',
            port: 5038
        ));

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('pre-resolved IP or an injected hostname resolver');

        new AmiClientManager($registry, new ClientOptions(enforceIpEndpoints: false));
    }

    public function testTickHonorsTimeoutAndClampsAboveMax(): void
    {
        $manager = new AmiClientManager();

        $timeouts = [];
        $client = new class ($timeouts) implements AmiClientInterface {
            public function __construct(private array &$timeouts)
            {
            }
            public function open(): void {}
            public function close(): void {}
            public function send(Action $action): PendingAction
            {
                throw new \RuntimeException('not needed');
            }
            public function onEvent(string $name, callable $listener): void {}
            public function onAnyEvent(callable $listener): void {}
            public function tick(int $timeoutMs = 0): TickSummary
            {
                $this->timeouts[] = $timeoutMs;
                return TickSummary::empty();
            }
            public function poll(): void {}
            public function processTick(bool $canAttemptConnect = true): bool
            {
                return false;
            }
            public function isConnected(): bool
            {
                return true;
            }
            public function getServerKey(): string
            {
                return 'node1';
            }
            public function getHealthStatus(): HealthStatus
            {
                return HealthStatus::READY;
            }
            public function health(): array
            {
                return [
                    'server_key' => 'node1',
                    'status' => 'ready',
                    'connected' => true,
                    'memory_usage_bytes' => 0,
                    'pending_actions' => 0,
                    'dropped_events' => 0,
                ];
            }
        };

        $manager->addClient('node1', $client);
        $manager->tick('node1', 50);
        $manager->tick('node1', TransportInterface::MAX_TICK_TIMEOUT_MS + 5);

        $this->assertSame([50, TransportInterface::MAX_TICK_TIMEOUT_MS], $timeouts);
    }

    public function testTickRejectsNegativeTimeout(): void
    {
        $manager = new AmiClientManager();

        $client = new class implements AmiClientInterface {
            public function open(): void {}
            public function close(): void {}
            public function send(Action $action): PendingAction
            {
                throw new \RuntimeException('not needed');
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
                return false;
            }
            public function isConnected(): bool
            {
                return true;
            }
            public function getServerKey(): string
            {
                return 'node1';
            }
            public function getHealthStatus(): HealthStatus
            {
                return HealthStatus::READY;
            }
            public function health(): array
            {
                return [
                    'server_key' => 'node1',
                    'status' => 'ready',
                    'connected' => true,
                    'memory_usage_bytes' => 0,
                    'pending_actions' => 0,
                    'dropped_events' => 0,
                ];
            }
        };

        $manager->addClient('node1', $client);

        $this->expectException(\InvalidArgumentException::class);
        $manager->tick('node1', -1);
    }

    public function testTickAllEmitsAggregateMetrics(): void
    {
        $metrics = new class implements \Apn\AmiClient\Core\Contracts\MetricsCollectorInterface {
            public array $records = [];
            public array $sets = [];
            public function increment(string $name, array $labels = [], int $amount = 1): void {}
            public function record(string $name, float $value, array $labels = []): void
            {
                $this->records[] = ['name' => $name, 'labels' => $labels];
            }
            public function set(string $name, float $value, array $labels = []): void
            {
                $this->sets[] = ['name' => $name, 'labels' => $labels];
            }
        };

        $manager = new AmiClientManager(metrics: $metrics);
        $manager->tickAll(0);

        $names = array_column($metrics->records, 'name');
        $this->assertContains('ami_tick_duration_ms', $names);

        $setNames = array_column($metrics->sets, 'name');
        $this->assertContains('ami_tick_progress', $setNames);
    }

    public function testTickAllContainsLoggerExceptionsWhenClientProcessingFails(): void
    {
        $throwingLogger = new class extends AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('logger failure');
            }
        };

        $manager = new AmiClientManager(logger: $throwingLogger);
        $client = new class implements AmiClientInterface {
            public function open(): void {}
            public function close(): void {}
            public function send(Action $action): PendingAction
            {
                throw new \RuntimeException('not needed');
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
                throw new \RuntimeException('process tick failed');
            }
            public function isConnected(): bool
            {
                return true;
            }
            public function getServerKey(): string
            {
                return 'node1';
            }
            public function getHealthStatus(): HealthStatus
            {
                return HealthStatus::READY;
            }
            public function health(): array
            {
                return [
                    'server_key' => 'node1',
                    'status' => 'ready',
                    'connected' => true,
                    'memory_usage_bytes' => 0,
                    'pending_actions' => 0,
                    'dropped_events' => 0,
                ];
            }
        };

        $manager->addClient('node1', $client);
        $manager->tickAll(0);

        $this->assertTrue(true);
    }
}
