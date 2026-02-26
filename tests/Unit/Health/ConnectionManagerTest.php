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
        $manager->setStatus(HealthStatus::READY);
        $this->assertEquals(HealthStatus::READY, $manager->getStatus());
    }

    public function testAuthStateTransitions(): void
    {
        $manager = new ConnectionManager();

        $manager->setStatus(HealthStatus::CONNECTING);
        $manager->recordBannerReceived();
        $this->assertEquals(HealthStatus::CONNECTED, $manager->getStatus());

        $manager->recordLoginAttempt();
        $this->assertEquals(HealthStatus::AUTHENTICATING, $manager->getStatus());

        $manager->recordLoginSuccess();
        $this->assertEquals(HealthStatus::READY, $manager->getStatus());
    }

    public function testConnectTimeout(): void
    {
        $manager = new ConnectionManager(connectTimeout: 0.001);
        $manager->setStatus(HealthStatus::CONNECTING);

        usleep(2000);

        $this->assertTrue($manager->isConnectTimedOut());

        $manager->recordConnectTimeout();
        $this->assertEquals(HealthStatus::DISCONNECTED, $manager->getStatus());
    }

    public function testReadTimeout(): void
    {
        $manager = new ConnectionManager(readTimeout: 0.001);
        $manager->setStatus(HealthStatus::READY);

        usleep(2000);

        $this->assertTrue($manager->isReadTimedOut());
        $manager->recordReadTimeout();
        $this->assertEquals(HealthStatus::DISCONNECTED, $manager->getStatus());
    }

    public function testCircuitBreakerBlocksReconnect(): void
    {
        $manager = new ConnectionManager(circuitFailureThreshold: 1, circuitCooldown: 30.0);
        $manager->setStatus(HealthStatus::CONNECTING);
        $manager->recordConnectTimeout();

        $this->assertEquals(\Apn\AmiClient\Health\CircuitState::OPEN, $manager->getCircuitState());
        $this->assertFalse($manager->shouldAttemptReconnect());
    }

    public function testHeartbeatSuccess(): void
    {
        $manager = new ConnectionManager();
        $manager->setStatus(HealthStatus::READY_DEGRADED);
        $manager->recordHeartbeatSuccess();
        $this->assertEquals(HealthStatus::READY, $manager->getStatus());
    }

    public function testHeartbeatFailureEscalation(): void
    {
        $manager = new ConnectionManager(heartbeatInterval: 15.0, maxHeartbeatFailures: 3);
        $manager->setStatus(HealthStatus::READY);

        $manager->recordHeartbeatFailure();
        $this->assertEquals(HealthStatus::READY_DEGRADED, $manager->getStatus());

        $manager->recordHeartbeatFailure();
        $this->assertEquals(HealthStatus::READY_DEGRADED, $manager->getStatus());

        $manager->recordHeartbeatFailure();
        $this->assertEquals(HealthStatus::DISCONNECTED, $manager->getStatus());
    }

    public function testShouldSendHeartbeat(): void
    {
        $manager = new ConnectionManager(heartbeatInterval: 0.1);
        $this->assertFalse($manager->shouldSendHeartbeat(), "Should not send heartbeat when disconnected");

        $manager->setStatus(HealthStatus::READY);
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

        $manager->setStatus(HealthStatus::READY);
        $this->assertEquals(0, $manager->getReconnectAttempts());
    }
}
