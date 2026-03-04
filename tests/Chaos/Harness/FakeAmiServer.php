<?php

declare(strict_types=1);

namespace Tests\Chaos\Harness;

/**
 * Deterministic AMI-like fake server used by chaos simulations.
 *
 * Script format:
 * - timed steps: ['type' => 'send_frame'|'send_truncated_frame'|'send_banner'|'send_garbage'|'close', 'at_ms' => int, ...]
 * - action hooks: ['type' => 'send_on_action', 'action' => 'Ping', ...]
 */
final class FakeAmiServer
{
    private string $host;
    private int $port;

    /** @var resource|null */
    private $server = null;

    /** @var array<int, resource> */
    private array $clients = [];

    /** @var array<int, string> */
    private array $readBuffers = [];

    /** @var array<int, array{at: float, payload: string}> */
    private array $writeQueue = [];

    /** @var array<int, array<string, mixed>> */
    private array $timedSteps = [];

    /** @var array<int, array<string, mixed>> */
    private array $actionHooks = [];

    private float $startedAt = 0.0;

    public function __construct(array $script = [], string $host = '127.0.0.1', int $port = 0)
    {
        $this->host = $host;
        $this->port = $port;

        foreach ($script as $step) {
            $type = (string) ($step['type'] ?? '');
            if ($type === 'send_on_action') {
                $this->actionHooks[] = $step;
            } else {
                $this->timedSteps[] = $step;
            }
        }

        usort($this->timedSteps, static function (array $a, array $b): int {
            return (int) ($a['at_ms'] ?? 0) <=> (int) ($b['at_ms'] ?? 0);
        });
    }

    public function start(): void
    {
        $errno = 0;
        $errstr = '';
        $server = @stream_socket_server(
            sprintf('tcp://%s:%d', $this->host, $this->port),
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        );

        if (!is_resource($server)) {
            throw new \RuntimeException(sprintf('Unable to start fake AMI server: %s (%d)', $errstr, $errno));
        }

        stream_set_blocking($server, false);
        $this->server = $server;

        $name = stream_socket_get_name($server, false);
        if (!is_string($name) || !str_contains($name, ':')) {
            throw new \RuntimeException('Unable to resolve bound server port');
        }
        $parts = explode(':', $name);
        $this->port = (int) end($parts);

        $this->startedAt = microtime(true);
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function tick(): void
    {
        $this->acceptClients();
        $this->readClients();
        $this->runTimedSteps();
        $this->flushWrites();
    }

    public function stop(): void
    {
        foreach ($this->clients as $client) {
            @fclose($client);
        }
        $this->clients = [];
        $this->readBuffers = [];
        $this->writeQueue = [];

        if (is_resource($this->server)) {
            @fclose($this->server);
        }
        $this->server = null;
    }

    private function acceptClients(): void
    {
        if (!is_resource($this->server)) {
            return;
        }

        while (true) {
            $client = @stream_socket_accept($this->server, 0);
            if (!is_resource($client)) {
                break;
            }

            stream_set_blocking($client, false);
            $id = (int) $client;
            $this->clients[$id] = $client;
            $this->readBuffers[$id] = '';
        }
    }

    private function readClients(): void
    {
        foreach ($this->clients as $id => $client) {
            $chunk = @fread($client, 65536);
            if ($chunk === false || $chunk === '') {
                continue;
            }

            $this->readBuffers[$id] .= $chunk;
            $this->drainActionFrames($id);
        }
    }

    private function drainActionFrames(int $clientId): void
    {
        $buffer = $this->readBuffers[$clientId] ?? '';

        while (true) {
            [$pos, $delimiterLen] = $this->findDelimiter($buffer);
            if ($pos === null) {
                break;
            }

            $frame = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + $delimiterLen);

            $action = $this->extractHeaderValue($frame, 'action');
            if ($action !== null) {
                $this->queueActionHooks($action);
            }
        }

        $this->readBuffers[$clientId] = $buffer;
    }

    private function runTimedSteps(): void
    {
        if ($this->timedSteps === []) {
            return;
        }

        $elapsedMs = (microtime(true) - $this->startedAt) * 1000;

        while ($this->timedSteps !== []) {
            $step = $this->timedSteps[0];
            $atMs = (int) ($step['at_ms'] ?? 0);
            if ($elapsedMs < $atMs) {
                break;
            }

            array_shift($this->timedSteps);
            $this->queueStepPayload($step);
        }
    }

    private function queueActionHooks(string $action): void
    {
        foreach ($this->actionHooks as $hook) {
            $hookAction = (string) ($hook['action'] ?? '');
            if (strcasecmp($hookAction, $action) !== 0) {
                continue;
            }

            $this->queueStepPayload($hook);
        }
    }

    /** @param array<string, mixed> $step */
    private function queueStepPayload(array $step): void
    {
        $type = (string) ($step['type'] ?? '');
        if ($type === 'close') {
            foreach ($this->clients as $client) {
                @fclose($client);
            }
            $this->clients = [];
            return;
        }

        $delimiter = $this->decodeEscapes((string) ($step['delimiter'] ?? "\\r\\n\\r\\n"));

        if ($type === 'send_banner') {
            $payload = ((string) ($step['version'] ?? 'Asterisk Call Manager/20.0.0')) . "\r\n";
        } elseif ($type === 'send_frame' || $type === 'send_on_action') {
            $frame = $this->decodeEscapes((string) ($step['frame'] ?? ''));
            $payload = rtrim($frame, "\r\n") . $delimiter;
        } elseif ($type === 'send_truncated_frame') {
            // Deliberately avoid appending AMI delimiters to exercise parser truncation recovery.
            $payload = rtrim($this->decodeEscapes((string) ($step['frame'] ?? '')), "\r\n");
        } elseif ($type === 'send_garbage') {
            $payload = $this->decodeEscapes((string) ($step['bytes'] ?? "\x00\x01garbage\x02"));
        } else {
            return;
        }

        $chunks = $this->chunkPayload($payload, $step);
        $chunkDelayMs = (int) ($step['chunk_delay_ms'] ?? 0);
        $startAt = microtime(true) + ((int) ($step['delay_ms'] ?? 0) / 1000);

        foreach ($chunks as $index => $chunk) {
            $this->writeQueue[] = [
                'at' => $startAt + (($chunkDelayMs * $index) / 1000),
                'payload' => $chunk,
            ];
        }
    }

    private function flushWrites(): void
    {
        if ($this->writeQueue === [] || $this->clients === []) {
            return;
        }

        usort($this->writeQueue, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);
        $now = microtime(true);
        $remaining = [];

        foreach ($this->writeQueue as $entry) {
            if ($entry['at'] > $now) {
                $remaining[] = $entry;
                continue;
            }

            foreach ($this->clients as $id => $client) {
                $written = @fwrite($client, $entry['payload']);
                if ($written === false) {
                    @fclose($client);
                    unset($this->clients[$id], $this->readBuffers[$id]);
                }
            }
        }

        $this->writeQueue = $remaining;
    }

    /** @return array{0: int|null, 1: int} */
    private function findDelimiter(string $buffer): array
    {
        $posCrlf = strpos($buffer, "\r\n\r\n");
        $posLf = strpos($buffer, "\n\n");

        if ($posCrlf === false && $posLf === false) {
            return [null, 0];
        }

        if ($posCrlf !== false && ($posLf === false || $posCrlf < $posLf)) {
            return [$posCrlf, 4];
        }

        return [$posLf, 2];
    }

    private function extractHeaderValue(string $frame, string $header): ?string
    {
        foreach (preg_split('/\r?\n/', $frame) ?: [] as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = explode(':', $line, 2);
            if (strcasecmp(trim($key), $header) === 0) {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $step
     * @return list<string>
     */
    private function chunkPayload(string $payload, array $step): array
    {
        $chunkSize = (int) ($step['chunk_size'] ?? 0);
        if ($chunkSize <= 0) {
            return [$payload];
        }

        $chunks = [];
        $len = strlen($payload);
        for ($offset = 0; $offset < $len; $offset += $chunkSize) {
            $chunks[] = substr($payload, $offset, $chunkSize);
        }

        return $chunks;
    }

    private function decodeEscapes(string $value): string
    {
        return str_replace(['\\r', '\\n', '\\t'], ["\r", "\n", "\t"], $value);
    }
}
