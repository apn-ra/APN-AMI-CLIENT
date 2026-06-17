<?php

declare(strict_types=1);

namespace Apn\AmiClient\Exceptions;

/**
 * Thrown when a trusted (allowlisted) endpoint hostname cannot be resolved to
 * any IP address (no A/AAAA record, or the resolver returned nothing).
 *
 * Extends {@see InvalidConfigurationException} (BC-safe).
 */
class EndpointHostUnresolvableException extends InvalidConfigurationException
{
    private ?string $endpointHost = null;

    public static function forHost(string $host): self
    {
        $exception = new self(sprintf(
            'Endpoint host "%s" is trusted but could not be resolved to any IP address.',
            $host
        ));
        $exception->endpointHost = $host;

        return $exception;
    }

    public function getEndpointHost(): ?string
    {
        return $this->endpointHost;
    }
}
