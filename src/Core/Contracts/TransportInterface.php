<?php

declare(strict_types=1);

namespace Apn\AmiClient\Core\Contracts;

/**
 * Low-level transport interface for AMI communication.
 * Handles physical connection and non-blocking I/O.
 */
interface TransportInterface
{
    public const MIN_TICK_TIMEOUT_MS = 0;
    public const MAX_TICK_TIMEOUT_MS = 250;

    /**
     * Establish the connection.
     *
     * @throws \Apn\AmiClient\Exceptions\ConnectionException
     */
    public function open(): void;

    /**
     * Close the connection.
     *
     * @param bool $graceful When false, pending outbound bytes are purged to prevent cross-session replay.
     */
    public function close(bool $graceful = true): void;

    /**
     * Queue raw data for transmission.
     *
     * @throws \Apn\AmiClient\Exceptions\BackpressureException If outbound buffer limit is exceeded.
     */
    public function send(string $data): void;

    /**
     * Perform I/O multiplexing (Read/Write).
     *
     * @param int $timeoutMs Maximum selector wait in milliseconds. Valid range:
     *                       0..MAX_TICK_TIMEOUT_MS. Negative values are rejected.
     *                       Values above MAX_TICK_TIMEOUT_MS are clamped.
     */
    public function tick(int $timeoutMs = 0): void;

    /**
     * Register a callback for incoming raw data.
     *
     * @param callable(string): void $callback
     */
    public function onData(callable $callback): void;

    /**
     * Check if the transport is currently connected.
     */
    public function isConnected(): bool;

    /**
     * Returns the number of pending bytes queued for write.
     */
    public function getPendingWriteBytes(): int;

    /**
     * Clean up resources and close connections.
     */
    public function terminate(): void;
}
