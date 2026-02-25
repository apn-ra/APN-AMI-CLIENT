<?php

declare(strict_types=1);

namespace Tests\Unit\Transport;

use Apn\AmiClient\Transport\TcpTransport;
use Apn\AmiClient\Exceptions\BackpressureException;
use Apn\AmiClient\Exceptions\ConnectionException;
use PHPUnit\Framework\TestCase;

class TcpTransportTest extends TestCase
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

    public function testConnectAndClose(): void
    {
        $transport = new TcpTransport($this->host, $this->port);
        $transport->open();
        $this->assertTrue($transport->isConnected());

        $transport->close();
        $this->assertFalse($transport->isConnected());
    }

    public function testConnectFails(): void
    {
        // Use a port that is unlikely to be open.
        $transport = new TcpTransport($this->host, 1);
        
        $this->expectException(ConnectionException::class);
        $transport->open();
    }

    public function testSendAndReceive(): void
    {
        $transport = new TcpTransport($this->host, $this->port);
        $transport->open();

        $client = null;
        for ($i = 0; $i < 10; $i++) {
            $client = @stream_socket_accept($this->server, 0);
            if ($client) break;
            usleep(10000);
        }
        $this->assertNotNull($client);

        $receivedData = '';
        $transport->onData(function (string $data) use (&$receivedData) {
            $receivedData .= $data;
        });

        // Send from client to transport
        fwrite($client, "hello\n");
        $transport->tick(10); // Wait 10ms for read

        $this->assertEquals("hello\n", $receivedData);

        // Send from transport to client
        $transport->send("world\n");
        $transport->tick(10); // Flush write buffer

        $read = fread($client, 1024);
        $this->assertEquals("world\n", $read);

        fclose($client);
    }

    public function testBufferOverflowRejection(): void
    {
        // Set a small buffer limit of 10 bytes
        $transport = new TcpTransport($this->host, $this->port, 30, 10);
        $transport->open();

        $transport->send("1234567890"); // OK
        $this->assertTrue($transport->isConnected());

        $this->expectException(BackpressureException::class);
        $transport->send("1"); // Should fail and drop connection
    }

    public function testDropConnectionOnOverflow(): void
    {
        $transport = new TcpTransport($this->host, $this->port, 30, 10);
        $transport->open();

        try {
            $transport->send("12345678901");
        } catch (BackpressureException $e) {
            // Expected
        }

        $this->assertFalse($transport->isConnected(), "Connection should be dropped on buffer overflow");
    }

    public function testTerminate(): void
    {
        $transport = new TcpTransport($this->host, $this->port);
        $transport->open();
        $transport->send("some data");
        $transport->terminate();

        $this->assertFalse($transport->isConnected());
        // Internal check: write buffer should be cleared too.
        $this->assertFalse($transport->hasPendingWrites());
    }
}
