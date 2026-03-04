# Chaos Remediation Report

## Original Failures
- Scenario runner and matrix evidence were incomplete for S11/S12/S13 and several error-terminal scenarios.
- Missing deterministic 1k out-of-order correlation test left a correctness gap.
- Observability artifacts lacked standardized memory/counter output per scenario.

## Root Causes
- Harness/runner capabilities lagged scenario expectations (single-server assumptions, simplistic pass predicate).
- Correlation diagnostics were not externally queryable for deterministic assertions.
- Reconnect cluster budget evidence existed implicitly but not via explicit manager snapshot assertions.

## Changes by Subsystem
- Protocol/Parser:
  - Added diagnostics and recovery telemetry (`src/Protocol/Parser.php`).
- Harness/Runner:
  - Added multi-server execution, expectation evaluation, per-server counters, metrics markdown generation, preview redaction (`tests/Chaos/run_scenario.php`).
  - Added explicit truncated frame primitive and decoded escaped payload handling (`tests/Chaos/Harness/FakeAmiServer.php`).
- Correlation:
  - Added diagnostics counters and 1k out-of-order stress regression (`src/Correlation/CorrelationRegistry.php`, `tests/Integration/CorrelationStormTest.php`).
- Fairness/Reconnect:
  - 3-node flood fairness assertions and manager connect-attempt snapshot (`tests/Performance/FloodSimulationTest.php`, `src/Cluster/AmiClientManager.php`, `tests/Integration/ReconnectStormTest.php`).
- Observability:
  - Added `AmiClient::snapshot()` and runner redaction regression coverage.

## Evidence
- Touched regression run:
  - `vendor/bin/phpunit tests/Integration/ChaosParserRecoveryTest.php tests/Integration/CorrelationStormTest.php tests/Integration/ReconnectStormTest.php tests/Integration/ChaosScenarioLinkageTest.php tests/Integration/ChaosRunnerRedactionTest.php tests/Performance/FloodSimulationTest.php tests/Unit/Correlation/CorrelationRegistryTest.php tests/Unit/Core/AmiClientTest.php`
  - Result: `OK (54 tests, 267 assertions)`
- Full chaos suite final:
  - `docs/ami-client/chaos/reports/20260304-043335Z-final-chaos-suite-results.md`
  - Result: `PASS (13/13)`

## Remaining Risks / Follow-Ups
- Chaos runner remains a harness approximation (not a full AMI client lifecycle simulator).
- Further production-grade stress can include longer soak windows and CPU telemetry snapshots.
