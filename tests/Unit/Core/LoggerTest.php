<?php

declare(strict_types=1);

namespace Apn\AmiClient\Tests\Unit\Core;

use Apn\AmiClient\Core\Logger;
use Apn\AmiClient\Core\Contracts\MetricsCollectorInterface;
use Apn\AmiClient\Core\SecretRedactor;
use PHPUnit\Framework\TestCase;

class LoggerTest extends TestCase
{
    public function testLogsInJsonFormatWithMandatoryFields(): void
    {
        $output = [];
        $logger = new Logger(sinkWriter: function (string $line) use (&$output): int {
            $output[] = $line;
            return strlen($line);
        });

        $logger->log('info', 'test message', ['server_key' => 'srv1', 'action_id' => '123']);
        $decoded = json_decode($output[0] ?? '', true);
        $this->assertIsArray($decoded);
        $this->assertEquals('INFO', $decoded['level']);
        $this->assertEquals('test message', $decoded['message']);
        $this->assertEquals('srv1', $decoded['server_key']);
        $this->assertEquals('123', $decoded['action_id']);
        $this->assertNull($decoded['queue_depth']);
        $this->assertArrayHasKey('worker_pid', $decoded);
        $this->assertArrayHasKey('timestamp_ms', $decoded);
    }

    public function testWithServerKeySetsDefault(): void
    {
        $output = [];
        $logger = (new Logger(sinkWriter: function (string $line) use (&$output): int {
            $output[] = $line;
            return strlen($line);
        }))->withServerKey('srv_default');

        $logger->info('test message');
        $decoded = json_decode($output[0] ?? '', true);
        $this->assertEquals('srv_default', $decoded['server_key']);
    }

    public function testRedactionInLogger(): void
    {
        $output = [];
        $logger = new Logger(
            redactor: new SecretRedactor(),
            sinkWriter: function (string $line) use (&$output): int {
                $output[] = $line;
                return strlen($line);
            }
        );

        $logger->info('test message', ['secret' => 'password123']);
        $decoded = json_decode($output[0] ?? '', true);
        $this->assertEquals('********', $decoded['secret']);
    }

    public function testMandatoryFieldsArePresentEvenIfNotProvided(): void
    {
        $output = [];
        $logger = new Logger(sinkWriter: function (string $line) use (&$output): int {
            $output[] = $line;
            return strlen($line);
        });

        $logger->info('test message');
        $decoded = json_decode($output[0] ?? '', true);
        $this->assertEquals('unknown', $decoded['server_key']);
        $this->assertNull($decoded['action_id']);
        $this->assertNull($decoded['queue_depth']);
    }

    public function testQueueDepthIsPreservedWhenProvided(): void
    {
        $output = [];
        $logger = new Logger(sinkWriter: function (string $line) use (&$output): int {
            $output[] = $line;
            return strlen($line);
        });

        $logger->warning('queue-related', ['queue_depth' => 42]);
        $decoded = json_decode($output[0] ?? '', true);
        $this->assertSame(42, $decoded['queue_depth']);
    }

    public function testFallbackOutputWhenJsonEncodingFails(): void
    {
        $output = [];
        $logger = new Logger(sinkWriter: function (string $line) use (&$output): int {
            $output[] = $line;
            return strlen($line);
        });
        $invalidUtf8 = "bad \xC3\x28";

        $logger->info('fallback-test', ['server_key' => 'srv1', 'bad' => $invalidUtf8]);
        $line = $output[0] ?? '';

        $this->assertIsString($line);
        $this->assertStringContainsString('LOG_FALLBACK', $line);
        $this->assertStringContainsString('level=INFO', $line);
        $this->assertStringContainsString('server_key=srv1', $line);
        $this->assertStringContainsString('message=fallback-test', $line);
    }

    public function testSinkBackpressureDropsAndIncrementsMetricWithoutThrowing(): void
    {
        $increments = [];
        $metrics = new class ($increments) implements MetricsCollectorInterface {
            public function __construct(private array &$increments)
            {
            }

            public function increment(string $name, array $labels = [], int $amount = 1): void
            {
                $this->increments[] = ['name' => $name, 'labels' => $labels, 'amount' => $amount];
            }

            public function record(string $name, float $value, array $labels = []): void
            {
            }

            public function set(string $name, float $value, array $labels = []): void
            {
            }
        };

        $logger = new Logger(
            metrics: $metrics,
            sinkCapacity: 1,
            sinkWriter: static fn (string $line): int => 0
        );

        $logger->warning('first', ['server_key' => 'srv1']);
        $logger->warning('second', ['server_key' => 'srv1']);

        $this->assertNotEmpty($increments);
        $this->assertSame('ami_log_sink_dropped_total', $increments[0]['name']);
        $this->assertSame('capacity_exceeded', $increments[0]['labels']['reason']);
        $this->assertSame('srv1', $increments[0]['labels']['server_key']);
    }

    public function testSinkDropEmitsThrottledWarningWithRequiredFields(): void
    {
        $lines = [];
        $increments = [];
        $metrics = new class ($increments) implements MetricsCollectorInterface {
            public function __construct(private array &$increments)
            {
            }

            public function increment(string $name, array $labels = [], int $amount = 1): void
            {
                $this->increments[] = ['name' => $name, 'labels' => $labels, 'amount' => $amount];
            }

            public function record(string $name, float $value, array $labels = []): void
            {
            }

            public function set(string $name, float $value, array $labels = []): void
            {
            }
        };

        $calls = 0;
        $logger = new Logger(
            metrics: $metrics,
            sinkCapacity: 1,
            sinkWriter: function (string $line) use (&$lines, &$calls): int {
                $calls++;
                if ($calls === 1) {
                    return 0;
                }
                $lines[] = $line;
                return strlen($line);
            }
        );

        $logger->warning('first', ['server_key' => 'srv1']);
        $logger->warning('second', ['server_key' => 'srv1']);
        $logger->warning('third', ['server_key' => 'srv1']);

        $this->assertNotEmpty($increments);
        $this->assertCount(1, $lines);
        $decoded = json_decode($lines[0] ?? '', true);
        $this->assertSame('WARNING', $decoded['level']);
        $this->assertSame('Log sink drop', $decoded['message']);
        $this->assertSame('srv1', $decoded['server_key']);
        $this->assertSame('capacity_exceeded', $decoded['reason']);
        $this->assertSame('log_sink', $decoded['queue_type']);
        $this->assertSame(1, $decoded['queue_depth']);
    }

    public function testSinkExceptionIncrementsMetricWithoutThrowing(): void
    {
        $increments = [];
        $metrics = new class ($increments) implements MetricsCollectorInterface {
            public function __construct(private array &$increments)
            {
            }

            public function increment(string $name, array $labels = [], int $amount = 1): void
            {
                $this->increments[] = ['name' => $name, 'labels' => $labels, 'amount' => $amount];
            }

            public function record(string $name, float $value, array $labels = []): void
            {
            }

            public function set(string $name, float $value, array $labels = []): void
            {
            }
        };

        $logger = new Logger(
            metrics: $metrics,
            sinkWriter: static function (string $line): int {
                throw new \RuntimeException('sink failed');
            }
        );

        $logger->warning('first', ['server_key' => 'srv1']);

        $this->assertNotEmpty($increments);
        $this->assertSame('ami_log_sink_dropped_total', $increments[0]['name']);
        $this->assertSame('sink_exception', $increments[0]['labels']['reason']);
        $this->assertSame('srv1', $increments[0]['labels']['server_key']);
    }

    public function testSinkQueuePreservesFifoOrder(): void
    {
        $lines = [];
        $calls = 0;
        $logger = new Logger(
            sinkCapacity: 10,
            maxDrainPerLog: 10,
            sinkWriter: function (string $line) use (&$lines, &$calls): int {
                $calls++;
                if ($calls <= 2) {
                    return 0;
                }
                $lines[] = $line;
                return strlen($line);
            }
        );

        $logger->info('first', ['server_key' => 'srv1']);
        $logger->info('second', ['server_key' => 'srv1']);
        $logger->info('third', ['server_key' => 'srv1']);

        $this->assertGreaterThanOrEqual(3, count($lines));
        $decoded = array_map(static fn (string $line): array => json_decode($line, true), $lines);
        $messages = array_column($decoded, 'message');

        $this->assertSame('first', $messages[0]);
        $this->assertSame('second', $messages[1]);
        $this->assertSame('third', $messages[2]);
    }
}
