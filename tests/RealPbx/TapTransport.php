<?php

declare(strict_types=1);

namespace Tests\RealPbx;

use Apn\AmiClient\Core\Contracts\TransportInterface;

final class TapTransport implements TransportInterface
{
    /** @var callable(string): void|null */
    private $upstreamCallback = null;

    /** @var callable(string): void */
    private $tap;

    public function __construct(
        private readonly TransportInterface $inner,
        callable $tap,
    ) {
        $this->tap = $tap;
    }

    public function open(): void
    {
        $this->inner->open();
    }

    public function close(bool $graceful = true): void
    {
        $this->inner->close($graceful);
    }

    public function send(string $data): void
    {
        $this->inner->send($data);
    }

    public function tick(int $timeoutMs = 0): void
    {
        $this->inner->tick($timeoutMs);
    }

    public function onData(callable $callback): void
    {
        $this->upstreamCallback = $callback;
        $tap = $this->tap;

        $this->inner->onData(function (string $data) use ($tap): void {
            $tap($data);

            if ($this->upstreamCallback !== null) {
                ($this->upstreamCallback)($data);
            }
        });
    }

    public function isConnected(): bool
    {
        return $this->inner->isConnected();
    }

    public function getPendingWriteBytes(): int
    {
        return $this->inner->getPendingWriteBytes();
    }

    public function terminate(): void
    {
        $this->inner->terminate();
    }
}
