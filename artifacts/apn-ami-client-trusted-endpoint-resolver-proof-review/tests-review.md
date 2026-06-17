# Tests Review

## Q18 — Does the test matrix cover the package security boundary sufficiently? **YES.**

## New tests executed independently
```
vendor/bin/phpunit tests/Unit/Cluster/Endpoint/DefaultTrustedEndpointResolverTest.php \
                   tests/Unit/Cluster/Endpoint/CidrMatcherTest.php \
                   tests/Unit/Cluster/TrustedEndpointResolverConfigTest.php
OK (35 tests, 68 assertions)
```
Matches the reported "35 tests / 68 assertions".

## Coverage assessment
- **Default-strict:** literal IP accepted; hostname rejected with no policy.
- **Allowlist-before-DNS:** untrusted host asserts **0 DNS calls** (call-counting hook) — the single most
  important SSRF property, explicitly tested.
- **Positive:** exact alias `apntalk-asterisk-app`, pattern aliases `apntalk-asterisk-node-{a,b}-app`,
  in-CIDR acceptance, and (via config test) the transport receiving the literal IP.
- **Negative:** out-of-CIDR, unresolvable, metadata `169.254.169.254`, loopback/localhost (rejected unless
  explicit), public IP under local-only CIDR, gateway `denied_ips`, network/broadcast boundary.
- **Multi-IP:** reject>1, mixed valid/invalid fail-closed, all-valid deterministic lowest.
- **Unsafe-state guard:** `ResolverUnavailableException` for policy with no allowlist / no CIDR; enforce=false
  + no policy rejected.
- **Taxonomy:** all five exceptions are `InvalidConfigurationException`.
- **Wiring:** ConfigLoader builds resolver from config; explicit resolver instance honored; literal IP still
  accepted by default; resolver returning non-IP contained.

## Independent empirical probe (beyond the suite)
A throwaway probe confirmed IPv4-mapped-IPv6 metadata/loopback bypass vectors fail closed — a class the unit
tests cover via classifier tests; the probe adds direct end-to-end confirmation.

## Gaps (minor, non-blocking — recorded for maintainers)
- No explicit test routing a **literal IP through the policy CIDR** via the manager (matches the documented
  short-circuit design; see enforce-ip-boundary-review observation).
- IPv6 happy-path (AAAA) at the manager/ConfigLoader layer is exercised only at the matcher/classifier level;
  the default DNS resolver is IPv4 (`gethostbynamel`). The resolver/CIDR/classifier already support IPv6 when
  a callable supplies it.
These do not affect the security boundary and are enhancement suggestions, not defects.

**Tests review: PASS.**
