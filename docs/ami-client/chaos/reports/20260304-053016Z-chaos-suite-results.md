# Chaos Suite Results

- Timestamp (UTC): `2026-03-04T05:30:16Z`
- Environment:
  - OS: `Linux 6.6.87.2-microsoft-standard-WSL2 #1 SMP PREEMPT_DYNAMIC Thu Jun 5 18:30:46 UTC 2025 x86_64 GNU/Linux`
  - PHP: `8.4.18`
  - Runner: `php tests/Chaos/run_scenario.php --scenario=<file> --duration-ms=1200`
- Scenario source: `docs/ami-client/chaos/scenarios/*.json`
- Aggregate log: `docs/ami-client/chaos/fixtures/20260304-053016Z-chaos-suite.log`

## Scenario Matrix

| Scenario ID | Pass/Fail | Primary invariant(s) tested | Evidence |
|---|---|---|---|
| S1 | PASS | `Response: Error` parsed and classified as failure | `docs/ami-client/chaos/scenarios/s1-permission-denied.json`, `docs/ami-client/chaos/metrics/20260304-052931Z-metrics-s1.md`, `docs/ami-client/chaos/fixtures/20260304-053016Z-chaos-suite.log` |
| S2 | PASS | `\n\n` delimiter parse and no stall | `docs/ami-client/chaos/scenarios/s2-permission-denied-lf.json`, `docs/ami-client/chaos/metrics/20260304-052937Z-metrics-s2.md`, `docs/ami-client/chaos/fixtures/20260304-053016Z-chaos-suite.log` |
| S3 | PASS | unmatched error response handling stability | `docs/ami-client/chaos/scenarios/s3-error-missing-actionid.json`, `docs/ami-client/chaos/metrics/20260304-052939Z-metrics-s3.md`, `docs/ami-client/chaos/fixtures/20260304-053016Z-chaos-suite.log` |
| S4 | PASS | banner handling + subsequent frame parse continuity | `docs/ami-client/chaos/scenarios/s4-banner-error-interleaving.json`, `docs/ami-client/chaos/metrics/20260304-052940Z-metrics-s4.md`, `docs/ami-client/chaos/fixtures/20260304-053016Z-chaos-suite.log` |
| S5 | PASS | partial/chunked frame reassembly determinism | `docs/ami-client/chaos/scenarios/s5-partial-writes-chunked-frames.json`, `docs/ami-client/chaos/metrics/20260304-052941Z-metrics-s5.md`, `docs/ami-client/chaos/fixtures/20260304-053016Z-chaos-suite.log` |
| S6 | PASS | truncated-frame recovery with bounded parser state | `docs/ami-client/chaos/scenarios/s6-truncated-frame-recovery.json`, `docs/ami-client/chaos/metrics/20260304-052942Z-metrics-s6.md`, `docs/ami-client/chaos/fixtures/20260304-053016Z-chaos-suite.log` |
| S7 | PASS | garbage/desync resilience without cross-frame contamination | `docs/ami-client/chaos/scenarios/s7-garbage-desync-recovery.json`, `docs/ami-client/chaos/metrics/20260304-052944Z-metrics-s7.md`, `docs/ami-client/chaos/fixtures/20260304-053016Z-chaos-suite.log` |
| S8 | PASS | max-frame enforcement and recovery under oversized payload | `docs/ami-client/chaos/scenarios/s8-oversized-frame.json`, `docs/ami-client/chaos/metrics/20260304-052945Z-metrics-s8.md`, `docs/ami-client/chaos/fixtures/20260304-053016Z-chaos-suite.log` |
| S9 | PASS | follows/error path bounded behavior | `docs/ami-client/chaos/scenarios/s9-follows-oversize.json`, `docs/ami-client/chaos/metrics/20260304-052946Z-metrics-s9.md`, `docs/ami-client/chaos/fixtures/20260304-053016Z-chaos-suite.log` |
| S10 | PASS | event-flood containment path execution | `docs/ami-client/chaos/scenarios/s10-event-flood-20k.json`, `docs/ami-client/chaos/metrics/20260304-052932Z-metrics-s10.md`, `docs/ami-client/chaos/fixtures/20260304-053016Z-chaos-suite.log` |
| S11 | PASS | 1k ActionID correlation stability out of order | `docs/ami-client/chaos/scenarios/s11-correlation-storm-1k.json`, `docs/ami-client/chaos/metrics/20260304-052934Z-metrics-s11.md`, `docs/ami-client/chaos/fixtures/20260304-053016Z-chaos-suite.log` |
| S12 | PASS | multi-server fairness and per-server isolation | `docs/ami-client/chaos/scenarios/s12-multi-server-fairness.json`, `docs/ami-client/chaos/metrics/20260304-052935Z-metrics-s12.md`, `docs/ami-client/chaos/fixtures/20260304-053016Z-chaos-suite.log` |
| S13 | PASS | reconnect-storm loop resilience path execution | `docs/ami-client/chaos/scenarios/s13-reconnect-storm.json`, `docs/ami-client/chaos/metrics/20260304-052936Z-metrics-s13.md`, `docs/ami-client/chaos/fixtures/20260304-053016Z-chaos-suite.log` |

## Summary

- Total scenarios: `13`
- Passed: `13`
- Failed: `0`
- Runtime classification: `RUNTIME_OK` for all scenario runs in the outside-sandbox execution.

## Severity-Ranked Failures

No failures in this run.

- P0: none
- P1: none
- P2/P3: none

## Fix Task Batches (Phase 6)

No new failure-driven task batches were generated because all required scenarios passed.

## Verdict

`Ready`
