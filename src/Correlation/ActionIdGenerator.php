<?php

declare(strict_types=1);

namespace Apn\AmiClient\Correlation;

/**
 * Generates unique ActionIDs following the mandatory format:
 * {server_key}:{instance_id}:{sequence_id}
 */
final class ActionIdGenerator
{
    private const int DEFAULT_MAX_ACTION_ID_LENGTH = 128;
    private const int MIN_MAX_ACTION_ID_LENGTH = 64;
    private const int MAX_MAX_ACTION_ID_LENGTH = 256;

    private int $sequence = 0;
    private readonly string $instanceId;
    private readonly int $maxActionIdLength;

    public function __construct(
        private readonly string $serverKey,
        ?string $instanceId = null,
        int $maxActionIdLength = self::DEFAULT_MAX_ACTION_ID_LENGTH
    ) {
        $this->instanceId = $instanceId ?? bin2hex(random_bytes(4));
        $this->maxActionIdLength = max(
            self::MIN_MAX_ACTION_ID_LENGTH,
            min(self::MAX_MAX_ACTION_ID_LENGTH, $maxActionIdLength)
        );
    }

    /**
     * Generates a new unique ActionID.
     */
    public function next(): string
    {
        $this->sequence++;

        $sequence = (string) $this->sequence;
        $candidate = sprintf('%s:%s:%s', $this->serverKey, $this->instanceId, $sequence);
        if (strlen($candidate) <= $this->maxActionIdLength) {
            return $candidate;
        }

        $segmentBudget = $this->maxActionIdLength - strlen($sequence) - 2; // two separators
        $serverBudget = max(1, intdiv($segmentBudget * 2, 3));
        $instanceBudget = max(1, $segmentBudget - $serverBudget);

        $serverSegment = $this->abbreviateSegment(
            $this->serverKey,
            $serverBudget,
            sprintf('server|%s|%s', $this->serverKey, $this->instanceId)
        );
        $instanceSegment = $this->abbreviateSegment(
            $this->instanceId,
            $instanceBudget,
            sprintf('instance|%s|%s', $this->serverKey, $this->instanceId)
        );

        return sprintf('%s:%s:%s', $serverSegment, $instanceSegment, $sequence);
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

    private function abbreviateSegment(string $value, int $maxLength, string $hashSeed): string
    {
        if (strlen($value) <= $maxLength) {
            return $value;
        }

        $hash = substr(hash('sha256', $hashSeed), 0, 12);
        if ($maxLength <= 13) {
            return substr($hash, 0, $maxLength);
        }

        return substr($value, 0, $maxLength - 13) . '~' . $hash;
    }
}
