<?php

declare(strict_types=1);

namespace Apn\AmiClient\Laravel;

use Apn\AmiClient\Cluster\AmiClientManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Apn\AmiClient\Core\Contracts\AmiClientInterface server(string $key)
 * @method static \Apn\AmiClient\Core\Contracts\AmiClientInterface default()
 * @method static void tickAll(int $timeoutMs = 0)
 * @method static void onEvent(string $eventName, callable $listener)
 * @method static void onAnyEvent(callable $listener)
 * @method static array health()
 *
 * @see \Apn\AmiClient\Cluster\AmiClientManager
 */
class Ami extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'ami';
    }
}
