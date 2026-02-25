<?php

declare(strict_types=1);

namespace Apn\AmiClient\Core;

/**
 * Redacts sensitive information from data structures.
 */
class SecretRedactor
{
    private const SENSITIVE_KEYS = [
        'secret',
        'password',
        'variable',
    ];

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
            } elseif (in_array(strtolower((string)$key), self::SENSITIVE_KEYS, true)) {
                $data[$key] = '********';
            }
        }

        return $data;
    }
}
