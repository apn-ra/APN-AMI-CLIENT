<?php

declare(strict_types=1);

namespace Apn\AmiClient\Exceptions;

/**
 * Thrown when a trusted hostname resolves to multiple IP addresses and the
 * configured multi-IP policy cannot deterministically and safely select one
 * (e.g. more than one IP under the strict reject policy, or a mixed
 * valid/invalid set). Fail-closed by design.
 *
 * Extends {@see InvalidConfigurationException} (BC-safe).
 */
class AmbiguousEndpointResolutionException extends InvalidConfigurationException
{
    private ?string $endpointHost = null;
    /** @var string[] */
    private array $resolvedIps = [];

    /**
     * @param string[] $resolvedIps
     */
    public static function forHost(string $host, array $resolvedIps, string $reason): self
    {
        $exception = new self(sprintf(
            'Endpoint host "%s" resolved ambiguously (%d IPs): %s.',
            $host,
            count($resolvedIps),
            $reason
        ));
        $exception->endpointHost = $host;
        $exception->resolvedIps = array_values($resolvedIps);

        return $exception;
    }

    public function getEndpointHost(): ?string
    {
        return $this->endpointHost;
    }

    /**
     * @return string[]
     */
    public function getResolvedIps(): array
    {
        return $this->resolvedIps;
    }
}
