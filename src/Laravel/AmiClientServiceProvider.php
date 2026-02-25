<?php

declare(strict_types=1);

namespace Apn\AmiClient\Laravel;

use Apn\AmiClient\Cluster\AmiClientManager;
use Apn\AmiClient\Cluster\ConfigLoader;
use Apn\AmiClient\Cluster\Contracts\RoutingStrategyInterface;
use Apn\AmiClient\Cluster\Routing\RoundRobinRoutingStrategy;
use Apn\AmiClient\Laravel\Commands\ListenCommand;
use Apn\AmiClient\Laravel\Events\AmiEventReceived;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

class AmiClientServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/ami-client.php', 'ami-client');

        $this->app->bind(RoutingStrategyInterface::class, RoundRobinRoutingStrategy::class);

        $this->app->singleton(AmiClientManager::class, function ($app) {
            $manager = ConfigLoader::load(
                $app['config']['ami-client'],
                $app->make(LoggerInterface::class)
            );

            if ($app->bound(RoutingStrategyInterface::class)) {
                $manager->routing($app->make(RoutingStrategyInterface::class));
            }

            return $manager;
        });

        $this->app->alias(AmiClientManager::class, 'ami');
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/ami-client.php' => config_path('ami-client.php'),
            ], 'ami-config');

            $this->commands([
                ListenCommand::class,
            ]);
        }

        if ($this->app['config']['ami-client.bridge_laravel_events'] ?? false) {
            $this->app->make(AmiClientManager::class)->onAnyEvent(function ($event) {
                AmiEventReceived::dispatch($event);
            });
        }
    }
}
