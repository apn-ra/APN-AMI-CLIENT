# enforce_ip_endpoints Boundary Preservation

## Default behavior is unchanged
- `enforce_ip_endpoints` default remains `true` (`ClientOptions`, `config/ami-client.php`).
- With **no** trusted policy/resolver installed:
  - literal IP endpoints are accepted exactly as before;
  - any non-IP host under enforce=true throws `InvalidConfigurationException`
    (message: "... is not a literal IP while enforce_ip_endpoints is enabled.").
- Regression-guarded by `TrustedEndpointResolverConfigTest::test_enforce_true_without_policy_rejects_hostname`
  and the existing `ConfigLoaderTest::testRejectsHostnameWhenIpOnlyPolicyIsEnabled` (still green).

## Redefined (widened only under explicit opt-in)
With a trusted policy/resolver installed, `enforce_ip_endpoints=true` now means:
> the endpoint host must be a **literal IP**, OR a **trusted-policy-validated hostname** that the package
> resolves into a literal, safe IP.

This is a widening that requires explicit operator action (installing a policy). For every consumer that
installs no policy it is a no-op. APNTalk therefore does **not** need to set `enforce_ip_endpoints=false`
to use trusted aliases.

## The unsafe state is prevented
- `enforce_ip_endpoints=false` is **not** the package's recommended solution and is not required.
- `enforce_ip_endpoints=false` + no resolver/policy + hostname → still rejected
  (`InvalidConfigurationException`, "pre-resolved IP or an injected hostname resolver"). Verified by
  `TrustedEndpointResolverConfigTest::test_enforce_false_without_policy_or_resolver_rejected`.
- A declared-but-unusable `trusted_endpoint_policy` (no allowlist or no CIDR) fails closed at load with
  `ResolverUnavailableException` — you cannot accidentally enable a no-op "trust everything" path.
- No config path admits an arbitrary public-DNS hostname: the allowlist gate runs before DNS and the CIDR +
  reserved checks run after.

## Transport-level safety retained
`TcpTransport` is unchanged: it always receives a literal IP (the resolver's `validatedIp`) and keeps its
own connect-time `FILTER_VALIDATE_IP` guard. The original hostname is retained only for diagnostics and is
never used to open the socket. Verified by
`TrustedEndpointResolverConfigTest::test_enforce_true_with_policy_accepts_trusted_alias` (reads the
transport's `remoteHost` and asserts it is the resolved literal IP, not the alias).
