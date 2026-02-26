<?php

declare(strict_types=1);

namespace Tests\Integration;

use Apn\AmiClient\Correlation\ActionIdGenerator;
use Apn\AmiClient\Correlation\CorrelationManager;
use Apn\AmiClient\Correlation\CorrelationRegistry;
use Apn\AmiClient\Core\AmiClient;
use Apn\AmiClient\Health\ConnectionManager;
use Apn\AmiClient\Health\HealthStatus;
use Apn\AmiClient\Transport\TcpTransport;
use PHPUnit\Framework\TestCase;

final class AsyncConnectFallbackTest extends TestCase
{
    /** @var resource|null */
    private $server = null;
    private string $host = '127.0.0.1';
    private int $port = 0;

    protected function setUp(): void
    {
        $this->server = stream_socket_server("tcp://{$this->host}:0", $errno, $errstr);
        if ($this->server === false) {
            $this->fail("Could not start mock server: $errstr");
        }
        $this->port = (int) parse_url(stream_socket_get_name($this->server, false), PHP_URL_PORT);
        stream_set_blocking($this->server, false);
    }

    protected function tearDown(): void
    {
        if ($this->server !== null) {
            @fclose($this->server);
        }
    }

    public function testFallbackVerificationFailureSchedulesReconnect(): void
    {
        $transport = new class ($this->host, $this->port) extends TcpTransport {
            protected function socketHelpersAvailable(): bool
            {
                return false;
            }

            protected function getPeerName($resource): string|false
            {
                return '127.0.0.1:1234';
            }

            protected function probeWritable($resource): bool
            {
                return false;
            }
        };

        $connectionManager = new ConnectionManager(connectTimeout: 0.0);
        $client = new AmiClient(
            'node1',
            $transport,
            new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry()),
            connectionManager: $connectionManager
        );

        $client->open();
        $client->tick(0);

        $this->assertSame(HealthStatus::DISCONNECTED, $client->getHealthStatus());
        $this->assertFalse($client->isConnected());
        $this->assertSame(1, $client->getConnectionManager()->getReconnectAttempts());

        $conn = @stream_socket_accept($this->server, 0);
        if ($conn) {
            fclose($conn);
        }
    }
}
