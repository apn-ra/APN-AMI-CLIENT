<?php

declare(strict_types=1);

namespace Apn\AmiClient\Health;

use Apn\AmiClient\Core\Contracts\MetricsCollectorInterface;
use Apn\AmiClient\Core\NullMetricsCollector;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Manages the connection state machine and reconnection logic.
 */
class ConnectionManager
{
    private HealthStatus $status = HealthStatus::DISCONNECTED;
    private int $reconnectAttempts = 0;
    private ?float $nextReconnectTime = null;
    private ?float $lastHeartbeatTime = null;
    private ?float $authStartTime = null;
    private ?float $connectStartTime = null;
    private ?float $lastReadTime = null;
    private bool $loginActionSent = false;
    private int $consecutiveHeartbeatFailures = 0;
    private int $connectAttemptsThisTick = 0;
    private ?float $lastLoginFailureAt = null;
    private CircuitBreaker $circuitBreaker;
    private LoggerInterface $logger;
    private MetricsCollectorInterface $metrics;
    /** @var array<string, string> */
    private array $labels;

    public function __construct(
        private readonly float $heartbeatInterval = 15.0,
        private readonly int $maxHeartbeatFailures = 3,
        private readonly float $minReconnectDelay = 0.1,
        private readonly float $maxReconnectDelay = 30.0,
        private readonly float $jitterFactor = 0.2,
        private readonly int $maxConnectAttemptsPerTick = 5,
        private readonly float $connectTimeout = 10.0,
        private readonly float $readTimeout = 30.0,
        private readonly float $loginTimeout = 5.0,
        private readonly int $circuitFailureThreshold = 5,
        private readonly float $circuitCooldown = 30.0,
        private readonly int $circuitHalfOpenMaxProbes = 1,
        ?MetricsCollectorInterface $metrics = null,
        array $labels = [],
        ?LoggerInterface $logger = null
    ) {
        $this->metrics = $metrics ?? new NullMetricsCollector();
        $this->labels = $labels;
        $this->logger = $logger ?? new NullLogger();
        $this->circuitBreaker = new CircuitBreaker(
            failureThreshold: $this->circuitFailureThreshold,
            cooldownSeconds: $this->circuitCooldown,
            maxHalfOpenProbes: $this->circuitHalfOpenMaxProbes
        );
    }

    public function getStatus(): HealthStatus
    {
        return $this->status;
    }

    public function setStatus(HealthStatus $status): void
    {
        if ($this->status !== $status) {
            $this->metrics->set('ami_connection_status', (float)$status->isAvailable(), $this->labels);
            
            // Start auth/connect timer when entering CONNECTING/CONNECTED/AUTHENTICATING
            if ($status === HealthStatus::CONNECTING
                || $status === HealthStatus::CONNECTED
                || $status === HealthStatus::AUTHENTICATING) {
                if ($this->authStartTime === null) {
                    $this->authStartTime = microtime(true);
                }
            } elseif ($status !== HealthStatus::RECONNECTING) {
                // Keep authStartTime if we are just switching between CONNECTING/CONNECTED/AUTHENTICATING,
                // otherwise reset it unless we are in RECONNECTING (where we don't have a socket anyway)
                $this->authStartTime = null;
            }
        }

        $this->status = $status;

        if ($status === HealthStatus::CONNECTING) {
            if ($this->connectStartTime === null) {
                $this->connectStartTime = microtime(true);
            }
        } elseif ($status !== HealthStatus::RECONNECTING) {
            $this->connectStartTime = null;
        }

        if ($status === HealthStatus::CONNECTED
            || $status === HealthStatus::AUTHENTICATING
            || $status === HealthStatus::READY
            || $status === HealthStatus::READY_DEGRADED) {
            if ($this->lastReadTime === null) {
                $this->lastReadTime = microtime(true);
            }
        } elseif ($status === HealthStatus::DISCONNECTED) {
            $this->lastReadTime = null;
        }
        
        if ($status === HealthStatus::READY) {
            $this->reconnectAttempts = 0;
            $this->nextReconnectTime = null;
            $this->consecutiveHeartbeatFailures = 0;
            $this->lastHeartbeatTime = microtime(true);
            $this->authStartTime = null;
            $this->connectStartTime = null;
            $this->loginActionSent = false;
            $this->lastReadTime = microtime(true);
            $this->circuitBreaker->recordSuccess();
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
        
        if ($this->status === HealthStatus::READY_DEGRADED) {
            $this->setStatus(HealthStatus::READY);
        }
    }

    public function recordHeartbeatFailure(): void
    {
        $this->consecutiveHeartbeatFailures++;
        
        if ($this->consecutiveHeartbeatFailures >= $this->maxHeartbeatFailures) {
            // signal forced closure via shouldForceClose()
            $this->setStatus(HealthStatus::DISCONNECTED);
        } elseif ($this->status === HealthStatus::READY) {
            $this->setStatus(HealthStatus::READY_DEGRADED);
        }
    }

    /**
     * Returns true if the connection must be force-closed (e.g., heartbeat failure).
     */
    public function shouldForceClose(): bool
    {
        return $this->consecutiveHeartbeatFailures >= $this->maxHeartbeatFailures;
    }

    public function recordLoginAttempt(): void
    {
        $this->setStatus(HealthStatus::AUTHENTICATING);
        $this->loginActionSent = true;
        // authStartTime is already set when we entered CONNECTING or AUTHENTICATING
    }

    public function recordBannerReceived(): void
    {
        if ($this->status === HealthStatus::CONNECTING) {
            $this->setStatus(HealthStatus::CONNECTED);
        }
    }

    public function isLoginTimedOut(): bool
    {
        if (($this->status !== HealthStatus::AUTHENTICATING
            && $this->status !== HealthStatus::CONNECTING
            && $this->status !== HealthStatus::CONNECTED)
            || $this->authStartTime === null) {
            return false;
        }

        return (microtime(true) - $this->authStartTime) >= $this->loginTimeout;
    }

    public function isConnectTimedOut(): bool
    {
        if ($this->status !== HealthStatus::CONNECTING || $this->connectStartTime === null) {
            return false;
        }

        return (microtime(true) - $this->connectStartTime) >= $this->connectTimeout;
    }

    public function isReadTimedOut(): bool
    {
        if ($this->readTimeout <= 0.0 || $this->lastReadTime === null) {
            return false;
        }

        if ($this->status === HealthStatus::CONNECTED
            || $this->status === HealthStatus::AUTHENTICATING
            || $this->status === HealthStatus::READY
            || $this->status === HealthStatus::READY_DEGRADED) {
            return (microtime(true) - $this->lastReadTime) >= $this->readTimeout;
        }

        return false;
    }

    public function recordRead(): void
    {
        $this->lastReadTime = microtime(true);
    }

    public function isLoginInProgress(): bool
    {
        return $this->status === HealthStatus::AUTHENTICATING;
    }

    public function hasLoginStarted(): bool
    {
        // This now means: has the login action been sent?
        // We need a separate flag for this since authStartTime covers banner wait too.
        return $this->loginActionSent;
    }

    public function recordLoginSuccess(): void
    {
        $this->authStartTime = null;
        $this->loginActionSent = false;
        $this->recordCircuitSuccess('login_success');
        $this->setStatus(HealthStatus::READY);
    }

    public function recordLoginFailure(): void
    {
        $this->authStartTime = null;
        $this->loginActionSent = false;
        $this->setStatus(HealthStatus::DISCONNECTED);
        $this->recordCircuitFailure('login_failure');
        
        // Treat login failure as a failed attempt to trigger backoff
        $this->reconnectAttempts++;
        $delay = $this->calculateBackoff();
        $this->nextReconnectTime = microtime(true) + $delay;
        $this->lastLoginFailureAt = microtime(true);
        
        $this->metrics->increment('ami_login_failure_totals', $this->labels);
    }

    public function recordConnectTimeout(): void
    {
        $this->setStatus(HealthStatus::DISCONNECTED);
        $this->recordCircuitFailure('connect_timeout');
        $this->reconnectAttempts++;
        $delay = $this->calculateBackoff();
        $this->nextReconnectTime = microtime(true) + $delay;
        $this->metrics->increment('ami_connect_timeout_totals', $this->labels);
    }

    public function recordConnectFailure(): void
    {
        $this->setStatus(HealthStatus::DISCONNECTED);
        $this->recordCircuitFailure('connect_failure');
        $this->reconnectAttempts++;
        $delay = $this->calculateBackoff();
        $this->nextReconnectTime = microtime(true) + $delay;
    }

    public function recordReadTimeout(): void
    {
        $this->setStatus(HealthStatus::DISCONNECTED);
        $this->reconnectAttempts++;
        $delay = $this->calculateBackoff();
        $this->nextReconnectTime = microtime(true) + $delay;
        $this->metrics->increment('ami_read_timeout_totals', $this->labels);
    }

    /**
     * Returns true once right after a login failure is recorded, then resets the flag.
     */
    public function consumeLoginFailureSignal(): bool
    {
        if ($this->lastLoginFailureAt === null) {
            return false;
        }
        $this->lastLoginFailureAt = null;
        return true;
    }

    public function shouldSendHeartbeat(): bool
    {
        if (!$this->status->isAvailable() && $this->status !== HealthStatus::READY_DEGRADED) {
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

        $before = $this->circuitBreaker->getState();
        $canAttempt = $this->circuitBreaker->canAttempt();
        $this->logCircuitTransition($before, $this->circuitBreaker->getState(), 'cooldown_elapsed');
        if (!$canAttempt) {
            return false;
        }

        if ($this->connectAttemptsThisTick >= $this->maxConnectAttemptsPerTick) {
            return false;
        }

        if ($this->nextReconnectTime === null) {
            return true;
        }

        return microtime(true) >= $this->nextReconnectTime;
    }

    public function recordReconnectAttempt(): void
    {
        $this->connectAttemptsThisTick++;
        $this->reconnectAttempts++;
        $this->setStatus(HealthStatus::CONNECTING);
        $this->circuitBreaker->recordAttempt();
        
        $this->metrics->increment('ami_reconnection_totals', $this->labels);
        
        $delay = $this->calculateBackoff();
        $this->nextReconnectTime = microtime(true) + $delay;
    }

    public function previewReconnectDelay(): float
    {
        return $this->calculateBackoff();
    }

    public function previewReconnectAt(float $delay): float
    {
        return microtime(true) + $delay;
    }

    public function resetTickBudgets(): void
    {
        $this->connectAttemptsThisTick = 0;
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

    public function getConsecutiveHeartbeatFailures(): int
    {
        return $this->consecutiveHeartbeatFailures;
    }

    public function getCircuitState(): CircuitState
    {
        return $this->circuitBreaker->getState();
    }

    public function getCircuitFailures(): int
    {
        return $this->circuitBreaker->getConsecutiveFailures();
    }

    public function getCircuitHalfOpenProbeCount(): int
    {
        return $this->circuitBreaker->getHalfOpenProbeCount();
    }

    public function getCircuitMaxHalfOpenProbes(): int
    {
        return $this->circuitBreaker->getMaxHalfOpenProbes();
    }

    private function recordCircuitFailure(string $reason): void
    {
        $before = $this->circuitBreaker->getState();
        $this->circuitBreaker->recordFailure();
        $after = $this->circuitBreaker->getState();
        if ($before === CircuitState::HALF_OPEN && $after === CircuitState::OPEN) {
            $finalReason = 'probe_failed';
        } elseif ($before !== CircuitState::OPEN && $after === CircuitState::OPEN) {
            $finalReason = 'failure_threshold';
        } else {
            $finalReason = $reason;
        }
        $this->logCircuitTransition($before, $after, $finalReason);
    }

    private function recordCircuitSuccess(string $reason): void
    {
        $before = $this->circuitBreaker->getState();
        $this->circuitBreaker->recordSuccess();
        $after = $this->circuitBreaker->getState();
        $finalReason = ($before === CircuitState::HALF_OPEN && $after === CircuitState::CLOSED)
            ? 'probe_success'
            : $reason;
        $this->logCircuitTransition($before, $after, $finalReason);
    }

    private function logCircuitTransition(CircuitState $from, CircuitState $to, string $reason): void
    {
        if ($from === $to) {
            return;
        }

        $context = [
            'server_key' => $this->labels['server_key'] ?? 'unknown',
            'host' => $this->labels['server_host'] ?? 'unknown',
            'reason' => $reason,
            'from_state' => $from->value,
            'to_state' => $to->value,
            'consecutive_failures' => $this->circuitBreaker->getConsecutiveFailures(),
            'probe_count' => $this->circuitBreaker->getHalfOpenProbeCount(),
            'failure_threshold' => $this->circuitFailureThreshold,
            'max_half_open_probes' => $this->circuitHalfOpenMaxProbes,
            'cooldown_seconds' => $this->circuitCooldown,
        ];

        $this->logger->warning('Circuit breaker transition', $context);
    }
}
