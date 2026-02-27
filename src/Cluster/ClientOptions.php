<?php

declare(strict_types=1);

namespace Apn\AmiClient\Cluster;

use Apn\AmiClient\Core\SecretRedactor;
use Apn\AmiClient\Exceptions\InvalidConfigurationException;

/**
 * Global configuration DTO for AMI clients.
 */
readonly class ClientOptions
{
    public const int MIN_FRAME_SIZE = 65536;
    public const int MAX_FRAME_SIZE = 4194304;
    public const int MIN_ACTION_ID_LENGTH = 64;
    public const int MAX_ACTION_ID_LENGTH = 256;

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
        public int $eventDropLogIntervalMs = 1000,
        public int $maxBytesReadPerTick = 1048576, // 1MB
        public int $maxFrameSize = 1048576, // 1MB
        public int $maxActionIdLength = 128,
        public int $maxConnectAttemptsPerTick = 5,
        public bool $enforceIpEndpoints = true,
        public bool $lazy = true,
    ) {
        $this->validate();
    }

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
            eventDropLogIntervalMs: $options['event_drop_log_interval_ms'] ?? 1000,
            maxBytesReadPerTick: $options['max_bytes_read_per_tick'] ?? 1048576,
            maxFrameSize: $options['max_frame_size'] ?? 1048576,
            maxActionIdLength: $options['max_action_id_length'] ?? 128,
            maxConnectAttemptsPerTick: $options['max_connect_attempts_per_tick'] ?? 5,
            enforceIpEndpoints: $options['enforce_ip_endpoints'] ?? true,
            lazy: $options['lazy'] ?? true,
        );
    }

    public function createRedactor(): SecretRedactor
    {
        return new SecretRedactor(
            $this->redactionKeys,
            $this->redactionKeyPatterns,
            $this->redactionValuePatterns
        );
    }

    private function validate(): void
    {
        self::assertRange('connect_timeout', $this->connectTimeout, 1);
        self::assertRange('read_timeout', $this->readTimeout, 1);
        self::assertRange('heartbeat_interval', $this->heartbeatInterval, 1);
        self::assertRange('circuit_failure_threshold', $this->circuitFailureThreshold, 1);
        self::assertRange('circuit_cooldown', $this->circuitCooldown, 1);
        self::assertRange('circuit_half_open_max_probes', $this->circuitHalfOpenMaxProbes, 1);
        self::assertRange('write_buffer_limit', $this->writeBufferLimit, 1);
        self::assertRange('max_pending_actions', $this->maxPendingActions, 1);
        self::assertRange('event_queue_capacity', $this->eventQueueCapacity, 1);
        self::assertRange('memory_limit', $this->memoryLimit, 0);
        self::assertRange('max_frames_per_tick', $this->maxFramesPerTick, 1);
        self::assertRange('max_events_per_tick', $this->maxEventsPerTick, 1);
        self::assertRange('event_drop_log_interval_ms', $this->eventDropLogIntervalMs, 1);
        self::assertRange('max_bytes_read_per_tick', $this->maxBytesReadPerTick, 1);
        self::assertRange('max_frame_size', $this->maxFrameSize, self::MIN_FRAME_SIZE, self::MAX_FRAME_SIZE);
        self::assertRange('max_action_id_length', $this->maxActionIdLength, self::MIN_ACTION_ID_LENGTH, self::MAX_ACTION_ID_LENGTH);
        self::assertRange('max_connect_attempts_per_tick', $this->maxConnectAttemptsPerTick, 1);
    }

    private static function assertRange(string $key, int $value, int $min, ?int $max = null): void
    {
        if ($value < $min || ($max !== null && $value > $max)) {
            if ($max === null) {
                throw new InvalidConfigurationException(sprintf(
                    'Invalid configuration: %s=%d must be >= %d.',
                    $key,
                    $value,
                    $min
                ));
            }

            throw new InvalidConfigurationException(sprintf(
                'Invalid configuration: %s=%d must be between %d and %d.',
                $key,
                $value,
                $min,
                $max
            ));
        }
    }
}
