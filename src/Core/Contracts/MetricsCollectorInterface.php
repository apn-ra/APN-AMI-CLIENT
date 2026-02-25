<?php

declare(strict_types=1);

namespace Apn\AmiClient\Core\Contracts;

/**
 * Interface for Prometheus-compatible metric collection.
 */
interface MetricsCollectorInterface
{
    /**
     * Increments a counter by a given amount.
     *
     * @param string $name Metric name (e.g., ami_dropped_events_total)
     * @param array<string, string> $labels Key-value pairs for labeling
     * @param int $amount
     */
    public function increment(string $name, array $labels = [], int $amount = 1): void;

    /**
     * Records a value for a histogram/summary.
     *
     * @param string $name Metric name (e.g., ami_action_latency_ms)
     * @param float $value
     * @param array<string, string> $labels Key-value pairs for labeling
     */
    public function record(string $name, float $value, array $labels = []): void;

    /**
     * Sets a gauge value.
     *
     * @param string $name Metric name (e.g., ami_connection_status)
     * @param float $value
     * @param array<string, string> $labels Key-value pairs for labeling
     */
    public function set(string $name, float $value, array $labels = []): void;
}
