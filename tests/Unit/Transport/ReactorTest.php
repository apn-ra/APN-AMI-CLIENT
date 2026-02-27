<?php

declare(strict_types=1);

namespace Tests\Unit\Transport;

use Apn\AmiClient\Transport\TcpTransport;
use Apn\AmiClient\Transport\Reactor;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

class ReactorTest extends TestCase
{
    /** @var resource|null */
    private $server = null;
    private string $host = '127.0.0.1';
    private int $port = 0;

    protected function setUp(): void
    {
        $this->server = @stream_socket_server("tcp://{$this->host}:0", $errno, $errstr);
        if ($this->server === false) {
            if ($this->name() === 'testReactorMultiplexing') {
                $this->markTestSkipped("Could not start mock server: $errstr");
            }
            return;
        }
        if (is_resource($this->server)) {
            $this->port = (int) parse_url(stream_socket_get_name($this->server, false), PHP_URL_PORT);
            stream_set_blocking($this->server, false);
        }
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            @fclose($this->server);
        }
    }

    private function awaitConnect(Reactor $reactor, TcpTransport $t1, TcpTransport $t2): array
    {
        $c1 = null;
        $c2 = null;
        for ($i = 0; $i < 20; $i++) {
            $reactor->tick(10);

            if (!$c1) {
                $c1 = @stream_socket_accept($this->server, 0);
                if ($c1) {
                    stream_set_blocking($c1, false);
                }
            }
            if (!$c2) {
                $c2 = @stream_socket_accept($this->server, 0);
                if ($c2) {
                    stream_set_blocking($c2, false);
                }
            }

            if ($t1->isConnected() && $t2->isConnected() && $c1 && $c2) {
                break;
            }

            usleep(10000);
        }

        return [$c1, $c2];
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

        [$c1, $c2] = $this->awaitConnect($reactor, $t1, $t2);
        
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

    public function testSelectorFailureUsesSingleSelectorCallAndClosesAffectedTransport(): void
    {
        $reactor = new class extends Reactor {
            public int $selectorCalls = 0;

            protected function selectStreams(array &$read, array &$write, ?array &$except, int $seconds, int $microseconds): int|false
            {
                $this->selectorCalls++;
                return false;
            }
        };

        $transport = new class extends TcpTransport {
            public bool $closed = false;
            private $resource;

            public function __construct()
            {
                parent::__construct('127.0.0.1', 5038);
                $this->resource = stream_context_create();
            }

            public function isConnected(): bool
            {
                return true;
            }

            public function isConnecting(): bool
            {
                return false;
            }

            public function getResource()
            {
                return $this->resource;
            }

            public function close(bool $graceful = true): void
            {
                $this->closed = true;
            }
        };

        $reactor->register('node-a', $transport);
        $reactor->tick(0);

        $this->assertSame(1, $reactor->selectorCalls);
        $this->assertTrue($transport->closed);
    }

    public function testTickContainsLoggerExceptionsOnSelectorFailure(): void
    {
        $throwingLogger = new class extends AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('logger failure');
            }
        };

        $reactor = new class($throwingLogger) extends Reactor {
            public function __construct($logger)
            {
                parent::__construct($logger);
            }

            protected function selectStreams(array &$read, array &$write, ?array &$except, int $seconds, int $microseconds): int|false
            {
                return false;
            }
        };

        $transport = new class extends TcpTransport {
            private $resource;

            public function __construct()
            {
                parent::__construct('127.0.0.1', 5038);
                $this->resource = stream_context_create();
            }

            public function isConnected(): bool
            {
                return true;
            }

            public function isConnecting(): bool
            {
                return false;
            }

            public function getResource()
            {
                return $this->resource;
            }
        };

        $reactor->register('node-a', $transport);
        $reactor->tick(0);

        $this->assertTrue(true);
    }
}
