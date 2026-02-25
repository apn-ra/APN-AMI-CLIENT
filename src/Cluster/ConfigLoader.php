<?php

declare(strict_types=1);

namespace Apn\AmiClient\Cluster;


/**
 * Helper to bootstrap AmiClientManager from configuration arrays.
 */
final class ConfigLoader
{
    /**
     * Bootstraps an AmiClientManager from a configuration array.
     */
    public static function load(array $config): AmiClientManager
    {
        $globalOptionsArr = $config['options'] ?? [];
        $globalOptions = ClientOptions::fromArray($globalOptionsArr);
        
        $registry = new ServerRegistry();
        $servers = $config['servers'] ?? [];

        foreach ($servers as $key => $serverConfigArr) {
            $registry->add(ServerConfig::fromArray((string)$key, $serverConfigArr));
        }

        $manager = new AmiClientManager($registry, $globalOptions);

        if (isset($config['default'])) {
            $manager->setDefaultServer((string)$config['default']);
        }

        return $manager;
    }

}
