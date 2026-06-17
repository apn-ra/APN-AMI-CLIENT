# Trusted Resolver Policy Review

Source-verified against `DefaultTrustedEndpointResolver`, `AllowedEndpointHostPolicy`,
`ReservedRangePolicy`, `MultiIpPolicy`, `CidrMatcher`, `IpClassifier`.

## Q5 — Allowlist/pattern validation occurs before DNS? **YES.**
`resolve()`: literal-IP short-circuit → **allowlist gate** (`isHostTrusted`, line 72) → DNS
(`resolveHostToIps`, line 77). An untrusted host throws `UntrustedEndpointHostException` before any DNS
callable invocation. Proven empirically: untrusted host rejected with **dns_called=0**.

## Q6 — Resolved-IP CIDR validation occurs before connection? **YES.**
Resolution runs at bootstrap (`AmiClientManager` construction → `resolveHost`), before any socket open. Each
resolved IP passes `validateResolvedIp()` (reserved-range → in-CIDR → not network/broadcast) before the
manager hands the literal IP to `TcpTransport`.

## Q4 — Arbitrary DNS rejected? **YES.**
No code path resolves a non-allowlisted hostname. Default DNS uses `gethostbynamel()` only; results are
filtered to valid IP literals and then CIDR/reserved-validated. There is no "resolve anything" branch.

## Q7 — metadata / loopback / localhost / public / out-of-CIDR / reserved / mixed multi-IP fail closed? **YES.**
Empirical probe (policy allows only `172.30.10.0/24`):
| Input via trusted alias | Result |
|---|---|
| `::ffff:169.254.169.254` (IPv4-mapped metadata) | REJECTED `ResolvedIpOutsideAllowedCidr` |
| `::ffff:127.0.0.1` (IPv4-mapped loopback) | REJECTED |
| `169.254.169.254` (metadata) | REJECTED |
| `127.0.0.1` (loopback) | REJECTED |
| `8.8.8.8` (public) | REJECTED |
| `172.30.10.0` (network) / `172.30.10.255` (broadcast) | REJECTED |
| `172.30.10.50` (legit in-CIDR) | ACCEPTED |
Plus unit tests: `localhost`/loopback rejected unless explicitly allowed; multiple IPs under `Reject` →
`AmbiguousEndpointResolutionException`; mixed valid/invalid under `DeterministicAllValid` → ambiguous;
all-valid → deterministic lowest pick.

## Multi-IP policy — fail-closed unless explicit deterministic all-valid. **Confirmed.**
- `MultiIpPolicy::Reject` (default): >1 IP ⇒ ambiguous, rejected.
- `MultiIpPolicy::DeterministicAllValid`: any invalid IP ⇒ ambiguous; otherwise lowest binary address
  chosen deterministically (`lowestAddress`, IPv4-before-IPv6 ordering).

## Policy construction is fail-closed
- Invalid PCRE pattern / invalid CIDR ⇒ `InvalidConfigurationException` at construction.
- `fromArray()` with no allowlist+patterns or no CIDRs ⇒ `ResolverUnavailableException`.
- `denied_ips` entries validated as IPs at construction.

## CIDR / classifier primitives
- `CidrMatcher`: byte-wise `inet_pton` math; family mismatch ⇒ non-match (no cross-family false positive,
  unit-tested); network/broadcast computation unit-tested.
- `IpClassifier`: IPv4 + IPv6 categories incl. IPv4-mapped-IPv6 → RESERVED (default-denied), metadata
  detection. No DNS, no sockets.

**Trusted resolver policy review: PASS.**
