<?php

declare(strict_types=1);

namespace Tests\Unit\Transport;

use Apn\AmiClient\Core\Contracts\MetricsCollectorInterface;
use Apn\AmiClient\Core\Contracts\TransportInterface;
use Apn\AmiClient\Transport\Reactor;
use Apn\AmiClient\Transport\TcpTransport;
use PHPUnit\Framework\TestCase;

final class TimeoutContractTest extends TestCase
{
    public function testReactorClampsTimeoutAndEmitsMetric(): void
    {
        $increments = [];
        $metrics = new class ($increments) implements MetricsCollectorInterface {
            public function __construct(private array &$increments)
            {
            }

            public function increment(string $metric, array $labels = [], int $value = 1): void
            {
                $this->increments[] = [$metric, $labels, $value];
            }

            public function record(string $name, float $value, array $labels = []): void
            {
            }

            public function set(string $name, float $value, array $labels = []): void
            {
            }
        };

        $reactor = new class ($metrics) extends Reactor {
            public function __construct(MetricsCollectorInterface $metrics)
            {
                parent::__construct(metrics: $metrics);
            }

            public function exposeNormalize(int $timeoutMs): int
            {
                return $this->normalizeTimeoutMs($timeoutMs);
            }
        };

        $max = TransportInterface::MAX_TICK_TIMEOUT_MS;
        $this->assertSame($max, $reactor->exposeNormalize($max + 10));
        $this->assertSame($max, $reactor->exposeNormalize($max));

        $this->assertSame(1, count($increments));
        $this->assertSame('ami_runtime_timeout_clamped_total', $increments[0][0]);
        $this->assertSame('reactor', $increments[0][1]['component'] ?? null);
        $this->assertSame('above_max', $increments[0][1]['reason'] ?? null);
    }

    public function testReactorRejectsNegativeTimeout(): void
    {
        $reactor = new class extends Reactor {
            public function exposeNormalize(int $timeoutMs): int
            {
                return $this->normalizeTimeoutMs($timeoutMs);
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        $reactor->exposeNormalize(-1);
    }

    public function testTransportClampsTimeoutAndEmitsMetric(): void
    {
        $increments = [];
        $metrics = new class ($increments) implements MetricsCollectorInterface {
            public function __construct(private array &$increments)
            {
            }

            public function increment(string $metric, array $labels = [], int $value = 1): void
            {
                $this->increments[] = [$metric, $labels, $value];
            }

            public function record(string $name, float $value, array $labels = []): void
            {
            }

            public function set(string $name, float $value, array $labels = []): void
            {
            }
        };

        $transport = new class ('127.0.0.1', 5038, metrics: $metrics) extends TcpTransport {
            public function exposeNormalize(int $timeoutMs): int
            {
                return $this->normalizeTimeoutMs($timeoutMs);
            }
        };

        $max = TransportInterface::MAX_TICK_TIMEOUT_MS;
        $this->assertSame($max, $transport->exposeNormalize($max + 5));
        $this->assertSame($max, $transport->exposeNormalize($max));

        $this->assertSame(1, count($increments));
        $this->assertSame('ami_runtime_timeout_clamped_total', $increments[0][0]);
        $this->assertSame('transport', $increments[0][1]['component'] ?? null);
        $this->assertSame('above_max', $increments[0][1]['reason'] ?? null);
    }

    public function testTransportRejectsNegativeTimeout(): void
    {
        $transport = new class ('127.0.0.1', 5038) extends TcpTransport {
            public function exposeNormalize(int $timeoutMs): int
            {
                return $this->normalizeTimeoutMs($timeoutMs);
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        $transport->exposeNormalize(-10);
    }
}
