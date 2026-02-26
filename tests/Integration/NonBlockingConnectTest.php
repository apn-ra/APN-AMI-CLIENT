<?php

declare(strict_types=1);

namespace Tests\Integration;

use Apn\AmiClient\Cluster\AmiClientManager;
use Apn\AmiClient\Correlation\ActionIdGenerator;
use Apn\AmiClient\Correlation\CorrelationManager;
use Apn\AmiClient\Correlation\CorrelationRegistry;
use Apn\AmiClient\Core\AmiClient;
use Apn\AmiClient\Core\Contracts\TransportInterface;
use Apn\AmiClient\Health\HealthStatus;
use Apn\AmiClient\Protocol\GenericAction;
use Apn\AmiClient\Transport\Reactor;
use Apn\AmiClient\Transport\TcpTransport;
use PHPUnit\Framework\TestCase;

class NonBlockingConnectTest extends TestCase
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

    public function test_connecting_node_does_not_block_other_nodes(): void
    {
        $manager = new AmiClientManager(reactor: new Reactor());

        $goodTransport = new TcpTransport($this->host, $this->port);
        $goodClient = new AmiClient(
            'good',
            $goodTransport,
            new CorrelationManager(new ActionIdGenerator('good'), new CorrelationRegistry())
        );

        $stuckTransport = new class implements TransportInterface {
            public bool $opened = false;
            public function open(): void { $this->opened = true; }
            public function close(): void {}
            public function send(string $data): void {}
            public function tick(int $timeoutMs = 0): void {}
            public function onData(callable $callback): void {}
            public function isConnected(): bool { return false; }
            public function getPendingWriteBytes(): int { return 0; }
            public function terminate(): void {}
        };
        $stuckClient = new AmiClient(
            'stuck',
            $stuckTransport,
            new CorrelationManager(new ActionIdGenerator('stuck'), new CorrelationRegistry())
        );

        $manager->addClient('good', $goodClient);
        $manager->addClient('stuck', $stuckClient);

        $goodClient->open();

        $conn = null;
        for ($i = 0; $i < 20; $i++) {
            $manager->tickAll(10);
            if ($conn === null) {
                $conn = @stream_socket_accept($this->server, 0);
                if ($conn) {
                    stream_set_blocking($conn, false);
                }
            }
            if ($conn !== null && $goodClient->isConnected()) {
                break;
            }
            usleep(10000);
        }

        $this->assertNotNull($conn, "Failed to connect good client");
        $this->assertTrue($goodClient->isConnected());

        // Ensure stuck client is in CONNECTING state and does not complete.
        $manager->tickAll(10);
        $this->assertEquals(HealthStatus::CONNECTING, $stuckClient->getHealthStatus());

        $receivedEvent = false;
        $goodClient->onAnyEvent(function () use (&$receivedEvent) {
            $receivedEvent = true;
        });

        fwrite($conn, "Event: TestEvent\r\n\r\n");

        $goodClient->send(new GenericAction('Ping'));

        $read = '';
        for ($i = 0; $i < 10; $i++) {
            $manager->tickAll(10);
            $chunk = fread($conn, 4096);
            if ($chunk !== false && $chunk !== '') {
                $read .= $chunk;
            }
            if ($receivedEvent) {
                break;
            }
            usleep(10000);
        }

        $this->assertStringContainsString("Action: Ping", $read);
        $this->assertTrue($receivedEvent);
        $this->assertEquals(HealthStatus::CONNECTING, $stuckClient->getHealthStatus());

        fclose($conn);
    }
}
