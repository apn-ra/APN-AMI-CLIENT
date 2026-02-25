<?php

declare(strict_types=1);

namespace Tests\Unit\Transport;

use Apn\AmiClient\Transport\TcpTransport;
use Apn\AmiClient\Transport\Reactor;
use PHPUnit\Framework\TestCase;

class ReactorTest extends TestCase
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

    public function testReactorMultiplexing(): void
    {
        $reactor = new Reactor();
        
        $t1 = new TcpTransport($this->host, $this->port);
        $t1->open();
        $reactor->register('t1', $t1);
        
        $t2 = new TcpTransport($this->host, $this->port);
        $t2->open();
        $reactor->register('t2', $t2);

        $c1 = null;
        $c2 = null;
        for ($i = 0; $i < 10; $i++) {
            if (!$c1) $c1 = @stream_socket_accept($this->server, 0);
            if (!$c2) $c2 = @stream_socket_accept($this->server, 0);
            if ($c1 && $c2) break;
            usleep(10000);
        }
        
        $this->assertNotNull($c1);
        $this->assertNotNull($c2);

        $t1Data = '';
        $t1->onData(function($d) use (&$t1Data) { $t1Data .= $d; });
        
        $t2Data = '';
        $t2->onData(function($d) use (&$t2Data) { $t2Data .= $d; });

        // Write to both clients
        fwrite($c1, "data1");
        fwrite($c2, "data2");
        
        // One reactor tick should handle both
        $reactor->tick(10);
        
        $this->assertEquals("data1", $t1Data);
        $this->assertEquals("data2", $t2Data);
        
        // Send from transports
        $t1->send("send1");
        $t2->send("send2");
        
        $reactor->tick(10);
        
        $this->assertEquals("send1", fread($c1, 1024));
        $this->assertEquals("send2", fread($c2, 1024));

        fclose($c1);
        fclose($c2);
    }
}
