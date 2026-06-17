<?php

declare(strict_types=1);

namespace Apn\AmiClient\Cluster\Endpoint;

/**
 * Controls how a hostname resolving to more than one IP is handled. Default is
 * fail-closed: more than one resolved IP is treated as ambiguous unless the
 * policy explicitly opts into deterministic selection of an all-valid set.
 */
enum MultiIpPolicy: string
{
    /**
     * Strict, fail-closed. More than one resolved IP → ambiguous, rejected.
     */
    case Reject = 'reject';

    /**
     * Permit multiple IPs ONLY when every resolved IP passes validation; then
     * pick one deterministically (lowest binary address). If any IP is invalid
     * the whole resolution is rejected (fail-closed).
     */
    case DeterministicAllValid = 'deterministic_all_valid';

    public static function fromString(string $value): self
    {
        return self::tryFrom($value)
            ?? throw new \Apn\AmiClient\Exceptions\InvalidConfigurationException(sprintf(
                'Invalid multi_ip policy "%s"; expected one of: %s.',
                $value,
                implode(', ', array_map(static fn (self $c): string => $c->value, self::cases()))
            ));
    }
}
