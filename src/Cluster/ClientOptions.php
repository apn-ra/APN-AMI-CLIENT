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
        public int $readTimeout = 30,
        public int $heartbeatInterval = 15,
        public int $circuitFailureThreshold = 5,
        public int $circuitCooldown = 30,
        public int $circuitHalfOpenMaxProbes = 1,
        public int $writeBufferLimit = 5242880, // 5MB
        public int $maxPendingActions = 5000,
        public int $eventQueueCapacity = 10000,
        /** @var string[] */
        public array $allowedEvents = [],
        /** @var string[] */
        public array $blockedEvents = [],
        /** @var string[] */
        public array $redactionKeys = [],
        /** @var string[] */
        public array $redactionKeyPatterns = [],
        /** @var string[] */
        public array $redactionValuePatterns = [],
        public int $memoryLimit = 0,
        public int $maxFramesPerTick = 1000,
        public int $maxEventsPerTick = 1000,
        public int $maxBytesReadPerTick = 1048576, // 1MB
        public int $maxFrameSize = 1048576, // 1MB
        public int $maxActionIdLength = 128,
        public int $maxConnectAttemptsPerTick = 5,
        public bool $enforceIpEndpoints = true,
        public bool $lazy = true,
    ) {}

    /**
     * Create from array.
     */
    public static function fromArray(array $options): self
    {
        return new self(
            connectTimeout: $options['connect_timeout'] ?? 10,
            readTimeout: $options['read_timeout'] ?? 30,
            heartbeatInterval: $options['heartbeat_interval'] ?? 15,
            circuitFailureThreshold: $options['circuit_failure_threshold'] ?? 5,
            circuitCooldown: $options['circuit_cooldown'] ?? 30,
            circuitHalfOpenMaxProbes: $options['circuit_half_open_max_probes'] ?? 1,
            writeBufferLimit: $options['write_buffer_limit'] ?? 5242880,
            maxPendingActions: $options['max_pending_actions'] ?? 5000,
            eventQueueCapacity: $options['event_queue_capacity'] ?? 10000,
            allowedEvents: $options['allowed_events'] ?? [],
            blockedEvents: $options['blocked_events'] ?? [],
            redactionKeys: $options['redaction_keys'] ?? [],
            redactionKeyPatterns: $options['redaction_key_patterns'] ?? [],
            redactionValuePatterns: $options['redaction_value_patterns'] ?? [],
            memoryLimit: $options['memory_limit'] ?? 0,
            maxFramesPerTick: $options['max_frames_per_tick'] ?? 1000,
            maxEventsPerTick: $options['max_events_per_tick'] ?? 1000,
            maxBytesReadPerTick: $options['max_bytes_read_per_tick'] ?? 1048576,
            maxFrameSize: $options['max_frame_size'] ?? 1048576,
            maxActionIdLength: $options['max_action_id_length'] ?? 128,
            maxConnectAttemptsPerTick: $options['max_connect_attempts_per_tick'] ?? 5,
            enforceIpEndpoints: $options['enforce_ip_endpoints'] ?? true,
            lazy: $options['lazy'] ?? true,
        );
    }
}
