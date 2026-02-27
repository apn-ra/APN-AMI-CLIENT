<?php

declare(strict_types=1);

namespace Apn\AmiClient\Transport;

use Apn\AmiClient\Core\Contracts\TransportInterface;
use Apn\AmiClient\Core\Contracts\MetricsCollectorInterface;
use Apn\AmiClient\Core\NullMetricsCollector;
use Apn\AmiClient\Exceptions\BackpressureException;
use Apn\AmiClient\Exceptions\ConnectionException;
use Apn\AmiClient\Exceptions\InvalidConfigurationException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * TCP transport implementation using non-blocking stream sockets.
 * Handles framing-agnostic byte transmission (Guideline 2).
 */
class TcpTransport implements TransportInterface
{
    /** @var resource|null */
    private $resource = null;
    private bool $connecting = false;
    private bool $connected = false;
    private string $remoteHost;
    private int $lastTickReadBytes = 0;
    private int $lastTickWrittenBytes = 0;

    private readonly WriteBuffer $writeBuffer;
    private readonly LoggerInterface $logger;
    private readonly MetricsCollectorInterface $metrics;
    /** @var array<string, string> */
    private array $labels;
    private readonly string $serverKey;

    /** @var callable(string): void|null */
    private $onDataCallback = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly int $connectTimeout = 30,
        int $writeBufferLimit = 5242880,
        private readonly int $maxBytesReadPerTick = 1048576,
        private readonly bool $enforceIpEndpoints = true,
        ?LoggerInterface $logger = null,
        ?MetricsCollectorInterface $metrics = null,
        array $labels = [],
        ?callable $hostnameResolver = null,
    ) {
        $this->writeBuffer = new WriteBuffer($writeBufferLimit);
        $this->logger = $logger ?? new NullLogger();
        $this->metrics = $metrics ?? new NullMetricsCollector();
        $this->labels = $labels;
        $this->serverKey = $labels['server_key'] ?? 'unknown';
        if (!isset($this->labels['server_key'])) {
            $this->labels['server_key'] = $this->serverKey;
        }
        if (!isset($this->labels['server_host'])) {
            $this->labels['server_host'] = $this->host;
        }

        $this->remoteHost = $this->resolveHost($hostnameResolver);
    }

    /**
     * @inheritDoc
     */
    public function open(): void
    {
        $remote = sprintf('tcp://%s:%d', $this->remoteHost, $this->port);
        $errno = 0;
        $errstr = '';

        // Use async connect to avoid blocking the tick loop.
        [$resource, $error] = $this->captureError(function () use ($remote, &$errno, &$errstr) {
            return stream_socket_client(
                $remote,
                $errno,
                $errstr,
                0.0,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
            );
        });

        if ($resource === false || !is_resource($resource)) {
            $this->logTransportError('connect', $error, [
                'error_code' => $errno,
                'error_message' => $errstr,
                'remote' => $remote,
            ]);
            throw new ConnectionException(sprintf(
                "Could not connect to %s: [%d] %s",
                $remote,
                $errno,
                $errstr
            ));
        }

        // Guideline 2: All socket operations must use non-blocking mode.
        [$blockingSet, $blockingError] = $this->captureError(function () use ($resource) {
            return stream_set_blocking($resource, false);
        });
        if ($blockingSet !== true) {
            $this->logTransportError('set_blocking', $blockingError, [
                'remote' => $remote,
            ]);
            $this->close(false);
            throw new ConnectionException(sprintf(
                "Could not configure non-blocking mode for %s",
                $remote
            ));
        }
        $this->resource = $resource;
        $this->connecting = true;
        $this->connected = false;
    }

    private function resolveHost(?callable $hostnameResolver): string
    {
        $hostIsIp = filter_var($this->host, FILTER_VALIDATE_IP) !== false;
        if ($hostIsIp) {
            return $this->host;
        }

        if ($this->enforceIpEndpoints) {
            throw new InvalidConfigurationException(sprintf(
                'Hostname endpoints are disabled by policy; provide an IP for %s:%d.',
                $this->host,
                $this->port
            ));
        }

        if ($hostnameResolver === null) {
            throw new InvalidConfigurationException(sprintf(
                'Hostname endpoints require a pre-resolved IP or injected hostname resolver for %s:%d.',
                $this->host,
                $this->port
            ));
        }

        $resolved = $hostnameResolver($this->host);
        if (!is_string($resolved) || $resolved === '' || filter_var($resolved, FILTER_VALIDATE_IP) === false) {
            throw new InvalidConfigurationException(sprintf(
                'Hostname resolver returned invalid IP for %s:%d.',
                $this->host,
                $this->port
            ));
        }

        return $resolved;
    }

    /**
     * @inheritDoc
     */
    public function close(bool $graceful = true): void
    {
        if (!$graceful) {
            $this->clearWriteBuffer();
        }

        if ($this->resource !== null && is_resource($this->resource)) {
            [$closed, $error] = $this->captureError(function () {
                return fclose($this->resource);
            });
            if ($closed === false) {
                $this->logTransportError('close', $error);
            }
        }
        $this->resource = null;
        $this->connecting = false;
        $this->connected = false;
    }

    /**
     * @inheritDoc
     */
    public function send(string $data): void
    {
        try {
            $this->writeBuffer->push($data);
        } catch (BackpressureException $e) {
            // Guideline 2: If the limit is reached, the connection must be dropped to prevent OOM.
            $this->terminate();
            throw $e;
        }
    }

    /**
     * @inheritDoc
     */
    public function onData(callable $callback): void
    {
        $this->onDataCallback = $callback;
    }

    /**
     * @inheritDoc
     */
    public function isConnected(): bool
    {
        return $this->connected && $this->resource !== null && is_resource($this->resource) && !feof($this->resource);
    }

    public function getPendingWriteBytes(): int
    {
        return $this->writeBuffer->size();
    }

    /**
     * Returns true while an async connect is in progress.
     */
    public function isConnecting(): bool
    {
        return $this->connecting;
    }

    /**
     * Check if there is data pending to be written.
     */
    public function hasPendingWrites(): bool
    {
        return !$this->writeBuffer->isEmpty();
    }

    /**
     * @inheritDoc
     * @param int $timeoutMs Maximum selector wait in milliseconds. Valid range:
     *                       0..TransportInterface::MAX_TICK_TIMEOUT_MS. Negative values are rejected.
     *                       Values above MAX_TICK_TIMEOUT_MS are clamped.
     */
    public function tick(int $timeoutMs = 0): void
    {
        $this->resetTickStats();
        $timeoutMs = $this->normalizeTimeoutMs($timeoutMs);

        if ($this->resource === null || !is_resource($this->resource)) {
            return;
        }

        $read = $this->connected ? [$this->resource] : [];
        $write = [];
        if ($this->connecting || (!$this->writeBuffer->isEmpty() && $this->connected)) {
            $write = [$this->resource];
        }
        $except = null;

        $seconds = (int) ($timeoutMs / 1000);
        $microseconds = ($timeoutMs % 1000) * 1000;

        // Perform I/O multiplexing.
        [$ready, $error] = $this->captureError(function () use (&$read, &$write, &$except, $seconds, $microseconds) {
            return stream_select($read, $write, $except, $seconds, $microseconds);
        });

        if ($ready === false) {
            $this->logTransportError('stream_select', $error);
            $this->close(false);
            return;
        }

        if ($ready === 0) {
            return;
        }

        if ($this->connected && in_array($this->resource, $read, true)) {
            $this->read();
        }

        // Re-check resource as read() might have closed it
        if ($this->resource !== null && in_array($this->resource, $write, true)) {
            $this->handleWriteReady();
        }
    }

    /**
     * Pull bytes from the socket into the callback (Guideline 2).
     * @internal
     */
    public function read(): void
    {
        if ($this->resource === null || !is_resource($this->resource)) {
            return;
        }

        $bytesRead = 0;
        $maxToRead = $this->maxBytesReadPerTick;

        while ($bytesRead < $maxToRead) {
            $chunkSize = min(8192, $maxToRead - $bytesRead);
            [$data, $error] = $this->captureError(function () use ($chunkSize) {
                return fread($this->resource, $chunkSize);
            });

            if ($data === false) {
                $this->logTransportError('read', $error);
                $this->close(false);
                return;
            }

            if ($data === '') {
                // If stream_select said it's ready but we read nothing, it might be EOF.
                if (feof($this->resource)) {
                    $this->close(false);
                }
                break;
            }

            $len = strlen($data);
            $bytesRead += $len;
            $this->lastTickReadBytes += $len;

            if ($this->onDataCallback !== null) {
                ($this->onDataCallback)($data);
            }

            if ($len < $chunkSize) {
                // No more data available on the socket for now
                break;
            }
        }
    }

    /**
     * Attempt to flush the outbound buffer (Guideline 2).
     * @internal
     */
    public function flush(): void
    {
        if ($this->resource === null || !is_resource($this->resource) || $this->writeBuffer->isEmpty()) {
            return;
        }

        $data = $this->writeBuffer->content();
        [$written, $error] = $this->captureError(function () use ($data) {
            return fwrite($this->resource, $data);
        });

        if ($written === false) {
            $this->logTransportError('write', $error);
            $this->close(false);
            return;
        }

        if ($written > 0) {
            $this->lastTickWrittenBytes += $written;
        }

        // Guideline 2: Every write operation must account for partial writes.
        $this->writeBuffer->advance($written);
    }

    /**
     * @inheritDoc
     */
    public function terminate(): void
    {
        $this->close(false);
    }

    /**
     * Handle write-ready notification for either async connect completion or flushing writes.
     */
    public function handleWriteReady(): void
    {
        if ($this->connecting) {
            $this->finalizeAsyncConnect();
        }

        if ($this->connected && $this->hasPendingWrites()) {
            $this->flush();
        }
    }

    /**
     * @internal
     */
    public function resetTickStats(): void
    {
        $this->lastTickReadBytes = 0;
        $this->lastTickWrittenBytes = 0;
    }

    /**
     * @internal
     */
    public function getLastTickWrittenBytes(): int
    {
        return $this->lastTickWrittenBytes;
    }

    private function finalizeAsyncConnect(): void
    {
        if ($this->resource === null || !is_resource($this->resource)) {
            return;
        }

        if (!$this->socketHelpersAvailable()) {
            if ($this->verifyAsyncConnectFallback()) {
                $this->connecting = false;
                $this->connected = true;
                return;
            }
            $this->close(false);
            return;
        }

        $error = $this->getSocketError($this->resource);
        if ($error === 0) {
            $this->connecting = false;
            $this->connected = true;
            return;
        }

        $this->close(false);
    }

    protected function clearWriteBuffer(): void
    {
        $this->writeBuffer->clear();
    }

    protected function normalizeTimeoutMs(int $timeoutMs): int
    {
        if ($timeoutMs < TransportInterface::MIN_TICK_TIMEOUT_MS) {
            throw new \InvalidArgumentException('timeoutMs must be >= 0.');
        }

        $max = TransportInterface::MAX_TICK_TIMEOUT_MS;
        if ($timeoutMs > $max) {
            $this->metrics->increment('ami_runtime_timeout_clamped_total', [
                'component' => 'transport',
                'reason' => 'above_max',
                'server_key' => $this->serverKey,
            ]);
            $this->safeLogWarning('Transport tick timeout exceeds maximum; clamping.', [
                'server_key' => $this->serverKey,
                'timeout_ms' => $timeoutMs,
                'max_timeout_ms' => $max,
            ]);
            return $max;
        }

        return $timeoutMs;
    }

    /**
     * @return bool True when socket helper functions are available.
     */
    protected function socketHelpersAvailable(): bool
    {
        return function_exists('socket_import_stream') && function_exists('socket_get_option');
    }

    /**
     * @param resource $resource
     */
    protected function getSocketError($resource): ?int
    {
        [$socket, $importError] = $this->captureError(function () use ($resource) {
            return socket_import_stream($resource);
        });
        if ($socket === false) {
            $this->logTransportError('socket_import_stream', $importError);
            return null;
        }

        [$errorValue, $optionError] = $this->captureError(function () use ($socket) {
            return socket_get_option($socket, SOL_SOCKET, SO_ERROR);
        });
        if (!is_int($errorValue)) {
            $this->logTransportError('socket_get_option', $optionError);
            return null;
        }

        return $errorValue;
    }

    /**
     * Fallback async-connect verification when ext-sockets helpers are unavailable.
     */
    protected function verifyAsyncConnectFallback(): bool
    {
        if ($this->resource === null || !is_resource($this->resource)) {
            return false;
        }

        $meta = stream_get_meta_data($this->resource);
        if (!is_array($meta)) {
            return false;
        }

        if (($meta['timed_out'] ?? false) === true || ($meta['eof'] ?? false) === true) {
            return false;
        }

        $peer = $this->getPeerName($this->resource);
        if ($peer === false || $peer === '') {
            return false;
        }

        return $this->probeWritable($this->resource);
    }

    /**
     * @param resource $resource
     * @return string|false
     */
    protected function getPeerName($resource): string|false
    {
        [$peer, $error] = $this->captureError(function () use ($resource) {
            return stream_socket_get_name($resource, true);
        });
        if ($peer === false) {
            $this->logTransportError('get_peer_name', $error);
        }
        return $peer;
    }

    /**
     * @param resource $resource
     */
    protected function probeWritable($resource): bool
    {
        [$result, $error] = $this->captureError(function () use ($resource) {
            return fwrite($resource, '');
        });
        if ($result === false) {
            $this->logTransportError('write_probe', $error);
            return false;
        }
        return true;
    }

    /**
     * Returns the raw resource handle.
     * @return resource|null
     * @internal
     */
    public function getResource()
    {
        return $this->resource;
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
    private function logTransportError(string $operation, ?array $error = null, array $context = []): void
    {
        $payload = array_merge([
            'server_key' => $this->serverKey,
            'operation' => $operation,
            'host' => $this->host,
            'port' => $this->port,
            'error_message' => $error['message'] ?? null,
            'error_type' => $error['type'] ?? null,
            'exception_class' => $error['exception_class'] ?? null,
        ], $context);

        $this->safeLogWarning('Transport operation failed', $payload);
        $this->metrics->increment('ami_transport_errors_total', array_merge(
            $this->labels,
            ['operation' => $operation]
        ));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function safeLogWarning(string $message, array $context): void
    {
        try {
            $this->logger->warning($message, $context);
        } catch (\Throwable) {
            // Logging must never interrupt runtime paths.
        }
    }
}
