<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use Apn\AmiClient\Core\AmiClient;
use Apn\AmiClient\Core\Contracts\TransportInterface;
use Apn\AmiClient\Correlation\ActionIdGenerator;
use Apn\AmiClient\Correlation\CorrelationManager;
use Apn\AmiClient\Correlation\CorrelationRegistry;
use Apn\AmiClient\Core\Logger;
use PHPUnit\Framework\TestCase;

final class AmiClientDebugTelemetryTest extends TestCase
{
    public function test_debug_telemetry_is_opt_in_and_emits_when_enabled(): void
    {
        $lines = [];
        $logger = new Logger(sinkWriter: function (string $line) use (&$lines): int {
            $lines[] = $line;
            return strlen($line);
        });

        $transport = new DebugTelemetryTransport();
        $correlation = new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry());
        $client = new AmiClient('node1', $transport, $correlation, logger: $logger);

        $transport->emitInbound("Response: Error\r\nActionID: tele-1\r\nSecret: hello\r\nMessage: Permission denied\r\n\r\n");
        $client->processTick();

        $payloadsDisabled = $this->decoded($lines);
        $this->assertFalse($this->containsMessage($payloadsDisabled, 'Inbound transport chunk'));
        $this->assertFalse($this->containsMessage($payloadsDisabled, 'Parser frame extracted'));

        $client->setDebugTelemetry(true);

        $transport->emitInbound("Response: Error\r\nActionID: tele-2\r\nSecret: world\r\nMessage: Permission denied\r\n\r\n");
        $client->processTick();

        $payloadsEnabled = $this->decoded($lines);
        $this->assertTrue($this->containsMessage($payloadsEnabled, 'Inbound transport chunk'));
        $this->assertTrue($this->containsMessage($payloadsEnabled, 'Parser frame extracted'));

        foreach ($payloadsEnabled as $payload) {
            if (($payload['message'] ?? '') === 'Inbound transport chunk' || ($payload['message'] ?? '') === 'Parser frame extracted') {
                $preview = (string) ($payload['preview'] ?? '');
                $this->assertStringNotContainsString('Secret: world', $preview);
            }
        }
    }

    /** @param array<int, string> $lines @return array<int, array<string, mixed>> */
    private function decoded(array $lines): array
    {
        $decoded = [];
        foreach ($lines as $line) {
            $json = json_decode($line, true);
            if (is_array($json)) {
                $decoded[] = $json;
            }
        }

        return $decoded;
    }

    /** @param array<int, array<string, mixed>> $payloads */
    private function containsMessage(array $payloads, string $message): bool
    {
        foreach ($payloads as $payload) {
            if (($payload['message'] ?? null) === $message) {
                return true;
            }
        }

        return false;
    }
}

final class DebugTelemetryTransport implements TransportInterface
{
    /** @var callable(string): void|null */
    private $onData = null;
    /** @var callable(array<string, mixed>): void|null */
    private $telemetry = null;

    public function open(): void
    {
    }

    public function close(bool $graceful = true): void
    {
    }

    public function send(string $data): void
    {
    }

    public function tick(int $timeoutMs = 0): void
    {
    }

    public function onData(callable $callback): void
    {
        $this->onData = $callback;
    }

    public function isConnected(): bool
    {
        return true;
    }

    public function getPendingWriteBytes(): int
    {
        return 0;
    }

    public function terminate(): void
    {
    }

    public function setInboundTelemetryCallback(?callable $callback, int $previewBytes = 160): void
    {
        $this->telemetry = $callback;
    }

    public function emitInbound(string $chunk): void
    {
        if ($this->telemetry !== null) {
            ($this->telemetry)([
                'chunk_len' => strlen($chunk),
                'delimiter_present' => true,
                'delimiter_used' => 'crlfcrlf',
                'preview' => 'Action: Login\\r\\nSecret: ********',
            ]);
        }

        if ($this->onData !== null) {
            ($this->onData)($chunk);
        }
    }
}
