<?php

declare(strict_types=1);

namespace Apn\AmiClient\Core;

use Apn\AmiClient\Core\Contracts\MetricsCollectorInterface;

/**
 * A metrics collector that does nothing.
 */
class NullMetricsCollector implements MetricsCollectorInterface
{
    public function increment(string $name, array $labels = [], int $amount = 1): void
    {
        // Do nothing
    }

    public function record(string $name, float $value, array $labels = []): void
    {
        // Do nothing
    }

    public function set(string $name, float $value, array $labels = []): void
    {
        // Do nothing
    }
}
