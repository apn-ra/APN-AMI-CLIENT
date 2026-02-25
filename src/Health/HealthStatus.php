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
    case AUTHENTICATING = 'authenticating';
    case CONNECTED_HEALTHY = 'connected_healthy';
    case CONNECTED_DEGRADED = 'connected_degraded';
    case RECONNECTING = 'reconnecting';

    /**
     * Whether the connection is available for sending actions.
     */
    public function isAvailable(): bool
    {
        return $this === self::CONNECTED_HEALTHY;
    }
}
