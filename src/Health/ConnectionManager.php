<?php

declare(strict_types=1);

namespace Apn\AmiClient\Health;

use Apn\AmiClient\Core\Contracts\MetricsCollectorInterface;
use Apn\AmiClient\Core\NullMetricsCollector;

/**
 * Manages the connection state machine and reconnection logic.
 */
class ConnectionManager
{
    private HealthStatus $status = HealthStatus::DISCONNECTED;
    private int $reconnectAttempts = 0;
    private ?float $nextReconnectTime = null;
    private ?float $lastHeartbeatTime = null;
    private int $consecutiveHeartbeatFailures = 0;
    private MetricsCollectorInterface $metrics;
    /** @var array<string, string> */
    private array $labels;

    public function __construct(
        private readonly float $heartbeatInterval = 15.0,
        private readonly int $maxHeartbeatFailures = 3,
        private readonly float $minReconnectDelay = 0.1,
        private readonly float $maxReconnectDelay = 30.0,
        private readonly float $jitterFactor = 0.2,
        ?MetricsCollectorInterface $metrics = null,
        array $labels = []
    ) {
        $this->metrics = $metrics ?? new NullMetricsCollector();
        $this->labels = $labels;
    }

    public function getStatus(): HealthStatus
    {
        return $this->status;
    }

    public function setStatus(HealthStatus $status): void
    {
        if ($this->status !== $status) {
            $this->metrics->set('ami_connection_status', (float)$status->isAvailable(), $this->labels);
        }

        $this->status = $status;
        
        if ($status === HealthStatus::CONNECTED_HEALTHY) {
            $this->reconnectAttempts = 0;
            $this->nextReconnectTime = null;
            $this->consecutiveHeartbeatFailures = 0;
            $this->lastHeartbeatTime = microtime(true);
        }
    }

    public function recordHeartbeatSent(): void
    {
        $this->lastHeartbeatTime = microtime(true);
    }

    public function recordHeartbeatSuccess(): void
    {
        $this->consecutiveHeartbeatFailures = 0;
        $this->lastHeartbeatTime = microtime(true);
        
        if ($this->status === HealthStatus::CONNECTED_DEGRADED) {
            $this->setStatus(HealthStatus::CONNECTED_HEALTHY);
        }
    }

    public function recordHeartbeatFailure(): void
    {
        $this->consecutiveHeartbeatFailures++;
        
        if ($this->consecutiveHeartbeatFailures >= $this->maxHeartbeatFailures) {
            $this->setStatus(HealthStatus::DISCONNECTED);
        } elseif ($this->status === HealthStatus::CONNECTED_HEALTHY) {
            $this->setStatus(HealthStatus::CONNECTED_DEGRADED);
        }
    }

    public function recordLoginSuccess(): void
    {
        $this->setStatus(HealthStatus::CONNECTED_HEALTHY);
    }

    public function recordLoginFailure(): void
    {
        $this->setStatus(HealthStatus::DISCONNECTED);
    }

    public function shouldSendHeartbeat(): bool
    {
        if (!$this->status->isAvailable() && $this->status !== HealthStatus::CONNECTED_DEGRADED) {
            return false;
        }

        if ($this->lastHeartbeatTime === null) {
            return true;
        }

        return (microtime(true) - $this->lastHeartbeatTime) >= $this->heartbeatInterval;
    }

    public function shouldAttemptReconnect(): bool
    {
        if ($this->status !== HealthStatus::DISCONNECTED) {
            return false;
        }

        if ($this->nextReconnectTime === null) {
            return true;
        }

        return microtime(true) >= $this->nextReconnectTime;
    }

    public function recordReconnectAttempt(): void
    {
        $this->reconnectAttempts++;
        $this->status = HealthStatus::CONNECTING;
        
        $this->metrics->increment('ami_reconnection_totals', $this->labels);
        
        $delay = $this->calculateBackoff();
        $this->nextReconnectTime = microtime(true) + $delay;
    }

    private function calculateBackoff(): float
    {
        // Exponential backoff: minDelay * 2^attempts
        // But we want it to start small.
        // Attempt 1: minDelay * 1
        // Attempt 2: minDelay * 2
        // Attempt 3: minDelay * 4
        $delay = $this->minReconnectDelay * (2 ** (min($this->reconnectAttempts, 10) - 1));
        $delay = min($delay, $this->maxReconnectDelay);
        
        // Add jitter
        $jitter = $delay * $this->jitterFactor * (mt_rand() / mt_getrandmax());
        
        // Ensure at least minReconnectDelay (100ms as per guidelines)
        return max($this->minReconnectDelay, $delay + $jitter);
    }
    
    public function getReconnectAttempts(): int
    {
        return $this->reconnectAttempts;
    }
}
