<?php

declare(strict_types=1);

namespace Tests\Unit\Cluster;

use Apn\AmiClient\Cluster\AmiClientManager;
use Apn\AmiClient\Cluster\ClientOptions;
use Apn\AmiClient\Cluster\ServerConfig;
use Apn\AmiClient\Cluster\ServerRegistry;
use Apn\AmiClient\Exceptions\InvalidConfigurationException;
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
}
