# Changed Paths Review

Every changed/untracked path classified. No unexpected paths.

| Path | Status | Classification |
|---|---|---|
| `.gitignore` | M | **pre-existing unrelated working-tree change** — adds `CLAUDE.md` to ignore; from a prior `/init`, not this feature. Not staged, not modified by review. |
| `config/ami-client.php` | M | **intended package source change** — adds a commented, opt-in `trusted_endpoint_policy` example block; no active default change. |
| `src/Cluster/AmiClientManager.php` | M | **intended package source change** — optional `?TrustedEndpointResolverInterface` ctor arg; `resolveHost()`/`validateHostnamePolicy()` defer to it. |
| `src/Cluster/ConfigLoader.php` | M | **intended package source change** — optional `$trustedResolver`/`$dnsResolver` params; builds resolver from `config['trusted_endpoint_policy']` (fail-closed). |
| `src/Exceptions/InvalidConfigurationException.php` | M | **intended package source change** — `final` removed so trust exceptions can extend it. |
| `src/Cluster/Endpoint/` (8 files) | ?? | **intended package source change** — interface, default resolver, result VO, policies, enum, CIDR/IP utilities. |
| `src/Exceptions/{Untrusted,EndpointHostUnresolvable,ResolvedIpOutsideAllowedCidr,AmbiguousEndpointResolution,ResolverUnavailable}*.php` | ?? | **intended package source change** — 5 precise exception subclasses. |
| `tests/Unit/Cluster/Endpoint/` (2 files) | ?? | **intended package test change** — resolver + CIDR matcher tests. |
| `tests/Unit/Cluster/TrustedEndpointResolverConfigTest.php` | ?? | **intended package test change** — manager/ConfigLoader wiring tests. |
| `artifacts/apn-ami-client-trusted-endpoint-resolver-implementation/` | ?? | **intended package artifact** — implementation report set. |
| `artifacts/apn-ami-client-trusted-endpoint-resolver-proof-review/` | ?? | **intended package artifact** — this review set. |

## Notes
- The `.gitignore` change is the only path NOT attributable to the resolver work. It is benign (adds a
  developer file to ignore) and must be left untouched / unstaged per task instructions.
- No modification to `TcpTransport`, `ClientOptions`, `ServerConfig`, `ConnectionException`, or any
  Core/Protocol/Health file. Confirmed via `git diff --name-only`.
- `composer.json` / `composer.lock` unchanged.
