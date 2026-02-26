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
    private int $bufferCap = 2097152; // 2MB (Guideline 6, Phase 1)
    private bool $bannerProcessed = false;

    /** @var int 64KB hard limit for individual AMI frames (Guideline 6, Phase 1) */
    private const int MAX_FRAME_SIZE = 65536;

    public function __construct(int $bufferCap = 2097152)
    {
        $this->bufferCap = $bufferCap;
    }

    /**
     * Reset the parser state.
     */
    public function reset(): void
    {
        $this->buffer = '';
        $this->bannerProcessed = false;
    }

    /**
     * Push raw bytes into the parser buffer.
     */
    public function push(string $data): void
    {
        $this->buffer .= $data;

        // Bounded memory check (Guideline 6: No unbounded memory growth)
        if (strlen($this->buffer) > $this->bufferCap) {
             $this->recover();
             if (strlen($this->buffer) > $this->bufferCap) {
                 $this->buffer = '';
             }
             throw new ParserDesyncException("Parser buffer exceeded safety limit (" . $this->bufferCap . " bytes)");
        }

        // Defensive check: if we have more than MAX_FRAME_SIZE and no delimiter, something is wrong.
        if (strlen($this->buffer) > self::MAX_FRAME_SIZE * 2) {
            if (!str_contains($this->buffer, "\r\n\r\n") && !str_contains($this->buffer, "\n\n")) {
                $len = strlen($this->buffer);
                $this->buffer = '';
                throw new ParserDesyncException("No message delimiter found in $len bytes of data (exceeded safety limit)");
            }
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
        // Handle initial banner (Task 4.3)
        if (!$this->bannerProcessed && strlen($this->buffer) > 0) {
            $posrn = strpos($this->buffer, "\r\n");
            $posn = strpos($this->buffer, "\n");
            
            if ($posrn !== false || $posn !== false) {
                $pos = ($posrn !== false && ($posn === false || $posrn < $posn)) ? $posrn : $posn;
                $line = substr($this->buffer, 0, $pos);
                
                if (str_starts_with($line, 'Asterisk Call Manager/')) {
                    $this->bannerProcessed = true;
                    $delimiterLen = ($posrn !== false && ($posn === false || $posrn < $posn)) ? 2 : 1;
                    $this->buffer = substr($this->buffer, $pos + $delimiterLen);
                    return new Banner($line);
                }
            }
        }

        $posrn = strpos($this->buffer, "\r\n\r\n");
        $posn = strpos($this->buffer, "\n\n");

        if ($posrn === false && $posn === false) {
            return null;
        }

        if ($posrn !== false && ($posn === false || $posrn < $posn)) {
            $pos = $posrn;
            $delimiterLen = 4;
        } else {
            $pos = $posn;
            $delimiterLen = 2;
        }

        $frame = substr($this->buffer, 0, $pos);
        $this->buffer = substr($this->buffer, $pos + $delimiterLen);

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
        $lines = preg_split('/\r?\n/', $frame);
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
        $posrn = strpos($this->buffer, "\r\n\r\n");
        $posn = strpos($this->buffer, "\n\n");

        if ($posrn !== false && ($posn === false || $posrn < $posn)) {
            $this->buffer = substr($this->buffer, $posrn + 4);
        } elseif ($posn !== false) {
            $this->buffer = substr($this->buffer, $posn + 2);
        } else {
            $this->buffer = '';
        }
    }
}
