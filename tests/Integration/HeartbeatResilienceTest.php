<?php

declare(strict_types=1);

namespace Tests\Integration;

use Apn\AmiClient\Core\AmiClient;
use Apn\AmiClient\Correlation\ActionIdGenerator;
use Apn\AmiClient\Correlation\CorrelationManager;
use Apn\AmiClient\Correlation\CorrelationRegistry;
use Apn\AmiClient\Health\ConnectionManager;
use Apn\AmiClient\Health\HealthStatus;
use Apn\AmiClient\Protocol\Ping;
use Apn\AmiClient\Transport\TcpTransport;
use PHPUnit\Framework\TestCase;

class HeartbeatResilienceTest extends TestCase
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

    public function test_it_force_closes_on_max_heartbeat_failures(): void
    {
        // 1. Setup client with small heartbeat interval and max 2 failures
        $transport = new TcpTransport($this->host, $this->port);
        $registry = new CorrelationRegistry();
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), $registry);
        
        $cm = new ConnectionManager(
            heartbeatInterval: 0.05, // 50ms
            maxHeartbeatFailures: 2,
            minReconnectDelay: 0.1,
            readTimeout: 0.5 // 500ms
        );
        
        $client = new AmiClient(
            serverKey: 'node1',
            transport: $transport,
            correlation: $correlation,
            connectionManager: $cm,
            host: $this->host,
            port: $this->port,
            readTimeout: 0.5
        );

        $client->open();

        // 2. Accept connection
        $conn = null;
        for ($i = 0; $i < 20; $i++) {
            $client->tick(10);
            if ($conn === null) {
                $conn = @stream_socket_accept($this->server, 0);
            }
            if ($conn && $client->isConnected()) break;
            usleep(10000);
        }
        $this->assertNotNull($conn, "Failed to connect to mock server");
        $this->assertTrue($client->isConnected());
        
        // 3. Set status to READY to enable heartbeats
        $cm->setStatus(HealthStatus::READY);
        $this->assertEquals(HealthStatus::READY, $client->getHealthStatus());

        // 4. Send some action and keep it pending
        $pending = $client->send(new Ping());
        $this->assertEquals(1, $registry->count());

        // 5. Wait for heartbeats to fail (server doesn't respond)
        // We need to drive the tick loop.
        // On each heartbeat interval, a Ping is sent.
        // Since the registry registry has 1 (our Ping) + heartbeats.
        
        $forceClosed = false;
        for ($i = 0; $i < 100; $i++) {
            $client->tick(10);
            // Send periodic events to keep the connection alive and avoid Read Timeout
            if ($i % 5 === 0 && $conn) {
                @fwrite($conn, "Event: KeepAlive\r\n\r\n");
            }
            if (!$client->isConnected()) {
                $forceClosed = true;
                break;
            }
            usleep(10000);
        }

        // 6. Assertions
        $this->assertTrue($forceClosed, "Client should have force-closed the connection due to heartbeat failure");
        $this->assertFalse($client->isConnected());
        $this->assertEquals(HealthStatus::DISCONNECTED, $client->getHealthStatus());
        $this->assertEquals(0, $registry->count(), "Correlation registry should have been purged");
        
        $failedAction = false;
        $failureReason = '';
        $pending->onComplete(function (?\Throwable $e) use (&$failedAction, &$failureReason) {
            if ($e) {
                $failedAction = true;
                $failureReason = $e->getMessage();
            }
        });
        
        $this->assertTrue($failedAction, "Pending action should have been failed with heartbeat failure reason");
        $this->assertStringContainsString("Max heartbeat failures", $failureReason);

        if ($conn) fclose($conn);
    }
}
