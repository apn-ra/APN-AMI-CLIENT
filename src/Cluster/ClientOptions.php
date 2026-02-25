<?php

declare(strict_types=1);

namespace Apn\AmiClient\Cluster;

/**
 * Global configuration DTO for AMI clients.
 */
readonly class ClientOptions
{
    public function __construct(
        public int $connectTimeout = 10,
        public int $writeBufferLimit = 5242880, // 5MB
        public int $maxPendingActions = 5000,
        public int $eventQueueCapacity = 10000,
        /** @var string[] */
        public array $allowedEvents = [],
        /** @var string[] */
        public array $blockedEvents = [],
        public int $memoryLimit = 0,
        public bool $lazy = true,
    ) {}

    /**
     * Create from array.
     */
    public static function fromArray(array $options): self
    {
        return new self(
            connectTimeout: $options['connect_timeout'] ?? 10,
            writeBufferLimit: $options['write_buffer_limit'] ?? 5242880,
            maxPendingActions: $options['max_pending_actions'] ?? 5000,
            eventQueueCapacity: $options['event_queue_capacity'] ?? 10000,
            allowedEvents: $options['allowed_events'] ?? [],
            blockedEvents: $options['blocked_events'] ?? [],
            memoryLimit: $options['memory_limit'] ?? 0,
            lazy: $options['lazy'] ?? true,
        );
    }
}
