<?php

declare(strict_types=1);

namespace Apn\AmiClient\Core;

use Apn\AmiClient\Core\Contracts\MetricsCollectorInterface;
use Psr\Log\AbstractLogger;
use Throwable;

/**
 * Simple JSON logger for the AMI client.
 */
class Logger extends AbstractLogger
{
    private const int DEFAULT_SINK_CAPACITY = 1024;
    private const int DEFAULT_MAX_DRAIN_PER_LOG = 32;
    private const int DEFAULT_SINK_WARNING_INTERVAL_MS = 1000;

    private int $workerPid;
    private SecretRedactor $redactor;
    private MetricsCollectorInterface $metrics;
    private ?string $defaultServerKey = null;
    private int $sinkCapacity;
    private int $maxDrainPerLog;
    private int $sinkWarningIntervalMs;
    /** @var \SplQueue<string> */
    private \SplQueue $sinkQueue;
    /** @var callable(string): int|false */
    private $sinkWriter;
    /** @var array<string, int> */
    private array $lastSinkWarningAt = [];

    public function __construct(
        ?SecretRedactor $redactor = null,
        ?MetricsCollectorInterface $metrics = null,
        int $sinkCapacity = self::DEFAULT_SINK_CAPACITY,
        int $maxDrainPerLog = self::DEFAULT_MAX_DRAIN_PER_LOG,
        ?callable $sinkWriter = null,
    )
    {
        $this->workerPid = getmypid() ?: 0;
        $this->redactor = $redactor ?? new SecretRedactor();
        $this->metrics = $metrics ?? new NullMetricsCollector();
        $this->sinkCapacity = max(1, $sinkCapacity);
        $this->maxDrainPerLog = max(1, $maxDrainPerLog);
        $this->sinkWarningIntervalMs = self::DEFAULT_SINK_WARNING_INTERVAL_MS;
        $this->sinkQueue = new \SplQueue();
        $this->sinkWriter = $sinkWriter ?? $this->createDefaultSinkWriter();
    }

    /**
     * Sets a default server key to be included in all logs.
     */
    public function withServerKey(string $serverKey): self
    {
        $clone = clone $this;
        $clone->defaultServerKey = $serverKey;
        return $clone;
    }

    /**
     * Log a message with context.
     *
     * @param mixed $level
     * @param string|\Stringable $message
     * @param array $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        try {
            $base = [
                'timestamp_ms' => (int)(microtime(true) * 1000),
                'level' => strtoupper((string)$level),
                'message' => (string)$message,
                'worker_pid' => $this->workerPid,
            ];

            if ($this->defaultServerKey !== null && !isset($context['server_key'])) {
                $base['server_key'] = $this->defaultServerKey;
            }

            // Ensure server_key and action_id are at least present as null if not provided,
            // to keep consistent structure if required by guidelines,
            // but guideline says "where applicable" for action_id.
            // Let's just merge and redact.
            $payload = array_merge($base, $context);

            // Mandatory fields check (Guideline 9)
            if (!isset($payload['server_key'])) {
                $payload['server_key'] = 'unknown';
            }

            if (!isset($payload['action_id'])) {
                $payload['action_id'] = null;
            }

            if (!isset($payload['queue_depth'])) {
                $payload['queue_depth'] = null;
            }

            // Guideline 9: Secret Redaction
            $payload = $this->redactor->redact($payload);

            // Guideline 9: Structured Logging
            $this->emitLine(json_encode($payload, JSON_THROW_ON_ERROR) . PHP_EOL, (string) $payload['server_key']);
        } catch (Throwable $e) {
            $this->emitFallback($level, $message, $context, $e);
        }
    }

    private function emitFallback(
        mixed $level,
        string|\Stringable $message,
        array $context,
        Throwable $error
    ): void {
        $timestamp = (int)(microtime(true) * 1000);
        $levelName = strtoupper($this->safeString($level));
        $serverKey = $this->safeString($context['server_key'] ?? $this->defaultServerKey ?? 'unknown');
        $messageString = $this->safeString($message);
        $errorString = $this->safeString($error::class . ': ' . $error->getMessage());

        $line = sprintf(
            'LOG_FALLBACK timestamp_ms=%d level=%s message=%s server_key=%s error=%s',
            $timestamp,
            $this->toSafeAscii($levelName),
            $this->toSafeAscii($messageString),
            $this->toSafeAscii($serverKey),
            $this->toSafeAscii($errorString)
        );

        $this->emitLine($line . PHP_EOL, $serverKey);
    }

    private function emitLine(string $line, string $serverKey): void
    {
        if (!$this->enqueueLine($line, $serverKey)) {
            return;
        }

        $this->drainQueue($serverKey);
    }

    private function enqueueLine(string $line, string $serverKey): bool
    {
        if ($this->sinkQueue->count() >= $this->sinkCapacity) {
            $this->metrics->increment('ami_log_sink_dropped_total', [
                'server_key' => $serverKey,
                'reason' => 'capacity_exceeded',
            ]);
            $this->emitSinkWarning($serverKey, 'capacity_exceeded');
            return false;
        }

        $this->sinkQueue->enqueue($line);
        return true;
    }

    private function drainQueue(string $serverKey): void
    {
        $drained = 0;

        while ($drained < $this->maxDrainPerLog && !$this->sinkQueue->isEmpty()) {
            $line = $this->sinkQueue->bottom();

            try {
                $written = ($this->sinkWriter)($line);
            } catch (Throwable) {
                $this->metrics->increment('ami_log_sink_dropped_total', [
                    'server_key' => $serverKey,
                    'reason' => 'sink_exception',
                ]);
                $this->emitSinkWarning($serverKey, 'sink_exception');
                return;
            }

            if ($written === false) {
                $this->metrics->increment('ami_log_sink_dropped_total', [
                    'server_key' => $serverKey,
                    'reason' => 'sink_write_failed',
                ]);
                $this->emitSinkWarning($serverKey, 'sink_write_failed');
                return;
            }

            if ($written === 0) {
                return;
            }

            $lineLength = strlen($line);
            if ($written >= $lineLength) {
                $this->sinkQueue->dequeue();
                $drained++;
                continue;
            }

            $this->sinkQueue[0] = substr($line, $written);
            return;
        }
    }

    private function emitSinkWarning(string $serverKey, string $reason): void
    {
        $now = (int)(microtime(true) * 1000);
        $last = $this->lastSinkWarningAt[$reason] ?? 0;
        if (($now - $last) < $this->sinkWarningIntervalMs) {
            return;
        }
        $this->lastSinkWarningAt[$reason] = $now;

        $payload = [
            'timestamp_ms' => $now,
            'level' => 'WARNING',
            'message' => 'Log sink drop',
            'worker_pid' => $this->workerPid,
            'server_key' => $serverKey,
            'queue_depth' => $this->sinkQueue->count(),
            'queue_type' => 'log_sink',
            'reason' => $reason,
            'action_id' => null,
        ];

        $payload = $this->redactor->redact($payload);

        try {
            $line = json_encode($payload, JSON_THROW_ON_ERROR) . PHP_EOL;
        } catch (Throwable) {
            return;
        }

        try {
            $written = ($this->sinkWriter)($line);
        } catch (Throwable) {
            return;
        }

        if ($written === false || $written === 0) {
            return;
        }
    }

    /**
     * @return callable(string): int|false
     */
    private function createDefaultSinkWriter(): callable
    {
        $stream = fopen('php://stdout', 'wb');
        if (!is_resource($stream)) {
            return static fn (string $line): int|false => 0;
        }

        stream_set_write_buffer($stream, 0);
        stream_set_blocking($stream, false);

        return static function (string $line) use ($stream): int|false {
            if (!is_resource($stream)) {
                return false;
            }

            $read = [];
            $write = [$stream];
            $except = [];
            $ready = stream_select($read, $write, $except, 0, 0);
            if ($ready !== 1) {
                return 0;
            }

            return fwrite($stream, $line);
        };
    }

    private function safeString(mixed $value): string
    {
        try {
            return (string) $value;
        } catch (Throwable) {
            return '';
        }
    }

    private function toSafeAscii(string $value): string
    {
        $sanitized = preg_replace('/[^\x20-\x7E]/', '?', $value);
        if ($sanitized === null) {
            return '';
        }
        return $sanitized;
    }
}
