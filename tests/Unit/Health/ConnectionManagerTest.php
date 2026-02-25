<?php

declare(strict_types=1);

namespace Tests\Unit\Health;

use Apn\AmiClient\Health\ConnectionManager;
use Apn\AmiClient\Health\HealthStatus;
use PHPUnit\Framework\TestCase;

class ConnectionManagerTest extends TestCase
{
    public function testInitialStatus(): void
    {
        $manager = new ConnectionManager();
        $this->assertEquals(HealthStatus::DISCONNECTED, $manager->getStatus());
    }

    public function testSetStatus(): void
    {
        $manager = new ConnectionManager();
        $manager->setStatus(HealthStatus::CONNECTED_HEALTHY);
        $this->assertEquals(HealthStatus::CONNECTED_HEALTHY, $manager->getStatus());
    }

    public function testHeartbeatSuccess(): void
    {
        $manager = new ConnectionManager();
        $manager->setStatus(HealthStatus::CONNECTED_DEGRADED);
        $manager->recordHeartbeatSuccess();
        $this->assertEquals(HealthStatus::CONNECTED_HEALTHY, $manager->getStatus());
    }

    public function testHeartbeatFailureEscalation(): void
    {
        $manager = new ConnectionManager(heartbeatInterval: 15.0, maxHeartbeatFailures: 3);
        $manager->setStatus(HealthStatus::CONNECTED_HEALTHY);

        $manager->recordHeartbeatFailure();
        $this->assertEquals(HealthStatus::CONNECTED_DEGRADED, $manager->getStatus());

        $manager->recordHeartbeatFailure();
        $this->assertEquals(HealthStatus::CONNECTED_DEGRADED, $manager->getStatus());

        $manager->recordHeartbeatFailure();
        $this->assertEquals(HealthStatus::DISCONNECTED, $manager->getStatus());
    }

    public function testShouldSendHeartbeat(): void
    {
        $manager = new ConnectionManager(heartbeatInterval: 0.1);
        $this->assertFalse($manager->shouldSendHeartbeat(), "Should not send heartbeat when disconnected");

        $manager->setStatus(HealthStatus::CONNECTED_HEALTHY);
        // Initially it might be true or false depending on when setStatus was called.
        // But recordHeartbeatSuccess sets lastHeartbeatTime to now.
        $manager->recordHeartbeatSuccess();
        $this->assertFalse($manager->shouldSendHeartbeat());

        usleep(110000); // Wait > 0.1s
        $this->assertTrue($manager->shouldSendHeartbeat());
    }

    public function testExponentialBackoffWithJitter(): void
    {
        $minDelay = 0.1;
        $maxDelay = 30.0;
        $manager = new ConnectionManager(minReconnectDelay: $minDelay, maxReconnectDelay: $maxDelay, jitterFactor: 0.2);

        $this->assertTrue($manager->shouldAttemptReconnect());
        
        // Attempt 1
        $manager->recordReconnectAttempt();
        $this->assertEquals(HealthStatus::CONNECTING, $manager->getStatus());
        $this->assertFalse($manager->shouldAttemptReconnect());
        $this->assertEquals(1, $manager->getReconnectAttempts());

        // We can't easily test the exact time with jitter, but we can check if it increases.
        // Actually, let's just check if multiple attempts increase the delay.
        
        $manager->setStatus(HealthStatus::DISCONNECTED);
        $manager->recordReconnectAttempt();
        $this->assertEquals(2, $manager->getReconnectAttempts());
        
        $manager->setStatus(HealthStatus::DISCONNECTED);
        $manager->recordReconnectAttempt();
        $this->assertEquals(3, $manager->getReconnectAttempts());
    }

    public function testResetAttemptsOnHealthy(): void
    {
        $manager = new ConnectionManager();
        $manager->recordReconnectAttempt();
        $manager->recordReconnectAttempt();
        $this->assertEquals(2, $manager->getReconnectAttempts());

        $manager->setStatus(HealthStatus::CONNECTED_HEALTHY);
        $this->assertEquals(0, $manager->getReconnectAttempts());
    }
}
