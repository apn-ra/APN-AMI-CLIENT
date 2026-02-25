<?php

declare(strict_types=1);

namespace Apn\AmiClient\Laravel;

use Apn\AmiClient\Cluster\AmiClientManager;
use Apn\AmiClient\Cluster\ConfigLoader;
use Apn\AmiClient\Laravel\Commands\ListenCommand;
use Illuminate\Support\ServiceProvider;

class AmiClientServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/ami-client.php', 'ami-client');

        $this->app->singleton(AmiClientManager::class, function ($app) {
            return ConfigLoader::load($app['config']['ami-client']);
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
    }
}
