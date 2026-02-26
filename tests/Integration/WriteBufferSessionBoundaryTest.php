<?php

declare(strict_types=1);

namespace Tests\Integration;

use Apn\AmiClient\Transport\TcpTransport;
use PHPUnit\Framework\TestCase;

class WriteBufferSessionBoundaryTest extends TestCase
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

    private function awaitConnect(TcpTransport $transport)
    {
        $client = null;
        for ($i = 0; $i < 20; $i++) {
            $transport->tick(10);
            if ($client === null) {
                $client = @stream_socket_accept($this->server, 0);
                if ($client) {
                    stream_set_blocking($client, false);
                }
            }
            if ($transport->isConnected() && $client !== null) {
                return $client;
            }
            usleep(10000);
        }

        return $client;
    }

    public function testNonGracefulClosePurgesPendingWritesBeforeReconnect(): void
    {
        $transport = new TcpTransport($this->host, $this->port);
        $transport->open();
        $conn1 = $this->awaitConnect($transport);
        $this->assertNotNull($conn1);

        $transport->send("stale-action\r\n");
        $this->assertGreaterThan(0, $transport->getPendingWriteBytes());

        $transport->close(false);

        if ($conn1) {
            fclose($conn1);
        }

        $transport->open();
        $conn2 = $this->awaitConnect($transport);
        $this->assertNotNull($conn2);

        $received = '';
        for ($i = 0; $i < 5; $i++) {
            $transport->tick(10);
            $chunk = fread($conn2, 4096);
            if ($chunk !== false && $chunk !== '') {
                $received .= $chunk;
            }
            usleep(10000);
        }

        $this->assertSame('', $received, 'Stale bytes should not be emitted after reconnect');

        $transport->send("fresh-action\r\n");
        $transport->tick(10);
        $read = fread($conn2, 4096);
        $this->assertNotFalse($read);
        $this->assertStringContainsString('fresh-action', $read);

        fclose($conn2);
    }
}
