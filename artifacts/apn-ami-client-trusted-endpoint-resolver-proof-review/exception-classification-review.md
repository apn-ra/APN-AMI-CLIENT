# Exception Classification Review

## Q13 — Precise and still subclasses of InvalidConfigurationException? **YES.**
Source-confirmed `extends InvalidConfigurationException` on all five:
- `UntrustedEndpointHostException` — host not in allowlist/pattern; thrown **before DNS**. Carries
  `getEndpointHost()`.
- `EndpointHostUnresolvableException` — allowlisted host with no resolvable IP. `getEndpointHost()`.
- `ResolvedIpOutsideAllowedCidrException` — IP outside CIDR or in a denied reserved range (loopback,
  link-local/metadata, multicast, unspecified, broadcast, public, network/broadcast/gateway).
  `getResolvedIp()`, `getEndpointHost()`, `getReason()`.
- `AmbiguousEndpointResolutionException` — multi-IP ambiguous/mixed under policy (fail-closed).
  `getEndpointHost()`, `getResolvedIps()`.
- `ResolverUnavailableException` — policy declared but unusable / DNS mechanism unavailable.

Each maps to exactly one failure condition; accessors enable precise reconciler→blocker mapping at APNTalk
later. Unit test `test_all_endpoint_exceptions_extend_invalid_configuration` asserts the inheritance.

## Q14 — Post-resolution socket failures remain ConnectionException? **YES.**
`ConnectionException` is untouched (`git diff --name-only` shows no change). Trust/config failures are
configuration-time (`InvalidConfigurationException` family); socket-time failures in `TcpTransport::open()`
still throw `ConnectionException`. Clean separation preserved.

## Q15 — InvalidConfigurationException made extendable without breaking API? **YES.**
Single change: `final class` → `class`. Removing `final` cannot break any existing consumer (no code could
have depended on the inability to subclass). All factory methods (`forRedactionPattern`) and getters are
unchanged. Existing `catch (InvalidConfigurationException $e)` blocks now also catch the five new subclasses
(widened catch is desirable and BC-safe).

**Exception classification review: PASS.**
