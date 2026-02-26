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

    /** @var string[] */
    private array $sensitiveKeys;

    /** @var string[] */
    private array $sensitiveKeyPatterns;

    public function __construct(
        array $additionalSensitiveKeys = [],
        array $additionalSensitiveKeyPatterns = [],
    ) {
        $normalizedKeys = array_map(
            static fn (mixed $key): string => strtolower(trim((string) $key)),
            array_merge(self::DEFAULT_SENSITIVE_KEYS, $additionalSensitiveKeys)
        );

        $this->sensitiveKeys = array_values(array_unique(array_filter($normalizedKeys)));
        $this->sensitiveKeyPatterns = array_values(array_filter(array_map('strval', array_merge([
            '/(password|secret|token|auth|key)/i',
        ], $additionalSensitiveKeyPatterns))));
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
}
