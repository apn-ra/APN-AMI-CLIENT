<?php

declare(strict_types=1);

namespace Apn\AmiClient\Events;

use Apn\AmiClient\Protocol\Event;

/**
 * Normalized AMI Event with metadata.
 */
readonly class AmiEvent
{
    public function __construct(
        public Event $event,
        public string $serverKey,
        public float $receivedAt
    ) {
    }

    /**
     * Creates a new AmiEvent with the current timestamp.
     */
    public static function create(Event $event, string $serverKey): self
    {
        return new self($event, $serverKey, microtime(true));
    }

    /**
     * Proxies header access to the underlying event.
     */
    public function getHeader(string $key): string|array|null
    {
        return $this->event->getHeader($key);
    }

    /**
     * Proxies name access to the underlying event.
     */
    public function getName(): string
    {
        return $this->event->getName();
    }
}
