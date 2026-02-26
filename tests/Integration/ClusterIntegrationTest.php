<?php

declare(strict_types=1);

namespace Tests\Integration;

use Apn\AmiClient\Cluster\AmiClientManager;
use Apn\AmiClient\Core\AmiClient;
use Apn\AmiClient\Transport\TcpTransport;
use Apn\AmiClient\Correlation\ActionIdGenerator;
use Apn\AmiClient\Correlation\CorrelationRegistry;
use Apn\AmiClient\Correlation\CorrelationManager;
use Apn\AmiClient\Protocol\GenericAction;
use PHPUnit\Framework\TestCase;

class ClusterIntegrationTest extends TestCase
{
    private $server1;
    private $server2;
    private int $port1;
    private int $port2;

    protected function setUp(): void
    {
        // Setup two mock servers
        $this->server1 = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr);
        $this->port1 = (int)parse_url(stream_socket_get_name($this->server1, false))['port'];
        stream_set_blocking($this->server1, false);

        $this->server2 = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr);
        $this->port2 = (int)parse_url(stream_socket_get_name($this->server2, false))['port'];
        stream_set_blocking($this->server2, false);
    }

    protected function tearDown(): void
    {
        if ($this->server1) fclose($this->server1);
        if ($this->server2) fclose($this->server2);
    }

    public function test_it_manages_multiple_server_connections(): void
    {
        $manager = new AmiClientManager();
        
        $c1 = $this->createClient('node1', '127.0.0.1', $this->port1);
        $c2 = $this->createClient('node2', '127.0.0.1', $this->port2);
        
        $manager->addClient('node1', $c1);
        $manager->addClient('node2', $c2);
        
        $c1->open();
        $c2->open();

        // Accept connections on mock servers
        $conn1 = null;
        $conn2 = null;
        for ($i = 0; $i < 20; $i++) {
            $manager->tickAll(10);
            if ($conn1 === null) {
                $conn1 = $this->accept($this->server1);
            }
            if ($conn2 === null) {
                $conn2 = $this->accept($this->server2);
            }
            if ($conn1 !== null && $conn2 !== null && $c1->isConnected() && $c2->isConnected()) {
                break;
            }
            usleep(10000);
        }
        
        $this->assertNotNull($conn1, "Failed to connect to server 1");
        $this->assertNotNull($conn2, "Failed to connect to server 2");
        
        // Mock handshake
        fwrite($conn1, "Asterisk Call Manager/5.0.0\r\n");
        fwrite($conn2, "Asterisk Call Manager/5.0.0\r\n");
        
        $manager->tickAll(10); // Process input
        
        $this->assertTrue($c1->isConnected());
        $this->assertTrue($c2->isConnected());
        
        // Test action routing
        $action = new GenericAction('Ping');
        $pending1 = $c1->send($action);
        
        $manager->tickAll(10); // Process output
        
        $request1 = fread($conn1, 1024);
        $this->assertStringContainsString("Action: Ping", $request1);
        
        // Send response back
        $actionId = $pending1->getAction()->getActionId();
        fwrite($conn1, "Response: Success\r\nActionID: $actionId\r\n\r\n");
        
        $resolved = false;
        $pending1->onComplete(function() use (&$resolved) { $resolved = true; });
        
        $manager->tickAll(10); // Process input
        
        $this->assertTrue($resolved, "Action on node 1 should have resolved");
        
        if ($conn1) fclose($conn1);
        if ($conn2) fclose($conn2);
    }

    private function createClient(string $key, string $host, int $port): AmiClient
    {
        $transport = new TcpTransport($host, $port);
        $correlation = new CorrelationManager(new ActionIdGenerator($key), new CorrelationRegistry());
        return new AmiClient($key, $transport, $correlation);
    }

    private function accept($server)
    {
        $conn = @stream_socket_accept($server, 0);
        if ($conn) {
            stream_set_blocking($conn, false);
        }
        return $conn;
    }
}
