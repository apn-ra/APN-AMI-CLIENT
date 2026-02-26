<?php

declare(strict_types=1);

namespace Tests\Unit\Cluster;

use Apn\AmiClient\Cluster\ConfigLoader;
use Apn\AmiClient\Exceptions\InvalidConfigurationException;
use PHPUnit\Framework\TestCase;

final class ConfigLoaderTest extends TestCase
{
    public function testRejectsHostnameWhenIpOnlyPolicyIsEnabled(): void
    {
        $config = [
            'default' => 'node1',
            'options' => [
                'enforce_ip_endpoints' => true,
            ],
            'servers' => [
                'node1' => [
                    'host' => 'localhost',
                    'port' => 5038,
                ],
            ],
        ];

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('enforce_ip_endpoints is enabled');

        ConfigLoader::load($config);
    }
}

