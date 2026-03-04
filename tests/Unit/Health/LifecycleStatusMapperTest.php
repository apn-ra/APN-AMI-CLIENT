<?php

declare(strict_types=1);

namespace Tests\Unit\Health;

use Apn\AmiClient\Health\HealthStatus;
use Apn\AmiClient\Health\LifecycleStatusMapper;
use PHPUnit\Framework\TestCase;

final class LifecycleStatusMapperTest extends TestCase
{
    public function test_ready_status_maps_to_connected_healthy_alias(): void
    {
        $this->assertSame('connected_healthy', LifecycleStatusMapper::toOperationalAlias(HealthStatus::READY));
    }

    public function test_ready_degraded_status_maps_to_connected_degraded_alias(): void
    {
        $this->assertSame('connected_degraded', LifecycleStatusMapper::toOperationalAlias(HealthStatus::READY_DEGRADED));
    }

    public function test_non_ready_statuses_keep_existing_value_for_compatibility(): void
    {
        $this->assertSame('disconnected', LifecycleStatusMapper::toOperationalAlias(HealthStatus::DISCONNECTED));
        $this->assertSame('authenticating', LifecycleStatusMapper::toOperationalAlias(HealthStatus::AUTHENTICATING));
    }
}

