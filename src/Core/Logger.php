<?php

declare(strict_types=1);

namespace Apn\AmiClient\Core;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

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

        // Guideline 9: Secret Redaction
        $payload = $this->redactor->redact($payload);

        // Guideline 9: Structured Logging
        echo json_encode($payload, JSON_THROW_ON_ERROR) . PHP_EOL;
    }

}
