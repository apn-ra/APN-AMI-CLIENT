# Full-Suite Failure Classification

## Q19 — Are the 6 full-suite failures truly unrelated/pre-existing? **YES (proven).**

## Full run
```
vendor/bin/phpunit
Tests: 342, Assertions: 2002156, Failures: 6, Skipped: 1
```
Matches the reported 342 / 6 / 1.

## The 6 failures
1. `Tests\Unit\Correlation\PermissionErrorCorrelationTest::test_missing_actionid_response_is_logged_and_counted_as_unmatched`
2. `Tests\Unit\Docs\ReadmeArtifactReferencesTest::test_release_checklist_globbed_artifact_families_resolve_to_files`
3–6. `Tests\Unit\Protocol\ParserPermissionErrorTest` (4 data cases) — `Failed asserting that file
   ".../docs/ami-client/fixtures/permission-errors/*.raw" exists`.

## Root cause (independent)
- `docs/ami-client/fixtures/permission-errors/` does **not exist**; `git ls-files docs/ami-client/fixtures/`
  returns **0** tracked files → fixtures were never committed.
- `ReadmeArtifactReferencesTest` globs production-readiness artifact families that are absent in this checkout.
- None of the three failing test files reference the resolver
  (`grep -lEi "TrustedEndpoint|Endpoint\\|resolveHost|AllowedEndpoint|ResolverUnavailable"` → no match).

## Proof of pre-existence (stash test)
With the four tracked-modified files stashed (baseline restored), re-running exactly these tests:
```
vendor/bin/phpunit --filter '...PermissionErrorCorrelationTest...|...ReadmeArtifactReferencesTest...|ParserPermissionErrorTest' tests/Unit
Tests: 7, Assertions: 16, Failures: 6
```
Identical 6 failures at baseline HEAD `d029ab6`. Edits restored and verified afterward.

## Conclusion
All 6 failures are pre-existing, data/environment-driven, and **not caused by** the trusted endpoint
resolver. Not fixed in this proof-review (out of scope; not resolver-related). The 1 skipped test is a
pre-existing environment-gated skip, also unrelated.
