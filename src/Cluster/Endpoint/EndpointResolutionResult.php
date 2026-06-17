<?php

declare(strict_types=1);

namespace Apn\AmiClient\Cluster\Endpoint;

/**
 * Outcome of a successful trusted endpoint resolution.
 *
 * The connection layer MUST use {@see $validatedIp} (always a literal IP that
 * passed allowlist + reserved-range + CIDR validation). {@see $originalHost}
 * is retained for diagnostics/logging only and MUST NOT be used to open a
 * socket.
 */
final readonly class EndpointResolutionResult
{
    public function __construct(
        public string $validatedIp,
        public string $originalHost,
    ) {}
}
