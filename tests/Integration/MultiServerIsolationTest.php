<?php

declare(strict_types=1);

namespace Tests\Integration;

use Apn\AmiClient\Cluster\AmiClientManager;
use Apn\AmiClient\Core\AmiClient;
use Apn\AmiClient\Core\Contracts\TransportInterface;
use Apn\AmiClient\Correlation\ActionIdGenerator;
use Apn\AmiClient\Correlation\CorrelationRegistry;
use Apn\AmiClient\Events\AmiEvent;
use PHPUnit\Framework\TestCase;

class MultiServerIsolationTest extends TestCase
{
    public function test_event_isolation_between_servers(): void
    {
        $manager = new AmiClientManager();
        
        $t1 = $this->createMock(TransportInterface::class);
        $t1_callback = null;
        $t1->method('onData')->willReturnCallback(function($cb) use (&$t1_callback) { $t1_callback = $cb; });
        
        $t2 = $this->createMock(TransportInterface::class);
        $t2_callback = null;
        $t2->method('onData')->willReturnCallback(function($cb) use (&$t2_callback) { $t2_callback = $cb; });
        
        $c1 = new AmiClient('node1', $t1, new CorrelationRegistry(), new ActionIdGenerator('node1'));
        $c2 = new AmiClient('node2', $t2, new CorrelationRegistry(), new ActionIdGenerator('node2'));
        
        $manager->addClient('node1', $c1);
        $manager->addClient('node2', $c2);
        
        $node1Received = [];
        $c1->onEvent('TestEvent', function(AmiEvent $e) use (&$node1Received) {
            $node1Received[] = $e;
        });
        
        $node2Received = [];
        $c2->onEvent('TestEvent', function(AmiEvent $e) use (&$node2Received) {
            $node2Received[] = $e;
        });
        
        $globalReceived = [];
        $manager->onEvent('TestEvent', function(AmiEvent $e) use (&$globalReceived) {
            $globalReceived[] = $e;
        });
        
        // Trigger event on node 1
        $t1_callback("Event: TestEvent\r\n\r\n");
        $c1->processTick();
        
        $this->assertCount(1, $node1Received);
        $this->assertCount(0, $node2Received);
        $this->assertCount(1, $globalReceived);
        $this->assertEquals('node1', $globalReceived[0]->serverKey);
        
        // Trigger event on node 2
        $t2_callback("Event: TestEvent\r\n\r\n");
        $c2->processTick();
        
        $this->assertCount(1, $node1Received);
        $this->assertCount(1, $node2Received);
        $this->assertCount(2, $globalReceived);
        $this->assertEquals('node2', $globalReceived[1]->serverKey);
    }

    public function test_action_id_isolation_between_servers(): void
    {
        $t1 = $this->createMock(TransportInterface::class);
        $t1_callback = null;
        $t1->method('onData')->willReturnCallback(function($cb) use (&$t1_callback) { $t1_callback = $cb; });
        
        $t2 = $this->createMock(TransportInterface::class);
        $t2_callback = null;
        $t2->method('onData')->willReturnCallback(function($cb) use (&$t2_callback) { $t2_callback = $cb; });
        
        // Note: they use the same instance id if we're not careful, but ActionIdGenerator includes serverKey
        $c1 = new AmiClient('node1', $t1, new CorrelationRegistry(), new ActionIdGenerator('node1', 'worker1'));
        $c2 = new AmiClient('node2', $t2, new CorrelationRegistry(), new ActionIdGenerator('node2', 'worker1'));
        
        $p1 = $c1->send(new \Apn\AmiClient\Protocol\GenericAction('Ping'));
        $p2 = $c2->send(new \Apn\AmiClient\Protocol\GenericAction('Ping'));
        
        $this->assertNotEquals($p1->getAction()->getActionId(), $p2->getAction()->getActionId());
        
        $r1Resolved = false;
        $p1->onComplete(function() use (&$r1Resolved) { $r1Resolved = true; });
        
        $r2Resolved = false;
        $p2->onComplete(function() use (&$r2Resolved) { $r2Resolved = true; });
        
        // Send response for 1 to node 2
        $t2_callback("Response: Success\r\nActionID: " . $p1->getAction()->getActionId() . "\r\n\r\n");
        $c2->processTick();
        
        $this->assertFalse($r1Resolved);
        $this->assertFalse($r2Resolved);
        
        // Send response for 1 to node 1
        $t1_callback("Response: Success\r\nActionID: " . $p1->getAction()->getActionId() . "\r\n\r\n");
        $c1->processTick();
        
        $this->assertTrue($r1Resolved);
        $this->assertFalse($r2Resolved);
    }
}
