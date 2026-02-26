<?php

declare(strict_types=1);

namespace Tests\Unit\Cluster;

use Apn\AmiClient\Cluster\AmiClientManager;
use Apn\AmiClient\Cluster\ClientOptions;
use Apn\AmiClient\Cluster\ServerConfig;
use Apn\AmiClient\Cluster\ServerRegistry;
use Apn\AmiClient\Core\Contracts\AmiClientInterface;
use Apn\AmiClient\Health\HealthStatus;
use Apn\AmiClient\Exceptions\InvalidConfigurationException;
use Apn\AmiClient\Protocol\Action;
use Apn\AmiClient\Correlation\PendingAction;
use PHPUnit\Framework\TestCase;

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

    public function testTickClampsPositiveTimeoutInRuntimeMode(): void
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
            public function tick(int $timeoutMs = 0): void
            {
                $this->timeouts[] = $timeoutMs;
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
        $manager->tick('node1', -5);

        $this->assertSame([0, 0], $timeouts);
    }
}
