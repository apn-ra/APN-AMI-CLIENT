<?php

declare(strict_types=1);

namespace Apn\AmiClient\Health;

/**
 * Represents the health status of an AMI connection.
 */
enum HealthStatus: string
{
    case DISCONNECTED = 'disconnected';
    case CONNECTING = 'connecting';
    case CONNECTED = 'connected';
    case AUTHENTICATING = 'authenticating';
    case READY = 'ready';
    case READY_DEGRADED = 'ready_degraded';
    case RECONNECTING = 'reconnecting';

    /**
     * Whether the connection is available for sending actions.
     */
    public function isAvailable(): bool
    {
        return $this === self::READY;
    }
}
