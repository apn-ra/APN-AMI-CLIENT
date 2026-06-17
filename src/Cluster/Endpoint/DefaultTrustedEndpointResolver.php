<?php

declare(strict_types=1);

namespace Apn\AmiClient\Cluster\Endpoint;

use Apn\AmiClient\Exceptions\AmbiguousEndpointResolutionException;
use Apn\AmiClient\Exceptions\EndpointHostUnresolvableException;
use Apn\AmiClient\Exceptions\ResolvedIpOutsideAllowedCidrException;
use Apn\AmiClient\Exceptions\ResolverUnavailableException;
use Apn\AmiClient\Exceptions\UntrustedEndpointHostException;

/**
 * Secure default {@see TrustedEndpointResolverInterface}.
 *
 * Resolution order (fail-closed at every step):
 *   1. Literal IP in → validate reserved/CIDR → return.
 *   2. Allowlist gate (BEFORE DNS): host must match exact allowlist or pattern,
 *      else {@see UntrustedEndpointHostException} — untrusted names are never
 *      resolved.
 *   3. Resolve via injected DNS callable (defaults to gethostbynamel()).
 *   4. Validate every resolved IP against reserved-range + CIDR policy.
 *   5. Apply the multi-IP policy and return a single validated literal IP.
 *
 * DNS happens at bootstrap/config time only — never inside the tick path. No
 * Docker socket, no shelling out: resolution uses PHP's resolver (or an
 * injected callable, e.g. a DNS test hook).
 */
final class DefaultTrustedEndpointResolver implements TrustedEndpointResolverInterface
{
    /** @var callable(string): array<int, string> */
    private $dnsResolver;

    /**
     * @param (callable(string): array<int, string>)|null $dnsResolver Returns the list of
     *        literal IPs for a hostname. Defaults to a gethostbynamel()-backed
     *        resolver. Injectable for tests (no real network).
     */
    public function __construct(
        private readonly AllowedEndpointHostPolicy $policy,
        ?callable $dnsResolver = null,
    ) {
        $this->dnsResolver = $dnsResolver ?? self::defaultDnsResolver();
    }

    public function resolve(string $host): EndpointResolutionResult
    {
        $host = trim($host);
        if ($host === '') {
            throw UntrustedEndpointHostException::forHost($host);
        }

        // 1. Literal IP short-circuit: validate against reserved + (if any) CIDR
        //    + network/broadcast boundary, consistent with the resolved path.
        if (IpClassifier::isIp($host)) {
            $reason = $this->policy->reserved->rejectionReason($host);
            if ($reason === null && $this->policy->allowedCidrs !== []) {
                if (!$this->ipInAllowedCidr($host)) {
                    $reason = 'outside every allowed CIDR';
                } elseif (!$this->policy->reserved->allowNetworkAndBroadcastAddress && $this->isCidrBoundaryAddress($host)) {
                    $reason = 'is a network or broadcast address of the allowed CIDR';
                }
            }
            if ($reason !== null) {
                throw ResolvedIpOutsideAllowedCidrException::forIp($host, null, $reason, $this->policy->allowedCidrs);
            }

            return new EndpointResolutionResult($host, $host);
        }

        // 2. Allowlist gate BEFORE any DNS lookup.
        if (!$this->policy->isHostTrusted($host)) {
            throw UntrustedEndpointHostException::forHost($host);
        }

        // 3. Resolve.
        $ips = $this->resolveHostToIps($host);
        if ($ips === []) {
            throw EndpointHostUnresolvableException::forHost($host);
        }

        // 4 + 5. Validate and select per multi-IP policy.
        return new EndpointResolutionResult($this->selectValidatedIp($host, $ips), $host);
    }

    /**
     * @return array<int, string>
     */
    private function resolveHostToIps(string $host): array
    {
        $result = ($this->dnsResolver)($host);

        $ips = [];
        foreach ((array) $result as $candidate) {
            $candidate = is_string($candidate) ? trim($candidate) : '';
            if ($candidate !== '' && IpClassifier::isIp($candidate)) {
                $ips[$candidate] = $candidate; // de-duplicate, preserve literal
            }
        }

        return array_values($ips);
    }

    /**
     * @param array<int, string> $ips
     */
    private function selectValidatedIp(string $host, array $ips): string
    {
        if ($this->policy->multiIp === MultiIpPolicy::Reject && count($ips) > 1) {
            throw AmbiguousEndpointResolutionException::forHost(
                $host,
                $ips,
                'multiple IPs returned and multi_ip policy is reject (fail-closed)'
            );
        }

        $valid = [];
        $firstError = null;
        foreach ($ips as $ip) {
            $reason = $this->validateResolvedIp($ip);
            if ($reason === null) {
                $valid[] = $ip;
            } elseif ($firstError === null) {
                $firstError = ResolvedIpOutsideAllowedCidrException::forIp($ip, $host, $reason, $this->policy->allowedCidrs);
            }
        }

        if ($this->policy->multiIp === MultiIpPolicy::DeterministicAllValid) {
            if (count($valid) !== count($ips)) {
                // Mixed valid/invalid set → fail closed.
                throw AmbiguousEndpointResolutionException::forHost(
                    $host,
                    $ips,
                    'resolved to a mixed set of valid and invalid IPs (fail-closed)'
                );
            }

            return $this->lowestAddress($valid);
        }

        // Reject policy with a single IP: surface the precise reason on failure.
        if ($valid === []) {
            throw $firstError ?? ResolvedIpOutsideAllowedCidrException::forIp(
                $ips[0],
                $host,
                'failed reserved-range / CIDR validation',
                $this->policy->allowedCidrs
            );
        }

        return $valid[0];
    }

    /**
     * @return string|null Null when the IP is acceptable, else a rejection reason.
     */
    private function validateResolvedIp(string $ip): ?string
    {
        $reason = $this->policy->reserved->rejectionReason($ip);
        if ($reason !== null) {
            return $reason;
        }

        if ($this->policy->allowedCidrs === []) {
            return 'no allowed CIDR is configured for hostname resolution';
        }

        if (!$this->ipInAllowedCidr($ip)) {
            return 'outside every allowed CIDR';
        }

        if (!$this->policy->reserved->allowNetworkAndBroadcastAddress && $this->isCidrBoundaryAddress($ip)) {
            return 'is a network or broadcast address of the allowed CIDR';
        }

        return null;
    }

    private function ipInAllowedCidr(string $ip): bool
    {
        foreach ($this->policy->allowedCidrs as $cidr) {
            if (CidrMatcher::contains($cidr, $ip)) {
                return true;
            }
        }

        return false;
    }

    private function isCidrBoundaryAddress(string $ip): bool
    {
        foreach ($this->policy->allowedCidrs as $cidr) {
            if (!CidrMatcher::contains($cidr, $ip)) {
                continue;
            }
            if ($ip === CidrMatcher::networkAddress($cidr) || $ip === CidrMatcher::broadcastAddress($cidr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $ips
     */
    private function lowestAddress(array $ips): string
    {
        $sorted = $ips;
        usort($sorted, static function (string $a, string $b): int {
            $binA = (string) @inet_pton($a);
            $binB = (string) @inet_pton($b);
            if (strlen($binA) !== strlen($binB)) {
                return strlen($binA) <=> strlen($binB); // IPv4 before IPv6, deterministic
            }

            return strcmp($binA, $binB);
        });

        return $sorted[0];
    }

    /**
     * @return callable(string): array<int, string>
     */
    private static function defaultDnsResolver(): callable
    {
        return static function (string $host): array {
            if (!function_exists('gethostbynamel')) {
                throw ResolverUnavailableException::forReason('gethostbynamel() is unavailable in this PHP build');
            }

            $ips = @gethostbynamel($host);

            return $ips === false ? [] : $ips;
        };
    }
}
