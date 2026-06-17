# Validation Results

## New tests (this task)
```
vendor/bin/phpunit tests/Unit/Cluster/Endpoint/ tests/Unit/Cluster/TrustedEndpointResolverConfigTest.php
OK (35 tests, 68 assertions)
```

## Full suite
```
vendor/bin/phpunit
Tests: 342, Assertions: 2002156, Failures: 6, Skipped: 1
```
The **6 failures are pre-existing and unrelated** to this task (proven by stashing this task's tracked edits
and re-running — identical 6 failures at baseline HEAD):
1. `ParserPermissionErrorTest` ×4 — missing `docs/ami-client/fixtures/permission-errors/*.raw` (never committed).
2. `PermissionErrorCorrelationTest::test_missing_actionid_response_is_logged_and_counted_as_unmatched` — same fixture family.
3. `ReadmeArtifactReferencesTest::test_release_checklist_globbed_artifact_families_resolve_to_files` — README artifact globs resolve to absent files.

No failure touches `Cluster/Endpoint`, the new exceptions, `ConfigLoader`, `AmiClientManager`, or `Transport`.

## Backward-compatibility spot check
```
vendor/bin/phpunit tests/Integration/NonBlockingConnectTest.php tests/Integration/ConfigLoaderHostnameResolverTest.php
OK (4 tests, 21 assertions)
```
(The legacy callable BC test errors only when run *in isolation*, because its `DnsTestHook` helper is
defined inside `NonBlockingConnectTest.php` — a pre-existing test-ordering coupling, green in the full suite.)

## Core boundary check
```
composer check:core-boundaries
ERROR: Forbidden sleep/usleep call in /src/Laravel/Commands/ListenCommand.php   (PRE-EXISTING, exit 1)
```
- The script's non-zero exit is caused **solely** by the pre-existing `ListenCommand.php` `usleep`
  (worker-layer cadence, permitted by NBRC Mode B but flagged by the regex).
- The trusted endpoint resolver files add **zero** violations:
  ```
  composer check:core-boundaries 2>&1 | grep -c "Endpoint|UntrustedEndpoint|ResolvedIp|Ambiguous|ResolverUnavailable|ConfigLoader|AmiClientManager"  → 0
  ```
- All new files declare `strict_types=1`, use no `sleep/usleep`, no `Illuminate` import in Core/Protocol
  (they are in `Cluster`/`Exceptions`), and no static mutable state.

## PHP lint
```
php -l on every new/modified PHP file → "No syntax errors detected"
```

## Git validation
```
git diff --check    → clean (no whitespace errors in the implementation diff)
git diff --name-status (tracked):
  M .gitignore                 (pre-existing /init change, NOT this task)
  M config/ami-client.php
  M src/Cluster/AmiClientManager.php
  M src/Cluster/ConfigLoader.php
  M src/Exceptions/InvalidConfigurationException.php
Untracked: src/Cluster/Endpoint/, 5 new src/Exceptions/*, tests/Unit/Cluster/Endpoint/,
           tests/Unit/Cluster/TrustedEndpointResolverConfigTest.php, this artifacts/ dir
```

## Unsafe-pattern scan (implementation files)
```
docker.sock | shell_exec | exec( | system( | passthru | APNTALK_ASTERISK | docker/.env | proc_open | popen
  → NONE in src/Cluster/Endpoint, src/Exceptions, or the new tests
enforce_ip_endpoints=false / enforceIpEndpoints=false recommendation
  → NONE in new src
```
(Two pre-existing `exec(` hits exist in `tests/Integration/Chaos*Test.php` — they invoke the chaos scenario
runner, not Docker, and predate this task.)
