<?php

declare(strict_types=1);

namespace Apn\AmiClient\Protocol;

use Apn\AmiClient\Exceptions\InvalidConfigurationException;
use Apn\AmiClient\Exceptions\ParserDesyncException;
use Apn\AmiClient\Exceptions\ProtocolException;

/**
 * Parses raw AMI stream bytes into Message objects.
 *
 * Implements AMI framing logic (\r\n\r\n) and configurable max frame size enforcement.
 * Includes key normalization (lowercase) and duplicate key handling as arrays.
 */
class Parser
{
    private const int FRAME_DELIMITER_BYTES = 4; // \r\n\r\n
    private const int MIN_FRAME_SIZE = 65536; // 64KB
    private const int DEFAULT_FRAME_SIZE = 1048576; // 1MB
    private const int MAX_FRAME_SIZE = 4194304; // 4MB

    private string $buffer = '';
    private int $bufferCap = 2097152; // 2MB (Guideline 6, Phase 1)
    private int $maxFrameSize = self::DEFAULT_FRAME_SIZE;
    private bool $bannerProcessed = false;
    /** @var callable(array<string, mixed>): void|null */
    private $debugHook = null;
    private int $debugPreviewBytes = 160;
    private int $peakBufferBytes = 0;
    private int $recoveries = 0;

    public function __construct(int $bufferCap = 2097152, int $maxFrameSize = self::DEFAULT_FRAME_SIZE)
    {
        $effectiveMaxFrameSize = max(self::MIN_FRAME_SIZE, min(self::MAX_FRAME_SIZE, $maxFrameSize));
        $minimumBufferCap = $effectiveMaxFrameSize + self::FRAME_DELIMITER_BYTES;

        if ($bufferCap < $minimumBufferCap) {
            throw new InvalidConfigurationException(sprintf(
                'Invalid parser configuration: bufferCap=%d is smaller than maxFrameSize=%d plus delimiterBytes=%d (minimum=%d).',
                $bufferCap,
                $effectiveMaxFrameSize,
                self::FRAME_DELIMITER_BYTES,
                $minimumBufferCap
            ));
        }

        $this->bufferCap = $bufferCap;
        $this->maxFrameSize = $effectiveMaxFrameSize;
    }

    /**
     * @param callable(array<string, mixed>): void|null $hook
     */
    public function setDebugHook(?callable $hook, int $previewBytes = 160): void
    {
        $this->debugHook = $hook;
        $this->debugPreviewBytes = max(32, min(512, $previewBytes));
    }

    /**
     * Reset the parser state.
     */
    public function reset(): void
    {
        $this->buffer = '';
        $this->bannerProcessed = false;
        $this->peakBufferBytes = 0;
        $this->recoveries = 0;
    }

    /**
     * Push raw bytes into the parser buffer.
     */
    public function push(string $data): void
    {
        $this->buffer .= $data;
        $this->peakBufferBytes = max($this->peakBufferBytes, strlen($this->buffer));

        // Bounded memory check (Guideline 6: No unbounded memory growth)
        if (strlen($this->buffer) > $this->bufferCap) {
             $this->emitDebugTelemetry([
                 'recovery_reason' => 'buffer_cap_exceeded',
                 'buffer_len' => strlen($this->buffer),
                 'buffer_cap' => $this->bufferCap,
             ]);
             $this->recover();
             if (strlen($this->buffer) > $this->bufferCap) {
                 $this->buffer = '';
             }
             throw new ParserDesyncException("Parser buffer exceeded safety limit (" . $this->bufferCap . " bytes)");
        }

        // Defensive check: if we have too much data and no delimiter, something is wrong.
        if (strlen($this->buffer) > $this->maxFrameSize * 2) {
            if (!str_contains($this->buffer, "\r\n\r\n") && !str_contains($this->buffer, "\n\n")) {
                $len = strlen($this->buffer);
                $this->buffer = '';
                $this->emitDebugTelemetry([
                    'recovery_reason' => 'delimiter_not_found',
                    'buffer_len' => $len,
                    'max_frame_size' => $this->maxFrameSize,
                ]);
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
            $delimiterUsed = 'crlfcrlf';
        } else {
            $pos = $posn;
            $delimiterLen = 2;
            $delimiterUsed = 'lflf';
        }

        $frame = substr($this->buffer, 0, $pos);
        $this->buffer = substr($this->buffer, $pos + $delimiterLen);

        $this->emitDebugTelemetry([
            'delimiter_used' => $delimiterUsed,
            'frame_len' => strlen($frame),
            'preview' => $this->redactPreview($frame),
        ]);

        if (strlen($frame) > $this->maxFrameSize) {
            throw new ProtocolException(sprintf("Frame size %d exceeded %d bytes limit", strlen($frame), $this->maxFrameSize));
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
     * @param array<string, mixed> $payload
     */
    private function emitDebugTelemetry(array $payload): void
    {
        if ($this->debugHook === null) {
            return;
        }

        try {
            ($this->debugHook)($payload);
        } catch (\Throwable) {
            // Telemetry hooks must never interrupt parser flow.
        }
    }

    private function redactPreview(string $frame): string
    {
        $preview = substr($frame, 0, $this->debugPreviewBytes);
        $preview = preg_replace('/\s+/', ' ', $preview) ?? $preview;
        $preview = preg_replace('/\b(secret|password|token)\s*:\s*[^\r\n]+/i', '$1: ********', $preview) ?? $preview;

        return trim($preview);
    }

    /**
     * Discard current buffer and scan for the next \r\n\r\n to recover (Guideline 6).
     */
    private function recover(): void
    {
        $this->recoveries++;
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

    /**
     * @return array{buffer_len:int,peak_buffer_len:int,recoveries:int,buffer_cap:int,max_frame_size:int}
     */
    public function diagnostics(): array
    {
        return [
            'buffer_len' => strlen($this->buffer),
            'peak_buffer_len' => $this->peakBufferBytes,
            'recoveries' => $this->recoveries,
            'buffer_cap' => $this->bufferCap,
            'max_frame_size' => $this->maxFrameSize,
        ];
    }
}
