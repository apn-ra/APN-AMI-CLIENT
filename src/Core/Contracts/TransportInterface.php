<?php

declare(strict_types=1);

namespace Apn\AmiClient\Core\Contracts;

/**
 * Low-level transport interface for AMI communication.
 * Handles physical connection and non-blocking I/O.
 */
interface TransportInterface
{
    /**
     * Establish the connection.
     *
     * @throws \Apn\AmiClient\Exceptions\ConnectionException
     */
    public function open(): void;

    /**
     * Close the connection gracefully.
     */
    public function close(): void;

    /**
     * Queue raw data for transmission.
     *
     * @throws \Apn\AmiClient\Exceptions\BackpressureException If outbound buffer limit is exceeded.
     */
    public function send(string $data): void;

    /**
     * Perform I/O multiplexing (Read/Write).
     *
     * @param int $timeoutMs Timeout for stream_select in milliseconds.
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
     * Clean up resources and close connections.
     */
    public function terminate(): void;
}
