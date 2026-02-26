<?php

declare(strict_types=1);

namespace Apn\AmiClient\Transport;

use Apn\AmiClient\Core\Contracts\TransportInterface;
use Apn\AmiClient\Exceptions\BackpressureException;
use Apn\AmiClient\Exceptions\ConnectionException;

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

    private readonly WriteBuffer $writeBuffer;

    /** @var callable(string): void|null */
    private $onDataCallback = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly int $connectTimeout = 30,
        int $writeBufferLimit = 5242880,
        private readonly int $maxBytesReadPerTick = 1048576,
    ) {
        $this->writeBuffer = new WriteBuffer($writeBufferLimit);
    }

    /**
     * @inheritDoc
     */
    public function open(): void
    {
        $remote = sprintf('tcp://%s:%d', $this->host, $this->port);
        $errno = 0;
        $errstr = '';

        // Use async connect to avoid blocking the tick loop.
        $resource = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            0.0,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
        );

        if ($resource === false || !is_resource($resource)) {
            throw new ConnectionException(sprintf(
                "Could not connect to %s: [%d] %s",
                $remote,
                $errno,
                $errstr
            ));
        }

        // Guideline 2: All socket operations must use non-blocking mode.
        stream_set_blocking($resource, false);
        $this->resource = $resource;
        $this->connecting = true;
        $this->connected = false;
    }

    /**
     * @inheritDoc
     */
    public function close(): void
    {
        if ($this->resource !== null && is_resource($this->resource)) {
            @fclose($this->resource);
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
     */
    public function tick(int $timeoutMs = 0): void
    {
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
        $ready = @stream_select($read, $write, $except, $seconds, $microseconds);

        if ($ready === false || $ready === 0) {
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
            $data = @fread($this->resource, $chunkSize);

            if ($data === false) {
                $this->close();
                return;
            }

            if ($data === '') {
                // If stream_select said it's ready but we read nothing, it might be EOF.
                if (feof($this->resource)) {
                    $this->close();
                }
                break;
            }

            $len = strlen($data);
            $bytesRead += $len;

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
        $written = fwrite($this->resource, $data);

        if ($written === false) {
            $this->close();
            return;
        }

        // Guideline 2: Every write operation must account for partial writes.
        $this->writeBuffer->advance($written);
    }

    /**
     * @inheritDoc
     */
    public function terminate(): void
    {
        $this->close();
        $this->writeBuffer->clear();
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

    private function finalizeAsyncConnect(): void
    {
        if ($this->resource === null || !is_resource($this->resource)) {
            return;
        }

        if (!function_exists('socket_import_stream') || !function_exists('socket_get_option')) {
            $this->connecting = false;
            $this->connected = true;
            return;
        }

        $socket = @socket_import_stream($this->resource);
        if ($socket === false) {
            $this->close();
            return;
        }

        $error = @socket_get_option($socket, SOL_SOCKET, SO_ERROR);
        if ($error === 0) {
            $this->connecting = false;
            $this->connected = true;
            return;
        }

        $this->close();
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
}
