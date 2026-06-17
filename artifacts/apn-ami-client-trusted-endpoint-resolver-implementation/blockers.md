# Blockers

## Task-blocking
**None.** The trusted endpoint resolver is implemented, wired, and fully tested (35 new tests green).

## Pre-existing issues (NOT caused by this task, NOT blocking)
1. **Missing parser fixtures** — `tests/Unit/Protocol/ParserPermissionErrorTest.php` (4 cases) and
   `tests/Unit/Correlation/PermissionErrorCorrelationTest.php` (1 case) fail because
   `docs/ami-client/fixtures/permission-errors/*.raw` are not present in the repo. Reproduced identically at
   baseline HEAD with this task's edits stashed.
2. **README artifact globs** — `tests/Unit/Docs/ReadmeArtifactReferencesTest.php` release-checklist glob
   assertion fails because referenced production-readiness artifact files are absent in this checkout.
3. **Core-boundary script** — `composer check:core-boundaries` exits non-zero solely on a `usleep()` in
   `src/Laravel/Commands/ListenCommand.php` (worker-layer cadence allowed by NBRC Mode B, flagged by the
   simple regex). The new resolver files add zero violations.
4. **Test-ordering coupling** — `ConfigLoaderHostnameResolverTest` depends on `DnsTestHook` defined inside
   `NonBlockingConnectTest.php`; it errors only when run in isolation and passes in the full suite.

## Open decisions for proof-review (not blockers)
- Version bump choice for the eventual tag (`v1.1.0` minor recommended vs `v1.0.2` patch).
- Whether to also expose an explicit IPv6 AAAA path in the default DNS resolver (current default uses
  `gethostbynamel()` IPv4; the resolver/CIDR/classifier already handle IPv6 when a callable supplies it).
- Whether the package should ship a tiny example using `trusted_endpoint_policy` in `examples/`.
