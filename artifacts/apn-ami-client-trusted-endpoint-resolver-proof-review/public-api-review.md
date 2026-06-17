# Public API Review

## Findings: additive and BC-safe. Verified against source.

### New public surface — `Apn\AmiClient\Cluster\Endpoint`
- `interface TrustedEndpointResolverInterface { resolve(string $host): EndpointResolutionResult }` — confirmed
  (line 18/24).
- `final readonly class EndpointResolutionResult { string $validatedIp; string $originalHost }` — confirmed.
- `final readonly class AllowedEndpointHostPolicy` — ctor `(array exactAllowlist, array allowlistPatterns,
  array allowedCidrs, ReservedRangePolicy reserved, MultiIpPolicy multiIp)`; `isHostTrusted()`,
  `hasAnyHostTrust()`, `fromArray()` (fail-closed). Confirmed.
- `final readonly class ReservedRangePolicy` — per-category allow flags + `deniedIps`; secure defaults
  (only `allowPrivate=true`). `rejectionReason()`, `fromArray()`. Confirmed.
- `enum MultiIpPolicy: string { Reject, DeterministicAllValid }` — confirmed.
- `final class DefaultTrustedEndpointResolver implements TrustedEndpointResolverInterface` — ctor
  `(AllowedEndpointHostPolicy, ?callable dnsResolver)`. Confirmed.
- `final class CidrMatcher` (static: `assertValid/contains/networkAddress/broadcastAddress`),
  `final class IpClassifier` (static classify + category constants). Confirmed.

### New exceptions — `Apn\AmiClient\Exceptions` (all `extends InvalidConfigurationException`)
`UntrustedEndpointHostException`, `EndpointHostUnresolvableException`,
`ResolvedIpOutsideAllowedCidrException`, `AmbiguousEndpointResolutionException`,
`ResolverUnavailableException` — all five confirmed extending the base.

### Changed signatures (additive trailing optionals only)
- `AmiClientManager::__construct(... ?callable $signalHandler = null, ?TrustedEndpointResolverInterface
  $trustedResolver = null)` — old call sites unaffected (new arg defaults null).
- `ConfigLoader::load(array $config, ?LoggerInterface $logger = null, ?callable $hostnameResolver = null,
  ?TrustedEndpointResolverInterface $trustedResolver = null, ?callable $dnsResolver = null)` — old 1–3 arg
  call sites unaffected; legacy `$hostnameResolver` retained.

### Visibility change
- `InvalidConfigurationException`: `final class` → `class`. Non-breaking (see exception-classification-review).

### New config key
- `config['trusted_endpoint_policy']` — optional; absent ⇒ strict literal-IP-only.

## Conclusion
No removed/renamed symbols; no required-parameter additions; no narrowed types. Old call sites compile and
behave identically when no policy/resolver is supplied. **API review: PASS.**
