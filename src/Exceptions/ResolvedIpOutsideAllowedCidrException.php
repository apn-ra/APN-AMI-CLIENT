<?php

declare(strict_types=1);

namespace Apn\AmiClient\Exceptions;

/**
 * Thrown when a resolved (or literal) endpoint IP fails IP-level trust
 * validation: outside every configured allowed CIDR, or inside a denied
 * reserved range (loopback, link-local/metadata, multicast, unspecified,
 * broadcast, public, network/broadcast/gateway address, etc.).
 *
 * Extends {@see InvalidConfigurationException} (BC-safe).
 */
class ResolvedIpOutsideAllowedCidrException extends InvalidConfigurationException
{
    private ?string $endpointHost = null;
    private ?string $resolvedIp = null;
    private ?string $reason = null;

    /**
     * @param string[] $allowedCidrs
     */
    public static function forIp(string $ip, ?string $host, string $reason, array $allowedCidrs = []): self
    {
        $exception = new self(sprintf(
            'Resolved IP "%s"%s rejected: %s.%s',
            $ip,
            $host !== null ? sprintf(' for host "%s"', $host) : '',
            $reason,
            $allowedCidrs !== [] ? ' Allowed CIDRs: ' . implode(', ', $allowedCidrs) . '.' : ''
        ));
        $exception->resolvedIp = $ip;
        $exception->endpointHost = $host;
        $exception->reason = $reason;

        return $exception;
    }

    public function getEndpointHost(): ?string
    {
        return $this->endpointHost;
    }

    public function getResolvedIp(): ?string
    {
        return $this->resolvedIp;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }
}
