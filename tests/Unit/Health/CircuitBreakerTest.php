<?php

declare(strict_types=1);

namespace Tests\Unit\Health;

use Apn\AmiClient\Health\CircuitBreaker;
use Apn\AmiClient\Health\CircuitState;
use PHPUnit\Framework\TestCase;

class CircuitBreakerTest extends TestCase
{
    public function testOpensAfterThresholdFailures(): void
    {
        $breaker = new CircuitBreaker(failureThreshold: 2, cooldownSeconds: 10.0);

        $breaker->recordFailure();
        $this->assertEquals(CircuitState::CLOSED, $breaker->getState());

        $breaker->recordFailure();
        $this->assertEquals(CircuitState::OPEN, $breaker->getState());
        $this->assertTrue($breaker->isOpen());
    }

    public function testCooldownTransitionsToHalfOpen(): void
    {
        $breaker = new CircuitBreaker(failureThreshold: 1, cooldownSeconds: 0.001);

        $breaker->recordFailure();
        $this->assertTrue($breaker->isOpen());

        usleep(2000);

        $this->assertFalse($breaker->isOpen());
        $this->assertEquals(CircuitState::HALF_OPEN, $breaker->getState());
    }

    public function testHalfOpenProbeLimitAndFailure(): void
    {
        $breaker = new CircuitBreaker(failureThreshold: 1, cooldownSeconds: 0.001, maxHalfOpenProbes: 1);

        $breaker->recordFailure();
        usleep(2000);
        $this->assertTrue($breaker->canAttempt());

        $breaker->recordAttempt();
        $this->assertFalse($breaker->canAttempt());

        $breaker->recordFailure();
        $this->assertEquals(CircuitState::OPEN, $breaker->getState());
    }

    public function testHalfOpenSuccessClosesCircuit(): void
    {
        $breaker = new CircuitBreaker(failureThreshold: 1, cooldownSeconds: 0.001, maxHalfOpenProbes: 1);

        $breaker->recordFailure();
        usleep(2000);
        $this->assertTrue($breaker->canAttempt());

        $breaker->recordAttempt();
        $breaker->recordSuccess();

        $this->assertEquals(CircuitState::CLOSED, $breaker->getState());
        $this->assertTrue($breaker->canAttempt());
    }
}
