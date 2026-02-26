<?php

declare(strict_types=1);

namespace Apn\AmiClient\Core;

/**
 * Redacts sensitive information from data structures.
 */
class SecretRedactor
{
    private const NON_REDACTABLE_KEYS = [
        'server_key',
        'action_id',
        'queue_depth',
        'worker_pid',
        'timestamp_ms',
        'level',
        'message',
    ];

    private const DEFAULT_SENSITIVE_KEYS = [
        'secret',
        'password',
        'token',
        'auth',
        'authorization',
        'key',
        'api_key',
        'apikey',
        'access_token',
        'refresh_token',
        'private_key',
        'public_key',
        'variable',
    ];

    private const DEFAULT_SENSITIVE_VALUE_PATTERNS = [
        '/\b(password|secret|token|api_key|apikey)\s*[:=]\s*[^\s,;]+/i',
        '/\bBearer\s+[A-Za-z0-9\-._~+\/]+=*/i',
    ];

    /** @var string[] */
    private array $sensitiveKeys;

    /** @var string[] */
    private array $sensitiveKeyPatterns;

    /** @var string[] */
    private array $sensitiveValuePatterns;

    public function __construct(
        array $additionalSensitiveKeys = [],
        array $additionalSensitiveKeyPatterns = [],
        array $additionalSensitiveValuePatterns = [],
    ) {
        $normalizedKeys = array_map(
            static fn (mixed $key): string => strtolower(trim((string) $key)),
            array_merge(self::DEFAULT_SENSITIVE_KEYS, $additionalSensitiveKeys)
        );

        $this->sensitiveKeys = array_values(array_unique(array_filter($normalizedKeys)));
        $this->sensitiveKeyPatterns = array_values(array_filter(array_map('strval', array_merge([
            '/(password|secret|token|auth|key)/i',
        ], $additionalSensitiveKeyPatterns))));
        $this->sensitiveValuePatterns = array_values(array_filter(array_map('strval', array_merge(
            self::DEFAULT_SENSITIVE_VALUE_PATTERNS,
            $additionalSensitiveValuePatterns
        ))));
    }

    /**
     * Redacts sensitive information from the given data.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->redact($value);
            } elseif ($this->isSensitiveKey((string) $key)) {
                $data[$key] = '********';
            } elseif (is_string($value)) {
                $data[$key] = $this->redactValue($value);
            }
        }

        return $data;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        if (in_array($normalized, self::NON_REDACTABLE_KEYS, true)) {
            return false;
        }

        if (in_array($normalized, $this->sensitiveKeys, true)) {
            return true;
        }

        foreach ($this->sensitiveKeyPatterns as $pattern) {
            if (@preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    private function redactValue(string $value): string
    {
        $redacted = $value;
        foreach ($this->sensitiveValuePatterns as $pattern) {
            $result = @preg_replace($pattern, '********', $redacted);
            if (is_string($result)) {
                $redacted = $result;
            }
        }

        return $redacted;
    }
}
