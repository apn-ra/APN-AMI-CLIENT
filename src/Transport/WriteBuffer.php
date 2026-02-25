<?php

declare(strict_types=1);

namespace Apn\AmiClient\Transport;

use Apn\AmiClient\Exceptions\BackpressureException;

/**
 * Manages outbound data for a transport.
 * Handles partial writes and buffer size limits (Guideline 2, 8).
 */
final class WriteBuffer
{
    private string $buffer = '';

    /** @var int 5MB default limit (Guideline 2: Maximum buffer size enforcement) */
    private const int DEFAULT_MAX_SIZE = 5242880;

    public function __construct(
        private readonly int $maxSize = self::DEFAULT_MAX_SIZE,
    ) {
    }

    /**
     * Push data into the buffer.
     *
     * @throws BackpressureException If max size is exceeded.
     */
    public function push(string $data): void
    {
        if ($this->size() + strlen($data) > $this->maxSize) {
            throw new BackpressureException(sprintf(
                "Write buffer limit of %d bytes reached. Current: %d, New: %d",
                $this->maxSize,
                $this->size(),
                strlen($data)
            ));
        }

        $this->buffer .= $data;
    }

    /**
     * Get the current buffer content.
     */
    public function content(): string
    {
        return $this->buffer;
    }

    /**
     * Get the current buffer size.
     */
    public function size(): int
    {
        return strlen($this->buffer);
    }

    /**
     * Advance the buffer by removing written bytes.
     */
    public function advance(int $bytes): void
    {
        if ($bytes <= 0) {
            return;
        }

        if ($bytes >= $this->size()) {
            $this->buffer = '';
            return;
        }

        $this->buffer = substr($this->buffer, $bytes);
    }

    /**
     * Clear the entire buffer.
     */
    public function clear(): void
    {
        $this->buffer = '';
    }

    /**
     * Check if the buffer is empty.
     */
    public function isEmpty(): bool
    {
        return $this->buffer === '';
    }
}
