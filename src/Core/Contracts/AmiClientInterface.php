<?php

declare(strict_types=1);

namespace Apn\AmiClient\Core\Contracts;

use Apn\AmiClient\Protocol\Action;
use Apn\AmiClient\Correlation\PendingAction;
use Apn\AmiClient\Health\HealthStatus;

/**
 * High-level AMI Client interface.
 */
interface AmiClientInterface
{
    /**
     * Establish the connection.
     */
    public function open(): void;

    /**
     * Close the connection.
     */
    public function close(): void;

    /**
     * Send an action to Asterisk.
     *
     * @param Action $action
     * @return PendingAction
     */
    public function send(Action $action): PendingAction;

    /**
     * Register a listener for specific AMI events.
     *
     * @param string $name
     * @param callable(\Apn\AmiClient\Events\AmiEvent): void $listener
     */
    public function onEvent(string $name, callable $listener): void;

    /**
     * Register a listener for all AMI events.
     *
     * @param callable(\Apn\AmiClient\Events\AmiEvent): void $listener
     */
    public function onAnyEvent(callable $listener): void;

    /**
     * Perform one tick of processing (I/O, parsing, timeouts).
     *
     * @param int $timeoutMs
     */
    public function tick(int $timeoutMs = 0): void;
    
    /**
     * Alias for tick(0). Explicitly non-blocking poll for I/O and protocol events.
     * Recommended for production event loops (Guideline 2).
     */
    public function poll(): void;

    /**
     * Performs internal processing (timeouts, health) without I/O multiplexing.
     * This is used when an external reactor handles the stream_select call.
     *
     * @param bool $canAttemptConnect Whether this tick allows new connection attempts (Phase 4).
     * @return bool Whether a connection attempt was made.
     */
    public function processTick(bool $canAttemptConnect = true): bool;

    /**
     * Check if the client is connected.
     */
    public function isConnected(): bool;

    /**
     * Returns the server key associated with this client.
     */
    public function getServerKey(): string;

    /**
     * Returns the current health status of the connection.
     */
    public function getHealthStatus(): HealthStatus;

    /**
     * Returns a summary of the client health and metrics.
     *
     * @return array{
     *     server_key: string,
     *     status: string,
     *     connected: bool,
     *     memory_usage_bytes: int,
     *     pending_actions: int,
     *     dropped_events: int
     * }
     */
    public function health(): array;
}
