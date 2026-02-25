<?php

declare(strict_types=1);

namespace Apn\AmiClient\Correlation;

/**
 * Generates unique ActionIDs following the mandatory format:
 * {server_key}:{instance_id}:{sequence_id}
 */
final class ActionIdGenerator
{
    private int $sequence = 0;
    private readonly string $instanceId;

    public function __construct(
        private readonly string $serverKey,
        ?string $instanceId = null
    ) {
        $this->instanceId = $instanceId ?? bin2hex(random_bytes(4));
    }

    /**
     * Generates a new unique ActionID.
     */
    public function next(): string
    {
        $this->sequence++;
        
        return sprintf(
            '%s:%s:%d',
            $this->serverKey,
            $this->instanceId,
            $this->sequence
        );
    }

    /**
     * Returns the server key this generator is associated with.
     */
    public function getServerKey(): string
    {
        return $this->serverKey;
    }

    /**
     * Returns the instance ID of this generator.
     */
    public function getInstanceId(): string
    {
        return $this->instanceId;
    }
}
