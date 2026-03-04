# Packaging and Release Hygiene Audit

- Timestamp (UTC): 2026-03-04T04:44:14Z

## Checks

### Composer constraints and autoload
- PHP constraint present: `composer.json:12`
- PSR-4 autoload maps package namespace to `src/`: `composer.json:6-9`
- `psr/log` declared: `composer.json:13`

### Framework-agnostic core packaging
- Root `require` currently includes Laravel packages:
  - `composer.json:14-15`
- This couples non-Laravel installs to Laravel dependencies. Recommend moving these to optional adapter package or `suggest`.

### Documentation and examples
- README exists with pure PHP + Laravel quickstart:
  - `README.md`
- Usage guide exists:
  - `docs/ami-client/usage-guide.md`
- Runtime contract doc exists:
  - `docs/contracts/non-blocking-runtime-contract.md`

### Dev-only/debug code in `src/`
- Debug telemetry exists but is guarded by explicit runtime flag:
  - `src/Core/AmiClient.php:159-164`, `src/Core/AmiClient.php:945-967`
- No `dd()` / `dump()` / `var_dump()` debug leftovers were found in `src/`.

## Verdict
- Release hygiene is mostly good.
- Primary packaging gap is mandatory Laravel runtime dependencies at root package level (P1).
