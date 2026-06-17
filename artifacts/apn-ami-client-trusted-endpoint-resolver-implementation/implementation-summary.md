# Implementation Summary

Authority A — package-owned trusted endpoint resolver. Static IP remains a
fallback, not the primary mechanism. Everything is additive and fail-closed.

## New namespace: `Apn\AmiClient\Cluster\Endpoint`
- **`TrustedEndpointResolverInterface`** — `resolve(string $host): EndpointResolutionResult`.
  Contract: fail-closed; any uncertainty throws an `InvalidConfigurationException` subclass.
- **`EndpointResolutionResult`** (readonly) — `validatedIp` (used to connect) + `originalHost`
  (diagnostics only; never used to open a socket).
- **`AllowedEndpointHostPolicy`** (readonly) — exact allowlist, PCRE alias patterns, allowed CIDRs,
  `ReservedRangePolicy`, `MultiIpPolicy`. Validates patterns/CIDRs at construction.
  `fromArray()` is fail-closed: a policy with no host trust or no CIDR throws `ResolverUnavailableException`.
- **`ReservedRangePolicy`** (readonly) — per-category allow flags (loopback/link-local/multicast/
  unspecified/broadcast/private/public), explicit `deniedIps`, and network/broadcast-boundary control.
  Secure defaults: only private allowed; everything else denied.
- **`MultiIpPolicy`** (enum) — `Reject` (default, fail-closed: >1 IP is ambiguous) and
  `DeterministicAllValid` (all IPs must validate, then lowest is chosen deterministically).
- **`DefaultTrustedEndpointResolver`** — secure default implementation (algorithm below). DNS callable is
  injectable (defaults to `gethostbynamel()`), so tests run fully offline.
- **`CidrMatcher`** — pure IPv4/IPv6 CIDR membership + network/broadcast address computation (inet_pton).
- **`IpClassifier`** — offline IP categorisation (loopback/link-local/metadata/multicast/unspecified/
  broadcast/private/reserved/public).

## Resolution algorithm (`DefaultTrustedEndpointResolver::resolve`)
1. **Literal IP in** → validate reserved-range + (if configured) CIDR + network/broadcast boundary → return.
2. **Allowlist gate BEFORE DNS** — host must match exact allowlist or a pattern, else
   `UntrustedEndpointHostException` (untrusted names are never resolved).
3. **Resolve** via the injected DNS callable; empty → `EndpointHostUnresolvableException`.
4. **Validate every resolved IP** — reserved-range deny + must be inside an allowed CIDR + not a
   network/broadcast boundary (unless explicitly allowed).
5. **Multi-IP policy** — `Reject`: >1 IP → `AmbiguousEndpointResolutionException`; `DeterministicAllValid`:
   any invalid IP → `AmbiguousEndpointResolutionException`, else lowest valid IP deterministically.

## New exceptions (`Apn\AmiClient\Exceptions`, all extend `InvalidConfigurationException`)
`UntrustedEndpointHostException`, `EndpointHostUnresolvableException`,
`ResolvedIpOutsideAllowedCidrException`, `AmbiguousEndpointResolutionException`,
`ResolverUnavailableException`. Base `InvalidConfigurationException` changed from `final` to `class`.

## Wiring
- **`AmiClientManager`** — new optional ctor arg `?TrustedEndpointResolverInterface $trustedResolver`.
  `resolveHost()`: literal IP → unchanged; non-IP + resolver installed → `resolver->resolve()->validatedIp`
  (works under enforce=true); non-IP + no resolver + enforce=true → unchanged rejection; non-IP + no
  resolver + enforce=false → legacy callable path (BC). `validateHostnamePolicy()` defers to the resolver
  when one is installed.
- **`ConfigLoader::load()`** — new optional `?TrustedEndpointResolverInterface $trustedResolver` and
  `?callable $dnsResolver`. If no explicit resolver and `config['trusted_endpoint_policy']` is present,
  builds a `DefaultTrustedEndpointResolver` (fail-closed at load).
- **`TcpTransport`** — unchanged; it still receives a literal IP and keeps its connect-time guard.
- **`config/ami-client.php`** — commented, opt-in `trusted_endpoint_policy` example (off by default).

## What was intentionally NOT changed
TcpTransport resolution logic, the legacy `$hostnameResolver` callable, `ClientOptions`, `ServerConfig`,
default `enforce_ip_endpoints=true`, and literal-IP-only behavior when no policy is installed.
