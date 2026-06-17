<?php

declare(strict_types=1);

namespace Apn\AmiClient\Exceptions;

/**
 * Thrown when an endpoint hostname does not match the trusted allowlist
 * (exact entries or patterns). Raised BEFORE any DNS lookup occurs, so an
 * untrusted name is never resolved.
 *
 * Extends {@see InvalidConfigurationException} so existing
 * `catch (InvalidConfigurationException)` blocks keep working (BC-safe).
 */
class UntrustedEndpointHostException extends InvalidConfigurationException
{
    private ?string $endpointHost = null;

    public static function forHost(string $host): self
    {
        $exception = new self(sprintf(
            'Endpoint host "%s" is not trusted: it matches no exact allowlist entry or trusted pattern.',
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
