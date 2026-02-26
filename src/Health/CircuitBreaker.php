<?php

declare(strict_types=1);

namespace Apn\AmiClient\Health;

/**
 * Per-node circuit breaker for connect/auth failures.
 */
class CircuitBreaker
{
    private CircuitState $state = CircuitState::CLOSED;
    private int $consecutiveFailures = 0;
    private ?float $openedAt = null;
    private int $halfOpenProbeCount = 0;

    public function __construct(
        private readonly int $failureThreshold = 5,
        private readonly float $cooldownSeconds = 30.0,
        private readonly int $maxHalfOpenProbes = 1
    ) {
    }

    public function getState(): CircuitState
    {
        return $this->state;
    }

    public function getConsecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }

    public function getOpenedAt(): ?float
    {
        return $this->openedAt;
    }

    public function getHalfOpenProbeCount(): int
    {
        return $this->halfOpenProbeCount;
    }

    public function getMaxHalfOpenProbes(): int
    {
        return $this->maxHalfOpenProbes;
    }

    public function isOpen(): bool
    {
        if ($this->state !== CircuitState::OPEN) {
            return false;
        }

        if ($this->openedAt === null) {
            return true;
        }

        if ((microtime(true) - $this->openedAt) >= $this->cooldownSeconds) {
            $this->state = CircuitState::HALF_OPEN;
            $this->halfOpenProbeCount = 0;
            return false;
        }

        return true;
    }

    public function canAttempt(): bool
    {
        if ($this->isOpen()) {
            return false;
        }

        if ($this->state === CircuitState::HALF_OPEN) {
            return $this->halfOpenProbeCount < $this->maxHalfOpenProbes;
        }

        return true;
    }

    public function recordAttempt(): void
    {
        if ($this->state === CircuitState::HALF_OPEN) {
            $this->halfOpenProbeCount++;
        }
    }

    public function recordFailure(): void
    {
        if ($this->state === CircuitState::OPEN) {
            return;
        }

        $this->consecutiveFailures++;

        if ($this->state === CircuitState::HALF_OPEN) {
            $this->state = CircuitState::OPEN;
            $this->openedAt = microtime(true);
            $this->halfOpenProbeCount = 0;
            return;
        }

        if ($this->consecutiveFailures >= $this->failureThreshold) {
            $this->state = CircuitState::OPEN;
            $this->openedAt = microtime(true);
            $this->halfOpenProbeCount = 0;
        }
    }

    public function recordSuccess(): void
    {
        $this->consecutiveFailures = 0;
        $this->openedAt = null;
        $this->state = CircuitState::CLOSED;
        $this->halfOpenProbeCount = 0;
    }
}
