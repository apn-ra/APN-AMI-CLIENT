# apntalk/ami-client v1.0.1 Release Notes

## 1) Summary
- Parser framing and desync recovery were hardened with bounded diagnostics, improving resilience under truncation/garbage/oversized frames.
- Correlation registry now has deterministic stress coverage for out-of-order 1k ActionID responses, reducing risk of orphaned pending actions.
- Multi-server fairness validation moved to direct three-node flood scenarios so noisy-node starvation risks are asserted, not inferred.
- Reconnect storm behavior now includes explicit cluster connect-attempt budget evidence, reducing herd-risk regressions.
- Observability artifacts were standardized across chaos scenarios with redacted previews and consistent metrics output.
- Core/Laravel packaging boundaries were tightened so framework-agnostic core installs do not require Laravel dependencies.
- Test environment classification now distinguishes sandbox/runtime constraints from code defects to prevent false-negative readiness decisions.
- Release governance/docs were aligned to latest-artifact selection and gate evidence requirements.

## 2) Verification
- Chaos suite verdict: `PASS (13/13)` from `docs/ami-client/chaos/reports/20260304-053700Z-final-chaos-suite-results.md`
- Production readiness score/verdict: `40/40 (100/100), Ready` from `docs/ami-client/production-readiness/audits/20260304-054704Z-production-readiness-score.md`
- Key invariants confirmed in release evidence:
  - Bounded memory/buffers (parser and diagnostics in S6/S7/S8)
  - Multi-server fairness/no starvation (S12)
  - Deterministic correlation completion and cleanup (S11)

## 3) Highlights by Subsystem

### Protocol/Parser
- Added parser diagnostics and recovery telemetry for chaos visibility; validated via completed batch `20260304-035051Z-chaos-batch-001-parser-framing-hardening.md`.
- Scenario evidence: S6/S7/S8 in `docs/ami-client/chaos/reports/20260304-053016Z-chaos-suite-results.md`.
- Test evidence: `tests/Integration/ChaosParserRecoveryTest.php` and `tests/Integration/ChaosScenarioLinkageTest.php` (batch evidence links).

### Correlation/Completion
- Added deterministic 1k out-of-order correlation storm assertions and diagnostics counters (`20260304-035051Z-chaos-batch-002-correlation-completion-correctness.md`).
- Scenario evidence: S11 PASS in `docs/ami-client/chaos/reports/20260304-053016Z-chaos-suite-results.md`.
- Test evidence: `tests/Integration/CorrelationStormTest.php`, `tests/Unit/Correlation/CorrelationRegistryTest.php`.

### Cluster/Fairness
- Upgraded fairness validation to direct 3-server flood scenario execution (`20260304-035051Z-chaos-batch-003-fairness-budgets-multi-server-select.md`).
- Scenario evidence: S12 PASS in `docs/ami-client/chaos/reports/20260304-053016Z-chaos-suite-results.md`.
- Test evidence: `tests/Performance/FloodSimulationTest.php`.

### Health/Reconnect
- Added explicit per-tick connect-attempt snapshot coverage for reconnect storms (`20260304-035051Z-chaos-batch-004-reconnect-herd-control.md`).
- Scenario evidence: S13 PASS in `docs/ami-client/chaos/reports/20260304-053016Z-chaos-suite-results.md`.
- Test evidence: `tests/Integration/ReconnectStormTest.php`.

### Observability
- Standardized chaos metrics generation and redacted previews (`20260304-035051Z-chaos-batch-005-observability-diagnostics-counters.md`).
- Additional readiness/governance evidence from completed batches:
  - `20260304-044414Z-pr-batch-004-test-harness-sandbox-classification.md`
  - `20260304-050618Z-pr-batch-004-test-harness-completeness.md`
  - `20260304-044414Z-pr-batch-005-docs-release-governance.md`
  - `20260304-050618Z-pr-batch-005-docs-packaging-governance.md`

## 4) Breaking Changes
- None documented for `v1.0.1`.

## 5) Upgrade Notes
- Core package remains framework-agnostic; verify install paths against `composer.json` package metadata updates.
- Keep dialer-grade defaults in place for bounded safety:
  - parser/frame caps
  - event queue/write buffer caps
  - per-tick fairness budgets
- For CI and local validation, use environment-classification workflows when socket/network restrictions are present.
