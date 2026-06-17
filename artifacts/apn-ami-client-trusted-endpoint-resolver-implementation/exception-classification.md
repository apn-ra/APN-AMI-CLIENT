# Exception Classification

All new endpoint-trust exceptions live in `Apn\AmiClient\Exceptions` and
**extend `InvalidConfigurationException`** (which itself extends `AmiException`).
This is BC-safe: existing `catch (InvalidConfigurationException)` blocks still
catch them. Resolution/trust failures are configuration failures; socket
failures stay `ConnectionException`.

| Condition | Exception | When thrown |
|---|---|---|
| Hostname not in allowlist/pattern | `UntrustedEndpointHostException` | **before** any DNS lookup |
| Allowlisted host has no resolvable IP | `EndpointHostUnresolvableException` | after empty DNS result |
| Resolved/literal IP outside allowed CIDR or in a denied reserved range (loopback, link-local/metadata, multicast, unspecified, broadcast, public, network/broadcast/gateway) | `ResolvedIpOutsideAllowedCidrException` | during IP validation |
| Multiple resolved IPs, ambiguous/mixed under policy | `AmbiguousEndpointResolutionException` | during multi-IP selection (fail-closed) |
| Policy declared but unusable (no allowlist/patterns, or no CIDR), or DNS mechanism unavailable | `ResolverUnavailableException` | at policy build / resolve |

## Distinct, unchanged
- **Post-resolution socket failure** → existing `ConnectionException` (TcpTransport). Trust/config errors are
  cleanly separated from connect-time errors. Verified conceptually by
  `EndpointExceptionTaxonomyTest`-style assertions inside
  `DefaultTrustedEndpointResolverTest::test_all_endpoint_exceptions_extend_invalid_configuration`.
- **Missing provider endpoint** remains an APNTalk-side concern (not represented in the package).

## Accessors (for precise operator/reconciler mapping)
- `getEndpointHost()` on host/unresolvable/ambiguous exceptions.
- `getResolvedIp()` / `getReason()` on `ResolvedIpOutsideAllowedCidrException`.
- `getResolvedIps()` on `AmbiguousEndpointResolutionException`.

## Secret hygiene
Messages include host / IP / CIDR only — never credentials or `APNTALK_ASTERISK_*` values. (The package's
`Core/SecretRedactor` remains available for any logging of these by consumers.)
