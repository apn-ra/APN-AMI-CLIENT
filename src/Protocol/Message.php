<?php

declare(strict_types=1);

namespace Apn\AmiClient\Protocol;

/**
 * Base class for all incoming AMI messages.
 */
abstract readonly class Message
{
    /**
     * @param array<string, string|array<int, string>> $headers Normalized headers (keys are lowercase).
     */
    public function __construct(
        protected array $headers,
    ) {
    }

    /**
     * Returns all headers.
     *
     * @return array<string, string|array<int, string>>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Returns a specific header by name (case-insensitive due to normalization).
     */
    public function getHeader(string $key): string|array|null
    {
        return $this->headers[strtolower($key)] ?? null;
    }
}
