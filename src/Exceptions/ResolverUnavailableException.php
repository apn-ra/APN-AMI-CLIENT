<?php

declare(strict_types=1);

namespace Apn\AmiClient\Exceptions;

/**
 * Thrown when a trusted-endpoint policy is declared but no usable resolver can
 * be established (policy declares no allowlist and no patterns, or the
 * underlying DNS mechanism is unavailable). Guards the unsafe state where a
 * consumer believes trusted-hostname mode is active but nothing actually
 * enforces trust.
 *
 * Extends {@see InvalidConfigurationException} (BC-safe).
 */
class ResolverUnavailableException extends InvalidConfigurationException
{
    public static function forReason(string $reason): self
    {
        return new self(sprintf(
            'Trusted endpoint resolver is unavailable: %s.',
            $reason
        ));
    }
}
