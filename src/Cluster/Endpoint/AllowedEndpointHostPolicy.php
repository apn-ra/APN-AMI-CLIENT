<?php

declare(strict_types=1);

namespace Apn\AmiClient\Cluster\Endpoint;

use Apn\AmiClient\Exceptions\InvalidConfigurationException;
use Apn\AmiClient\Exceptions\ResolverUnavailableException;

/**
 * Immutable trust policy consumed by {@see DefaultTrustedEndpointResolver}.
 *
 * A hostname is trusted only if it matches an exact allowlist entry or one of
 * the configured patterns (provider-node aliases such as
 * `apntalk-asterisk-node-a-app`). A resolved IP is accepted only if it falls
 * inside one of {@see $allowedCidrs} and passes {@see $reserved}.
 */
final readonly class AllowedEndpointHostPolicy
{
    /**
     * @param string[] $exactAllowlist    Exact trusted hostnames.
     * @param string[] $allowlistPatterns PCRE patterns (full delimiters), matched against the host.
     * @param string[] $allowedCidrs      CIDRs a resolved IP must fall within.
     */
    public function __construct(
        public array $exactAllowlist = [],
        public array $allowlistPatterns = [],
        public array $allowedCidrs = [],
        public ReservedRangePolicy $reserved = new ReservedRangePolicy(),
        public MultiIpPolicy $multiIp = MultiIpPolicy::Reject,
    ) {
        foreach ($this->allowlistPatterns as $pattern) {
            if (@preg_match($pattern, '') === false) {
                throw new InvalidConfigurationException(sprintf(
                    'Invalid trusted endpoint pattern "%s": not a valid PCRE pattern.',
                    $pattern
                ));
            }
        }

        foreach ($this->allowedCidrs as $cidr) {
            CidrMatcher::assertValid($cidr);
        }
    }

    /**
     * True when the host matches an exact allowlist entry or a trusted pattern.
     * Comparison is case-insensitive for exact entries (DNS names are
     * case-insensitive); patterns control their own casing.
     */
    public function isHostTrusted(string $host): bool
    {
        foreach ($this->exactAllowlist as $allowed) {
            if (strcasecmp($host, (string) $allowed) === 0) {
                return true;
            }
        }

        foreach ($this->allowlistPatterns as $pattern) {
            if (preg_match($pattern, $host) === 1) {
                return true;
            }
        }

        return false;
    }

    public function hasAnyHostTrust(): bool
    {
        return $this->exactAllowlist !== [] || $this->allowlistPatterns !== [];
    }

    /**
     * Builds a policy from a config array. Throws {@see ResolverUnavailableException}
     * when the policy could not establish any host trust (no allowlist and no
     * patterns) — guarding the unsafe state where trusted-hostname mode is
     * declared but nothing would actually be trusted.
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        /** @var string[] $exact */
        $exact = array_values(array_map('strval', (array) ($config['exact_allowlist'] ?? [])));
        /** @var string[] $patterns */
        $patterns = array_values(array_map('strval', (array) ($config['allowlist_patterns'] ?? [])));
        /** @var string[] $cidrs */
        $cidrs = array_values(array_map('strval', (array) ($config['allowed_cidrs'] ?? [])));

        $multiIp = isset($config['multi_ip'])
            ? MultiIpPolicy::fromString((string) $config['multi_ip'])
            : MultiIpPolicy::Reject;

        $reserved = isset($config['reserved']) && is_array($config['reserved'])
            ? ReservedRangePolicy::fromArray($config['reserved'])
            : new ReservedRangePolicy();

        $policy = new self(
            exactAllowlist: $exact,
            allowlistPatterns: $patterns,
            allowedCidrs: $cidrs,
            reserved: $reserved,
            multiIp: $multiIp,
        );

        if (!$policy->hasAnyHostTrust()) {
            throw ResolverUnavailableException::forReason(
                'trusted_endpoint_policy declares neither exact_allowlist nor allowlist_patterns'
            );
        }

        if ($policy->allowedCidrs === []) {
            throw ResolverUnavailableException::forReason(
                'trusted_endpoint_policy declares no allowed_cidrs, so no resolved hostname IP could be accepted'
            );
        }

        return $policy;
    }
}
