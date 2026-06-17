# Public API Summary

All changes are additive. No existing signature was removed; one optional
parameter was appended to two methods, and the base config exception was made
extendable.

## New public types — `Apn\AmiClient\Cluster\Endpoint`
```php
interface TrustedEndpointResolverInterface {
    public function resolve(string $host): EndpointResolutionResult; // throws InvalidConfigurationException subclasses
}

final readonly class EndpointResolutionResult {
    public function __construct(public string $validatedIp, public string $originalHost);
}

final readonly class AllowedEndpointHostPolicy {
    public function __construct(
        array $exactAllowlist = [],
        array $allowlistPatterns = [],   // full PCRE patterns
        array $allowedCidrs = [],
        ReservedRangePolicy $reserved = new ReservedRangePolicy(),
        MultiIpPolicy $multiIp = MultiIpPolicy::Reject,
    );
    public function isHostTrusted(string $host): bool;
    public function hasAnyHostTrust(): bool;
    public static function fromArray(array $config): self; // fail-closed (ResolverUnavailableException)
}

final readonly class ReservedRangePolicy {
    public function __construct(
        bool $allowLoopback = false, bool $allowLinkLocal = false, bool $allowMulticast = false,
        bool $allowUnspecified = false, bool $allowBroadcast = false, bool $allowPrivate = true,
        bool $allowPublic = false, bool $allowNetworkAndBroadcastAddress = false, array $deniedIps = [],
    );
    public function rejectionReason(string $ip): ?string;
    public static function fromArray(array $config): self;
}

enum MultiIpPolicy: string { case Reject = 'reject'; case DeterministicAllValid = 'deterministic_all_valid'; }

final class DefaultTrustedEndpointResolver implements TrustedEndpointResolverInterface {
    public function __construct(AllowedEndpointHostPolicy $policy, ?callable $dnsResolver = null);
}

final class CidrMatcher {     // static utility
    public static function assertValid(string $cidr): void;
    public static function contains(string $cidr, string $ip): bool;
    public static function networkAddress(string $cidr): string;
    public static function broadcastAddress(string $cidr): string;
}

final class IpClassifier {    // static utility, category constants + classify()/isMetadataAddress()
}
```

## New exceptions — `Apn\AmiClient\Exceptions` (all `extends InvalidConfigurationException`)
- `UntrustedEndpointHostException` (`getEndpointHost()`)
- `EndpointHostUnresolvableException` (`getEndpointHost()`)
- `ResolvedIpOutsideAllowedCidrException` (`getResolvedIp()`, `getEndpointHost()`, `getReason()`)
- `AmbiguousEndpointResolutionException` (`getEndpointHost()`, `getResolvedIps()`)
- `ResolverUnavailableException`

## Changed signatures (additive only)
```php
// AmiClientManager::__construct(... , ?callable $signalHandler = null,
//                                       ?TrustedEndpointResolverInterface $trustedResolver = null)

// ConfigLoader::load(array $config, ?LoggerInterface $logger = null,
//                    ?callable $hostnameResolver = null,                 // legacy, kept (BC)
//                    ?TrustedEndpointResolverInterface $trustedResolver = null,
//                    ?callable $dnsResolver = null)
```

## Changed visibility
- `InvalidConfigurationException`: `final class` → `class` (now extendable). All factory methods/getters
  unchanged.

## New config key (opt-in)
`config['trusted_endpoint_policy']` (array): `exact_allowlist`, `allowlist_patterns`, `allowed_cidrs`,
`multi_ip`, `reserved{...}`. Absent by default → strict literal-IP-only.
