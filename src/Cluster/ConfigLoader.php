<?php

declare(strict_types=1);

namespace Apn\AmiClient\Cluster;


use Psr\Log\LoggerInterface;

/**
 * Helper to bootstrap AmiClientManager from configuration arrays.
 */
final class ConfigLoader
{
    /**
     * Bootstraps an AmiClientManager from a configuration array.
     */
    public static function load(array $config, ?LoggerInterface $logger = null): AmiClientManager
    {
        $globalOptionsArr = $config['options'] ?? [];
        $globalOptions = ClientOptions::fromArray($globalOptionsArr);
        
        $registry = new ServerRegistry();
        $servers = $config['servers'] ?? [];

        foreach ($servers as $key => $serverConfigArr) {
            $registry->add(ServerConfig::fromArray((string)$key, $serverConfigArr));
        }

        $manager = new AmiClientManager($registry, $globalOptions, $logger);

        if (isset($config['default'])) {
            $manager->setDefaultServer((string)$config['default']);
        }

        return $manager;
    }

}
