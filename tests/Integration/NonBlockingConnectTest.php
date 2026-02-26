<?php

declare(strict_types=1);

namespace Apn\AmiClient\Cluster;

final class DnsTestHook
{
    private static string $mode = 'passthrough';
    /** @var array<string, string> */
    private static array $map = [];
    private static int $callCount = 0;

    public static function reset(): void
    {
        self::$mode = 'passthrough';
        self::$map = [];
        self::$callCount = 0;
    }

    public static function setResolved(string $host, string $ip): void
    {
        self::$map[$host] = $ip;
    }

    public static function forbid(): void
    {
        self::$mode = 'forbid';
    }

    public static function resolve(string $host): string
    {
        self::$callCount++;
        if (self::$mode === 'forbid') {
            throw new \RuntimeException('DNS resolution attempted during tick');
        }
        if (isset(self::$map[$host])) {
            return self::$map[$host];
        }

        return \gethostbyname($host);
    }

    public static function getCallCount(): int
    {
        return self::$callCount;
    }
}

function gethostbyname(string $host): string
{
    return DnsTestHook::resolve($host);
}

namespace Tests\Integration;

use Apn\AmiClient\Cluster\AmiClientManager;
use Apn\AmiClient\Cluster\ClientOptions;
use Apn\AmiClient\Cluster\DnsTestHook;
use Apn\AmiClient\Cluster\ServerConfig;
use Apn\AmiClient\Cluster\ServerRegistry;
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
        DnsTestHook::reset();
        $this->server = stream_socket_server("tcp://{$this->host}:0", $errno, $errstr);
        if ($this->server === false) {
             $this->fail("Could not start mock server: $errstr");
        }
        $this->port = (int) parse_url(stream_socket_get_name($this->server, false), PHP_URL_PORT);
        stream_set_blocking($this->server, false);
    }

    protected function tearDown(): void
    {
        DnsTestHook::reset();
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

    public function test_hostname_reconnect_does_not_block_tick(): void
    {
        $registry = new ServerRegistry();
        $registry->add(new ServerConfig(
            key: 'good',
            host: $this->host,
            port: $this->port
        ));
        $registry->add(new ServerConfig(
            key: 'hosted',
            host: 'example.test',
            port: 5038
        ));

        DnsTestHook::setResolved('example.test', '203.0.113.1');

        $options = new ClientOptions(
            enforceIpEndpoints: false,
            connectTimeout: 1,
            readTimeout: 1,
            maxConnectAttemptsPerTick: 1
        );

        $manager = new AmiClientManager($registry, $options, reactor: new Reactor());

        $this->assertSame(1, DnsTestHook::getCallCount(), 'Hostname resolution should occur only at bootstrap.');

        DnsTestHook::forbid();

        $goodClient = $manager->server('good');
        $hostnameClient = $manager->server('hosted');

        $goodClient->open();
        $hostnameClient->open();

        $conn = null;
        for ($i = 0; $i < 20; $i++) {
            $start = microtime(true);
            $manager->tickAll(0);
            $elapsedMs = (microtime(true) - $start) * 1000;
            $this->assertLessThan(50.0, $elapsedMs, 'tickAll must remain non-blocking during hostname reconnect.');

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

        $this->assertNotNull($conn, 'Failed to connect good client');
        $this->assertTrue($goodClient->isConnected());
        $this->assertEquals(HealthStatus::CONNECTING, $hostnameClient->getHealthStatus());

        $receivedEvent = false;
        $goodClient->onAnyEvent(function () use (&$receivedEvent) {
            $receivedEvent = true;
        });

        fwrite($conn, "Event: TestEvent\r\n\r\n");
        $goodClient->send(new GenericAction('Ping'));

        $read = '';
        for ($i = 0; $i < 10; $i++) {
            $manager->tickAll(0);
            $chunk = fread($conn, 4096);
            if ($chunk !== false && $chunk !== '') {
                $read .= $chunk;
            }
            if ($receivedEvent) {
                break;
            }
            usleep(10000);
        }

        $this->assertStringContainsString('Action: Ping', $read);
        $this->assertTrue($receivedEvent);
        $this->assertEquals(HealthStatus::CONNECTING, $hostnameClient->getHealthStatus());

        fclose($conn);
    }
}
