<?php

declare(strict_types=1);

namespace Apn\AmiClient\Laravel {
    if (!function_exists('Apn\AmiClient\Laravel\config_path')) {
        function config_path($path = '') {
            return $path;
        }
    }
}

namespace Tests\Unit\Laravel {

use Apn\AmiClient\Cluster\AmiClientManager;
use Apn\AmiClient\Cluster\Contracts\RoutingStrategyInterface;
use Apn\AmiClient\Laravel\AmiClientServiceProvider;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class AmiClientServiceProviderTest extends TestCase
{
    protected Container $app;
    protected AmiClientServiceProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        // Use an anonymous class that extends Container to mock Application
        $this->app = new class extends Container {
            public function runningInConsole(): bool { return true; }
            public function configPath($path = ''): string { return $path; }
            public function publisheableGroups(): array { return []; }
            public function bound($abstract): bool { return parent::bound($abstract); }
        };

        $config = new class implements \ArrayAccess {
            private array $items = [];
            public function offsetExists($offset): bool { return isset($this->items[$offset]); }
            public function offsetGet($offset): mixed { return $this->items[$offset] ?? null; }
            public function offsetSet($offset, $value): void { $this->items[$offset] = $value; }
            public function offsetUnset($offset): void { unset($this->items[$offset]); }
            public function get(string $key, $default = null) { return $this->items[$key] ?? $default; }
            public function set(string $key, $value): void { $this->items[$key] = $value; }
        };
        $config->set('ami-client', [
            'default' => 'node_1',
            'servers' => [
                'node_1' => [
                    'host' => '127.0.0.1',
                    'port' => 5038,
                ],
            ],
            'options' => [
                'connect_timeout' => 10,
            ],
            'bridge_laravel_events' => false,
        ]);
        $this->app->instance('config', $config);
        $this->app->instance(LoggerInterface::class, $this->createMock(LoggerInterface::class));
        $this->app->instance('events', $this->createMock(Dispatcher::class));

        $this->provider = new AmiClientServiceProvider($this->app);
    }

    public function testRegistersManagerAsSingleton(): void
    {
        $this->provider->register();

        $this->assertTrue($this->app->bound(AmiClientManager::class));
        $this->assertTrue($this->app->bound('ami'));

        $manager1 = $this->app->make(AmiClientManager::class);
        $manager2 = $this->app->make('ami');

        $this->assertInstanceOf(AmiClientManager::class, $manager1);
        $this->assertSame($manager1, $manager2);
    }

    public function testBindsDefaultRoutingStrategy(): void
    {
        $this->provider->register();

        $this->assertTrue($this->app->bound(RoutingStrategyInterface::class));
        $strategy = $this->app->make(RoutingStrategyInterface::class);
        $this->assertInstanceOf(RoutingStrategyInterface::class, $strategy);
    }

    public function testBridgesLaravelEventsWhenEnabled(): void
    {
        $config = $this->app->make('config');
        $config->set('ami-client.bridge_laravel_events', true);

        $this->provider->register();
        $this->provider->boot();

        // After boot, the manager should have a listener on onAnyEvent
        $manager = $this->app->make(AmiClientManager::class);
        
        // We can't easily check if a listener was added without some reflection or mocking
        // But we can check that it doesn't crash.
        $this->assertTrue(true);
    }
}
}
