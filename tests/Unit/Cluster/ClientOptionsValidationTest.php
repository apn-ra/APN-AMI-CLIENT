<?php

declare(strict_types=1);

namespace Tests\Unit\Cluster;

use Apn\AmiClient\Cluster\ClientOptions;
use Apn\AmiClient\Exceptions\InvalidConfigurationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientOptionsValidationTest extends TestCase
{
    #[DataProvider('invalidOptionsProvider')]
    public function testInvalidOptionsThrow(string $key, int $value, string $expectedMessage): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($expectedMessage);

        $options = $this->defaults();
        $options[$key] = $value;
        $this->assertInstanceOf(ClientOptions::class, $this->buildOptions($options));
    }

    public static function invalidOptionsProvider(): array
    {
        return [
            ['connectTimeout', 0, 'connect_timeout=0'],
            ['readTimeout', 0, 'read_timeout=0'],
            ['heartbeatInterval', 0, 'heartbeat_interval=0'],
            ['circuitFailureThreshold', 0, 'circuit_failure_threshold=0'],
            ['circuitCooldown', 0, 'circuit_cooldown=0'],
            ['circuitHalfOpenMaxProbes', 0, 'circuit_half_open_max_probes=0'],
            ['writeBufferLimit', 0, 'write_buffer_limit=0'],
            ['maxPendingActions', 0, 'max_pending_actions=0'],
            ['eventQueueCapacity', 0, 'event_queue_capacity=0'],
            ['memoryLimit', -1, 'memory_limit=-1'],
            ['maxFramesPerTick', 0, 'max_frames_per_tick=0'],
            ['maxEventsPerTick', 0, 'max_events_per_tick=0'],
            ['eventDropLogIntervalMs', 0, 'event_drop_log_interval_ms=0'],
            ['maxBytesReadPerTick', 0, 'max_bytes_read_per_tick=0'],
            ['maxFrameSize', ClientOptions::MIN_FRAME_SIZE - 1, 'max_frame_size=' . (ClientOptions::MIN_FRAME_SIZE - 1)],
            ['maxFrameSize', ClientOptions::MAX_FRAME_SIZE + 1, 'max_frame_size=' . (ClientOptions::MAX_FRAME_SIZE + 1)],
            ['maxActionIdLength', ClientOptions::MIN_ACTION_ID_LENGTH - 1, 'max_action_id_length=' . (ClientOptions::MIN_ACTION_ID_LENGTH - 1)],
            ['maxActionIdLength', ClientOptions::MAX_ACTION_ID_LENGTH + 1, 'max_action_id_length=' . (ClientOptions::MAX_ACTION_ID_LENGTH + 1)],
            ['maxConnectAttemptsPerTick', 0, 'max_connect_attempts_per_tick=0'],
        ];
    }

    public function testBoundaryValuesAreAccepted(): void
    {
        $options = $this->defaults();
        $options['connectTimeout'] = 1;
        $options['readTimeout'] = 1;
        $options['heartbeatInterval'] = 1;
        $options['circuitFailureThreshold'] = 1;
        $options['circuitCooldown'] = 1;
        $options['circuitHalfOpenMaxProbes'] = 1;
        $options['writeBufferLimit'] = 1;
        $options['maxPendingActions'] = 1;
        $options['eventQueueCapacity'] = 1;
        $options['memoryLimit'] = 0;
        $options['maxFramesPerTick'] = 1;
        $options['maxEventsPerTick'] = 1;
        $options['eventDropLogIntervalMs'] = 1;
        $options['maxBytesReadPerTick'] = 1;
        $options['maxFrameSize'] = ClientOptions::MIN_FRAME_SIZE;
        $options['maxActionIdLength'] = ClientOptions::MIN_ACTION_ID_LENGTH;
        $options['maxConnectAttemptsPerTick'] = 1;

        $this->buildOptions($options);

        $options['maxFrameSize'] = ClientOptions::MAX_FRAME_SIZE;
        $options['maxActionIdLength'] = ClientOptions::MAX_ACTION_ID_LENGTH;
        $this->assertInstanceOf(ClientOptions::class, $this->buildOptions($options));
    }

    private function defaults(): array
    {
        return [
            'connectTimeout' => 10,
            'readTimeout' => 30,
            'heartbeatInterval' => 15,
            'circuitFailureThreshold' => 5,
            'circuitCooldown' => 30,
            'circuitHalfOpenMaxProbes' => 1,
            'writeBufferLimit' => 5242880,
            'maxPendingActions' => 5000,
            'eventQueueCapacity' => 10000,
            'memoryLimit' => 0,
            'maxFramesPerTick' => 1000,
            'maxEventsPerTick' => 1000,
            'eventDropLogIntervalMs' => 1000,
            'maxBytesReadPerTick' => 1048576,
            'maxFrameSize' => 1048576,
            'maxActionIdLength' => 128,
            'maxConnectAttemptsPerTick' => 5,
        ];
    }

    private function buildOptions(array $options): ClientOptions
    {
        return new ClientOptions(
            connectTimeout: $options['connectTimeout'],
            readTimeout: $options['readTimeout'],
            heartbeatInterval: $options['heartbeatInterval'],
            circuitFailureThreshold: $options['circuitFailureThreshold'],
            circuitCooldown: $options['circuitCooldown'],
            circuitHalfOpenMaxProbes: $options['circuitHalfOpenMaxProbes'],
            writeBufferLimit: $options['writeBufferLimit'],
            maxPendingActions: $options['maxPendingActions'],
            eventQueueCapacity: $options['eventQueueCapacity'],
            memoryLimit: $options['memoryLimit'],
            maxFramesPerTick: $options['maxFramesPerTick'],
            maxEventsPerTick: $options['maxEventsPerTick'],
            eventDropLogIntervalMs: $options['eventDropLogIntervalMs'],
            maxBytesReadPerTick: $options['maxBytesReadPerTick'],
            maxFrameSize: $options['maxFrameSize'],
            maxActionIdLength: $options['maxActionIdLength'],
            maxConnectAttemptsPerTick: $options['maxConnectAttemptsPerTick'],
        );
    }
}
