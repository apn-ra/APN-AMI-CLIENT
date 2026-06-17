# enforce_ip_endpoints Boundary Review

## Q1 — Package owns trusted endpoint host authority? **YES.**
Trust logic lives entirely in the package (`Cluster\Endpoint\*` + `AmiClientManager`/`ConfigLoader` wiring).
A consumer supplies only allowlist/CIDR **data**; no app-side trust decision is required.

## Q2 — Default remains strict literal-IP-only under enforce=true? **YES.**
`ClientOptions::enforceIpEndpoints` default `true` (unchanged). With no resolver, `AmiClientManager::resolveHost()`
returns a literal IP as before and throws `InvalidConfigurationException` for any non-IP host. Verified by
`ConfigLoaderTest::testRejectsHostnameWhenIpOnlyPolicyIsEnabled` and
`TrustedEndpointResolverConfigTest::test_enforce_true_without_policy_rejects_hostname` (both green).

## Q3 — Hostnames rejected unless an explicit policy is installed? **YES.**
A non-IP host reaches the resolver path only when `$this->trustedResolver !== null` (AmiClientManager
line ~565). No resolver ⇒ enforce=true rejects; enforce=false falls to the legacy callable path (which itself
rejects without a callable).

## Q11 — Avoids requiring APNTalk to set enforce=false? **YES.**
The resolver is consulted under enforce=true. `validateHostnamePolicy()` returns early when a resolver is
installed, and `resolveHost()` calls `resolver->resolve()->validatedIp`. Trusted aliases work with enforce
left at `true`. Verified by `test_enforce_true_with_policy_accepts_trusted_alias`.

## Q12 — Unsafe state `enforce=false + no policy` prevented / not required? **YES (not required; legacy path
still fail-closed).**
- The new trusted path never needs enforce=false, so the unsafe state is **not required**.
- enforce=false + no resolver/policy + hostname is still rejected
  (`test_enforce_false_without_policy_or_resolver_rejected`, green).
- A declared-but-unusable `trusted_endpoint_policy` (no allowlist or no CIDR) fails closed at load with
  `ResolverUnavailableException` (`AllowedEndpointHostPolicy::fromArray`).

## Q8 — Transport receives a literal validated IP? **YES.**
`TcpTransport` is unmodified and still applies its `filter_var(...FILTER_VALIDATE_IP)` guard (lines 125/147).
The manager passes it `resolver->resolve()->validatedIp`. `test_enforce_true_with_policy_accepts_trusted_alias`
reads the transport's `remoteHost` via reflection and asserts it equals the resolved literal IP (`172.30.10.20`,
`10.250.5.7`), never the alias.

## Q9 — Original hostname diagnostic-only? **YES.**
`EndpointResolutionResult::$originalHost` is returned for diagnostics; only `$validatedIp` is used to connect.
The manager uses `->validatedIp` exclusively.

## Observation (non-blocking) — literal-IP short-circuit asymmetry
In `AmiClientManager::resolveHost()`, a literal-IP endpoint configured in `servers` returns immediately
**before** the trusted resolver, so it is **not** subjected to the policy's CIDR/reserved validation. This
*preserves existing behavior* (a literal IP has always been accepted under enforce=true) and is therefore not
a regression or SSRF weakening — the resolver governs hostnames, which are the untrusted input. Note for
maintainers: the standalone `DefaultTrustedEndpointResolver::resolve()` *does* validate literal IPs against
the policy, so a consumer wanting literal IPs also confined to the trusted CIDR would need to call the
resolver directly or the manager would need an opt-in to route literal IPs through it. Documented as a
future enhancement, not a defect for this claim.

**enforce_ip boundary review: PASS.**
