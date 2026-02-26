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

    public function testConnectAndClose(): void
    {
        $transport = new TcpTransport($this->host, $this->port);
        $transport->open();
        $client = $this->awaitConnect($transport);
        $this->assertNotNull($client);
        $this->assertTrue($transport->isConnected());

        $transport->close();
        $this->assertFalse($transport->isConnected());

        if ($client) {
            fclose($client);
        }
    }

    public function testConnectAndCloseWhenIpPolicyIsEnabled(): void
    {
        $transport = new TcpTransport($this->host, $this->port, enforceIpEndpoints: true);
        $transport->open();
        $client = $this->awaitConnect($transport);
        $this->assertNotNull($client);
        $this->assertTrue($transport->isConnected());

        $transport->close();
        $this->assertFalse($transport->isConnected());

        if ($client) {
            fclose($client);
        }
    }

    public function testRejectsHostnameByPolicyByDefault(): void
    {
        $transport = new TcpTransport('localhost', $this->port);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Hostname endpoints are disabled by policy');
        $transport->open();
    }

    public function testRejectsHostnameWhenIpPolicyIsEnabled(): void
    {
        $transport = new TcpTransport('localhost', $this->port, enforceIpEndpoints: true);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Hostname endpoints are disabled by policy');
        $transport->open();
    }

    public function testConnectFails(): void
    {
        // Use a port that is unlikely to be open.
        $transport = new TcpTransport($this->host, 1);

        try {
            $transport->open();
        } catch (ConnectionException) {
            $this->assertTrue(true);
            return;
        }

        for ($i = 0; $i < 5; $i++) {
            $transport->tick(10);
            usleep(10000);
        }

        $this->assertFalse($transport->isConnected());
    }

    public function testAsyncConnectFallbackSucceedsWithPeerAndProbe(): void
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
                return true;
            }
        };

        $transport->open();
        $client = $this->awaitConnect($transport);

        $this->assertNotNull($client);
        $this->assertTrue($transport->isConnected());

        $transport->close();

        if ($client) {
            fclose($client);
        }
    }

    public function testAsyncConnectFailsWithoutVerificationWhenSocketHelpersUnavailable(): void
    {
        $transport = new class ($this->host, $this->port) extends TcpTransport {
            protected function socketHelpersAvailable(): bool
            {
                return false;
            }

            protected function getPeerName($resource): string|false
            {
                return false;
            }

            protected function probeWritable($resource): bool
            {
                return true;
            }
        };

        $transport->open();

        $client = null;
        for ($i = 0; $i < 10; $i++) {
            $transport->tick(10);
            if ($client === null) {
                $client = @stream_socket_accept($this->server, 0);
                if ($client) {
                    stream_set_blocking($client, false);
                }
            }
            if (!$transport->isConnecting()) {
                break;
            }
            usleep(10000);
        }

        $this->assertFalse($transport->isConnected());
        $this->assertFalse($transport->isConnecting());
        $this->assertNull($transport->getResource());

        if ($client) {
            fclose($client);
        }
    }

    public function testAsyncConnectFailsWhenFallbackProbeFails(): void
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

        $transport->open();

        $client = null;
        for ($i = 0; $i < 10; $i++) {
            $transport->tick(10);
            if ($client === null) {
                $client = @stream_socket_accept($this->server, 0);
                if ($client) {
                    stream_set_blocking($client, false);
                }
            }
            if (!$transport->isConnecting()) {
                break;
            }
            usleep(10000);
        }

        $this->assertFalse($transport->isConnected());
        $this->assertFalse($transport->isConnecting());
        $this->assertNull($transport->getResource());

        if ($client) {
            fclose($client);
        }
    }

    public function testSendAndReceive(): void
    {
        $transport = new TcpTransport($this->host, $this->port);
        $transport->open();

        $client = $this->awaitConnect($transport);
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
        $client = $this->awaitConnect($transport);
        $this->assertNotNull($client);

        $transport->send("1234567890"); // OK
        $this->assertTrue($transport->isConnected());

        $this->expectException(BackpressureException::class);
        $transport->send("1"); // Should fail and drop connection

        if ($client) {
            fclose($client);
        }
    }

    public function testDropConnectionOnOverflow(): void
    {
        $transport = new TcpTransport($this->host, $this->port, 30, 10);
        $transport->open();
        $client = $this->awaitConnect($transport);
        $this->assertNotNull($client);

        try {
            $transport->send("12345678901");
        } catch (BackpressureException $e) {
            // Expected
        }

        $this->assertFalse($transport->isConnected(), "Connection should be dropped on buffer overflow");

        if ($client) {
            fclose($client);
        }
    }

    public function testTerminate(): void
    {
        $transport = new TcpTransport($this->host, $this->port);
        $transport->open();
        $client = $this->awaitConnect($transport);
        $this->assertNotNull($client);
        $transport->send("some data");
        $transport->terminate();

        $this->assertFalse($transport->isConnected());
        // Internal check: write buffer should be cleared too.
        $this->assertFalse($transport->hasPendingWrites());

        if ($client) {
            fclose($client);
        }
    }
}
