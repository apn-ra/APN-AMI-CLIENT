<?php

declare(strict_types=1);

namespace Apn\AmiClient\Health;

final class LifecycleStatusMapper
{
    public static function toOperationalAlias(HealthStatus $status): string
    {
        return match ($status) {
            HealthStatus::READY => 'connected_healthy',
            HealthStatus::READY_DEGRADED => 'connected_degraded',
            default => $status->value,
        };
    }
}

