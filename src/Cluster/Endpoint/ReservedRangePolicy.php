<?php

declare(strict_types=1);

namespace Apn\AmiClient\Cluster\Endpoint;

use Apn\AmiClient\Exceptions\InvalidConfigurationException;

/**
 * Reserved / dangerous IP-range policy applied to every resolved (or literal)
 * endpoint IP. Defaults are secure: only RFC1918 / ULA private addresses are
 * permitted (the expected control-plane case). Loopback, link-local (incl.
 * cloud metadata 169.254.169.254), multicast, unspecified, broadcast and
 * public addresses are denied unless an operator explicitly opts in (mainly
 * for tests).
 *
 * This is defense-in-depth layered on top of the CIDR allowlist: even an IP
 * inside an allowed CIDR is rejected if its category is denied here.
 */
final readonly class ReservedRangePolicy
{
    /**
     * @param string[] $deniedIps Explicit literal IPs to always reject
     *                            (e.g. bridge/gateway addresses).
     */
    public function __construct(
        public bool $allowLoopback = false,
        public bool $allowLinkLocal = false,
        public bool $allowMulticast = false,
        public bool $allowUnspecified = false,
        public bool $allowBroadcast = false,
        public bool $allowPrivate = true,
        public bool $allowPublic = false,
        public bool $allowNetworkAndBroadcastAddress = false,
        public array $deniedIps = [],
    ) {
        foreach ($this->deniedIps as $ip) {
            if (!IpClassifier::isIp((string) $ip)) {
                throw new InvalidConfigurationException(sprintf(
                    'Invalid reserved-range policy: denied_ips entry "%s" is not a valid IP.',
                    (string) $ip
                ));
            }
        }
    }

    /**
     * @return string|null Null when the IP's category is permitted, otherwise a
     *                     short human-readable reason for rejection.
     */
    public function rejectionReason(string $ip): ?string
    {
        foreach ($this->deniedIps as $denied) {
            if ($ip === $denied) {
                return 'matches an explicitly denied address (gateway/bridge/reserved)';
            }
        }

        $category = IpClassifier::classify($ip);

        return match ($category) {
            IpClassifier::LOOPBACK => $this->allowLoopback ? null : 'loopback addresses are not allowed',
            IpClassifier::LINK_LOCAL => $this->allowLinkLocal
                ? null
                : (IpClassifier::isMetadataAddress($ip)
                    ? 'cloud metadata / link-local addresses are not allowed'
                    : 'link-local addresses are not allowed'),
            IpClassifier::MULTICAST => $this->allowMulticast ? null : 'multicast addresses are not allowed',
            IpClassifier::UNSPECIFIED => $this->allowUnspecified ? null : 'unspecified addresses are not allowed',
            IpClassifier::BROADCAST => $this->allowBroadcast ? null : 'broadcast addresses are not allowed',
            IpClassifier::PRIVATE => $this->allowPrivate ? null : 'private addresses are not allowed',
            IpClassifier::PUBLIC => $this->allowPublic ? null : 'public addresses are not allowed',
            default => 'reserved addresses are not allowed',
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        /** @var string[] $deniedIps */
        $deniedIps = array_values(array_map('strval', (array) ($config['denied_ips'] ?? [])));

        return new self(
            allowLoopback: (bool) ($config['allow_loopback'] ?? false),
            allowLinkLocal: (bool) ($config['allow_link_local'] ?? false),
            allowMulticast: (bool) ($config['allow_multicast'] ?? false),
            allowUnspecified: (bool) ($config['allow_unspecified'] ?? false),
            allowBroadcast: (bool) ($config['allow_broadcast'] ?? false),
            allowPrivate: (bool) ($config['allow_private'] ?? true),
            allowPublic: (bool) ($config['allow_public'] ?? false),
            allowNetworkAndBroadcastAddress: (bool) ($config['allow_network_and_broadcast_address'] ?? false),
            deniedIps: $deniedIps,
        );
    }
}
