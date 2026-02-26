<?php

declare(strict_types=1);

namespace Apn\AmiClient\Core;

use Psr\Log\AbstractLogger;
use Throwable;

/**
 * Simple JSON logger for the AMI client.
 */
class Logger extends AbstractLogger
{
    private int $workerPid;
    private SecretRedactor $redactor;
    private ?string $defaultServerKey = null;

    public function __construct(?SecretRedactor $redactor = null)
    {
        $this->workerPid = getmypid() ?: 0;
        $this->redactor = $redactor ?? new SecretRedactor();
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
            echo json_encode($payload, JSON_THROW_ON_ERROR) . PHP_EOL;
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

        echo $line . PHP_EOL;
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
