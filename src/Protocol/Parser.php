<?php

declare(strict_types=1);

namespace Apn\AmiClient\Protocol;

use Apn\AmiClient\Exceptions\ParserDesyncException;
use Apn\AmiClient\Exceptions\ProtocolException;

/**
 * Parses raw AMI stream bytes into Message objects.
 *
 * Implements AMI framing logic (\r\n\r\n) and 1MB max frame size enforcement.
 * Includes key normalization (lowercase) and duplicate key handling as arrays.
 */
class Parser
{
    private string $buffer = '';

    /** @var int 64KB hard limit for individual AMI frames (Guideline 6, Phase 1) */
    private const int MAX_FRAME_SIZE = 65536;

    /**
     * Push raw bytes into the parser buffer.
     */
    public function push(string $data): void
    {
        $this->buffer .= $data;

        // Bounded memory check (Guideline 6: No unbounded memory growth)
        if (strlen($this->buffer) > self::MAX_FRAME_SIZE * 2 && !str_contains($this->buffer, "\r\n\r\n")) {
             $this->recover();
             throw new ParserDesyncException("Parser buffer exceeded safety limit without finding message delimiter");
        }
    }

    /**
     * Attempt to pull the next Message from the buffer.
     *
     * @return Message|null
     * @throws ProtocolException If a frame exceeds the size limit.
     */
    public function next(): ?Message
    {
        $pos = strpos($this->buffer, "\r\n\r\n");
        if ($pos === false) {
            return null;
        }

        $frame = substr($this->buffer, 0, $pos);
        $this->buffer = substr($this->buffer, $pos + 4);

        if (strlen($frame) > self::MAX_FRAME_SIZE) {
            $this->recover();
            throw new ProtocolException(sprintf("Frame size %d exceeded %d bytes limit", strlen($frame), self::MAX_FRAME_SIZE));
        }

        return $this->parseFrame($frame);
    }

    /**
     * Parse a single frame string into a Message object.
     */
    private function parseFrame(string $frame): Message
    {
        $lines = explode("\r\n", $frame);
        $headers = [];
        $currentKey = null;

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            if (!str_contains($line, ':')) {
                // Multi-line block handling (e.g. for "Response: Follows") (Guideline 6)
                if ($currentKey !== null) {
                    if (!isset($headers[$currentKey])) {
                         $headers[$currentKey] = $line;
                    } elseif (is_array($headers[$currentKey])) {
                         $headers[$currentKey][] = $line;
                    } else {
                         $headers[$currentKey] = [$headers[$currentKey], $line];
                    }
                }
                continue;
            }

            [$key, $value] = explode(':', $line, 2);
            $key = strtolower(trim($key));
            $value = trim($value);

            $currentKey = $key;

            if (isset($headers[$key])) {
                // Duplicate Key Handling (Guideline 6)
                if (!is_array($headers[$key])) {
                    $headers[$key] = [$headers[$key]];
                }
                $headers[$key][] = $value;
            } else {
                $headers[$key] = $value;
            }
        }

        // Determine if it's an Event or Response (Guideline 11: Immutable DTOs)
        if (isset($headers['event'])) {
            return new Event($headers);
        }

        return new Response($headers);
    }

    /**
     * Discard current buffer and scan for the next \r\n\r\n to recover (Guideline 6).
     */
    private function recover(): void
    {
        $pos = strpos($this->buffer, "\r\n\r\n");
        if ($pos !== false) {
            $this->buffer = substr($this->buffer, $pos + 4);
        } else {
            $this->buffer = '';
        }
    }
}
