<?php

declare(strict_types=1);

namespace Tests\Integration;

use Apn\AmiClient\Cluster\ConfigLoader;
use Apn\AmiClient\Cluster\DnsTestHook;
use PHPUnit\Framework\TestCase;
use Tests\Support\RuntimeEnvironment;

require_once __DIR__ . '/../Support/RuntimeEnvironment.php';

final class ConfigLoaderHostnameResolverTest extends TestCase
{
    /** @var resource|null */
    private $server = null;
    private string $host = '127.0.0.1';
    private int $port = 0;

    protected function setUp(): void
    {
        DnsTestHook::reset();
        $runtime = RuntimeEnvironment::createTcpServerOrSkip($this, $this->host);
        $this->server = $runtime['server'];
        $this->port = $runtime['port'];
    }

    protected function tearDown(): void
    {
        DnsTestHook::reset();
        if (is_resource($this->server)) {
            @fclose($this->server);
        }
    }

    public function test_hostname_endpoint_bootstraps_with_injected_resolver(): void
    {
        DnsTestHook::setResolved('example.test', $this->host);

        $config = [
            'default' => 'node1',
            'options' => [
                'enforce_ip_endpoints' => false,
                'connect_timeout' => 1,
                'read_timeout' => 1,
            ],
            'servers' => [
                'node1' => [
                    'host' => 'example.test',
                    'port' => $this->port,
                ],
            ],
        ];

        $manager = ConfigLoader::load(
            $config,
            hostnameResolver: static fn (string $host): string => DnsTestHook::resolve($host)
        );

        $client = $manager->server('node1');
        $client->open();

        $conn = null;
        for ($i = 0; $i < 20; $i++) {
            $manager->tickAll(0);
            if ($conn === null) {
                $conn = @stream_socket_accept($this->server, 0);
                if ($conn) {
                    stream_set_blocking($conn, false);
                }
            }
            if ($conn !== null && $client->isConnected()) {
                break;
            }
            usleep(10000);
        }

        $this->assertNotNull($conn, 'Failed to accept connection');
        $this->assertTrue($client->isConnected());

        if ($conn) {
            fclose($conn);
        }
    }
}
