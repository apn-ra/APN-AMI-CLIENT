# Current State

## Repo
- Package repo: `/home/ra/Documents/apn_projects/APN-AMI-CLIENT`
- Branch: `main`
- HEAD before changes (unchanged — nothing committed): `d029ab6a8366d511259f586cce9b520bab835961`
- PHP: 8.4.22; PHPUnit: 12.5.14

## Working tree (after this task, uncommitted)
Modified (tracked):
- `src/Exceptions/InvalidConfigurationException.php` — `final` removed so trust exceptions can extend it.
- `src/Cluster/AmiClientManager.php` — optional `TrustedEndpointResolverInterface`; wired into `resolveHost()`/`validateHostnamePolicy()`.
- `src/Cluster/ConfigLoader.php` — optional resolver param + `config['trusted_endpoint_policy']` builder.
- `config/ami-client.php` — documented, commented, opt-in `trusted_endpoint_policy` block.
- `.gitignore` — pre-existing working-tree change from a prior `/init` (adds `CLAUDE.md`); NOT part of this task, left untouched.

New (untracked):
- `src/Cluster/Endpoint/` — `TrustedEndpointResolverInterface`, `DefaultTrustedEndpointResolver`, `EndpointResolutionResult`, `AllowedEndpointHostPolicy`, `ReservedRangePolicy`, `MultiIpPolicy`, `CidrMatcher`, `IpClassifier`.
- `src/Exceptions/` — `UntrustedEndpointHostException`, `EndpointHostUnresolvableException`, `ResolvedIpOutsideAllowedCidrException`, `AmbiguousEndpointResolutionException`, `ResolverUnavailableException`.
- `tests/Unit/Cluster/Endpoint/` — `DefaultTrustedEndpointResolverTest`, `CidrMatcherTest`.
- `tests/Unit/Cluster/TrustedEndpointResolverConfigTest.php`.

## Pre-existing enforcement (before this task)
- Literal-IP enforcement lived in `AmiClientManager::resolveHost()` + `TcpTransport` connect-time guard.
- `enforce_ip_endpoints=true` by default; non-IP host under enforce=true → `InvalidConfigurationException`.
- A legacy `?callable $hostnameResolver` hook existed (only useful with enforce=false; delegated all
  allowlist/CIDR safety to the app — the unsafe state this task removes the *need* for).

## Baseline test reality (BEFORE my changes; reproduced with edits stashed)
The suite is **not green at baseline** due to environment/data gaps unrelated to this task:
- `ParserPermissionErrorTest` (4 cases) + `PermissionErrorCorrelationTest` (1 case): missing
  `docs/ami-client/fixtures/permission-errors/*.raw` (never committed).
- `ReadmeArtifactReferencesTest` (1 case): README artifact globs resolve to absent files.
Verified: stashing this task's tracked edits and re-running these tests reproduces the **same 6 failures**.
