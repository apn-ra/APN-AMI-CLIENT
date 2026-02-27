<?php

declare(strict_types=1);

namespace Apn\AmiClient\Transport;

use Apn\AmiClient\Core\Contracts\MetricsCollectorInterface;
use Apn\AmiClient\Core\Contracts\TransportInterface;
use Apn\AmiClient\Core\NullMetricsCollector;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Orchestrates stream_select across multiple TcpTransports.
 * Enforces Guideline 2: Stream Select Ownership.
 */
class Reactor
{
    /** @var array<string, TcpTransport> */
    private array $transports = [];
    private readonly LoggerInterface $logger;
    private readonly MetricsCollectorInterface $metrics;

    public function __construct(
        ?LoggerInterface $logger = null,
        ?MetricsCollectorInterface $metrics = null
    )
    {
        $this->logger = $logger ?? new NullLogger();
        $this->metrics = $metrics ?? new NullMetricsCollector();
    }

    /**
     * Register a transport with the reactor.
     */
    public function register(string $key, TcpTransport $transport): void
    {
        $this->transports[$key] = $transport;
    }

    /**
     * Unregister a transport from the reactor.
     */
    public function unregister(string $key): void
    {
        unset($this->transports[$key]);
    }

    /**
     * Perform one tick of I/O multiplexing for all registered transports.
     *
     * @param int $timeoutMs Maximum selector wait in milliseconds. Valid range:
     *                       0..TransportInterface::MAX_TICK_TIMEOUT_MS. Negative values are rejected.
     *                       Values above MAX_TICK_TIMEOUT_MS are clamped.
     */
    public function tick(int $timeoutMs = 0): void
    {
        $timeoutMs = $this->normalizeTimeoutMs($timeoutMs);

        if (empty($this->transports)) {
            return;
        }

        $read = [];
        $write = [];
        $except = null;

        /** @var array<int, string> Map resource ID to transport key */
        $idToKey = [];

        foreach ($this->transports as $key => $transport) {
            $resource = $transport->getResource();
            if ($resource === null || !is_resource($resource)) {
                continue;
            }

            $id = (int) $resource;
            if ($transport->isConnected()) {
                $read[] = $resource;
            }
            $idToKey[$id] = $key;

            if ($transport->isConnecting() || ($transport->isConnected() && $transport->hasPendingWrites())) {
                $write[] = $resource;
            }
        }

        if (empty($read) && empty($write)) {
            return;
        }

        $seconds = (int) ($timeoutMs / 1000);
        $microseconds = ($timeoutMs % 1000) * 1000;

        // Guideline 2: The event loop or a dedicated Reactor component must own the stream_select call.
        $selectStartedAt = microtime(true);
        [$ready, $error] = $this->captureError(function () use (&$read, &$write, &$except, $seconds, $microseconds) {
            return $this->selectStreams($read, $write, $except, $seconds, $microseconds);
        });
        $selectWaitMs = (microtime(true) - $selectStartedAt) * 1000;
        $this->safeMetric(fn () => $this->metrics->record('ami_selector_wait_ms', $selectWaitMs, [
            'scope' => 'reactor',
        ]));

        if ($ready === false) {
            $this->handleStreamSelectFailure($idToKey, $error);
            return;
        }

        if ($ready === 0) {
            return;
        }

        foreach ($read as $resource) {
            $id = (int) $resource;
            if (isset($idToKey[$id])) {
                $this->transports[$idToKey[$id]]->read();
            }
        }

        // We use a copy of $write because some transports might have been closed in the read phase.
        foreach ($write as $resource) {
            $id = (int) $resource;
            if (isset($idToKey[$id])) {
                $transport = $this->transports[$idToKey[$id]];
                $transport->handleWriteReady();
            }
        }
    }

    /**
     * @param array<int, string> $idToKey
     * @param array{type: int, message: string}|null $error
     */
    private function handleStreamSelectFailure(array $idToKey, ?array $error): void
    {
        foreach ($idToKey as $id => $key) {
            if (!isset($this->transports[$key])) {
                continue;
            }

            $transport = $this->transports[$key];
            $resource = $transport->getResource();
            if (!$this->isSelectableStream($resource)) {
                $this->logTransportError($key, 'stream_select', $error, [
                    'resource_state' => 'invalid',
                ]);
                $transport->close(false);
                continue;
            }

            // Selector failure is global for this tick; mark affected transports and reconnect
            // on the next manager pass without issuing secondary selector probes.
            $this->logTransportError($key, 'stream_select', $error, [
                'resource_state' => 'affected',
            ]);
            $transport->close(false);
        }
    }

    /**
     * @param array<int, resource> $read
     * @param array<int, resource> $write
     * @param array<int, resource>|null $except
     */
    protected function selectStreams(array &$read, array &$write, ?array &$except, int $seconds, int $microseconds): int|false
    {
        return stream_select($read, $write, $except, $seconds, $microseconds);
    }

    private function isSelectableStream(mixed $resource): bool
    {
        return is_resource($resource) && get_resource_type($resource) === 'stream';
    }

    /**
     * @param callable(): mixed $operation
     * @return array{0: mixed, 1: array{type: int, message: string, exception_class?: string}|null}
     */
    private function captureError(callable $operation): array
    {
        $error = null;
        $handler = static function (int $errno, string $errstr) use (&$error): bool {
            $error = [
                'type' => $errno,
                'message' => $errstr,
            ];
            return true;
        };
        set_error_handler($handler);
        try {
            $result = $operation();
        } catch (\Throwable $e) {
            $error ??= [
                'type' => (int) $e->getCode(),
                'message' => $e->getMessage(),
                'exception_class' => $e::class,
            ];
            $result = false;
        } finally {
            restore_error_handler();
        }

        return [$result, $error];
    }

    /**
     * @param array{type: int, message: string, exception_class?: string}|null $error
     * @param array<string, mixed> $context
     */
    private function logTransportError(string $serverKey, string $operation, ?array $error, array $context = []): void
    {
        $payload = array_merge([
            'server_key' => $serverKey,
            'operation' => $operation,
            'error_message' => $error['message'] ?? null,
            'error_type' => $error['type'] ?? null,
            'exception_class' => $error['exception_class'] ?? null,
        ], $context);

        $this->safeLog('warning', 'Reactor stream_select failed', $payload);
        $this->metrics->increment('ami_transport_errors_total', [
            'server_key' => $serverKey,
            'operation' => $operation,
        ]);
    }

    private function safeMetric(callable $operation): void
    {
        try {
            $operation();
        } catch (\Throwable $e) {
            $this->safeLog('warning', 'Metrics emission failed', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    protected function normalizeTimeoutMs(int $timeoutMs): int
    {
        if ($timeoutMs < TransportInterface::MIN_TICK_TIMEOUT_MS) {
            throw new \InvalidArgumentException('timeoutMs must be >= 0.');
        }

        $max = TransportInterface::MAX_TICK_TIMEOUT_MS;
        if ($timeoutMs > $max) {
            $this->metrics->increment('ami_runtime_timeout_clamped_total', [
                'component' => 'reactor',
                'reason' => 'above_max',
            ]);
            $this->safeLog('warning', 'Reactor tick timeout exceeds maximum; clamping.', [
                'timeout_ms' => $timeoutMs,
                'max_timeout_ms' => $max,
            ]);
            return $max;
        }

        return $timeoutMs;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function safeLog(string $level, string $message, array $context = []): void
    {
        try {
            match ($level) {
                'debug' => $this->logger->debug($message, $context),
                'info' => $this->logger->info($message, $context),
                'notice' => $this->logger->notice($message, $context),
                'warning' => $this->logger->warning($message, $context),
                'error' => $this->logger->error($message, $context),
                'critical' => $this->logger->critical($message, $context),
                'alert' => $this->logger->alert($message, $context),
                'emergency' => $this->logger->emergency($message, $context),
                default => $this->logger->log($level, $message, $context),
            };
        } catch (\Throwable) {
            // NB-51: logger failures must never interrupt runtime tick processing.
        }
    }
}
