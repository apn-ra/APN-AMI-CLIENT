<?php

declare(strict_types=1);

namespace Apn\AmiClient\Transport;

/**
 * Orchestrates stream_select across multiple TcpTransports.
 * Enforces Guideline 2: Stream Select Ownership.
 */
class Reactor
{
    /** @var array<string, TcpTransport> */
    private array $transports = [];

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
     * @param int $timeoutMs Timeout for stream_select in milliseconds.
     */
    public function tick(int $timeoutMs = 0): void
    {
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
            $read[] = $resource;
            $idToKey[$id] = $key;

            if ($transport->hasPendingWrites()) {
                $write[] = $resource;
            }
        }

        if (empty($read) && empty($write)) {
            return;
        }

        $seconds = (int) ($timeoutMs / 1000);
        $microseconds = ($timeoutMs % 1000) * 1000;

        // Guideline 2: The event loop or a dedicated Reactor component must own the stream_select call.
        $ready = @stream_select($read, $write, $except, $seconds, $microseconds);

        if ($ready === false || $ready === 0) {
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
                // Ensure it's still connected and still has pending writes.
                if ($transport->isConnected() && $transport->hasPendingWrites()) {
                    $transport->flush();
                }
            }
        }
    }
}
