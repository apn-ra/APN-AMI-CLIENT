# Final Chaos Suite Results

- Timestamp (UTC): 2026-03-04T05:02:32Z
- Runner: `php tests/Chaos/run_scenario.php` for all `docs/ami-client/chaos/scenarios/*.json`
- Classification: `SANDBOX_ENVIRONMENT`

## Scenario Summary
- Total scenarios: 13
- Passed: 0
- Failed: 13

All scenarios failed with runtime startup signature:
- `Unable to start fake AMI server: Success (0)`

## Evidence
- Aggregate run log: `/tmp/20260304-050340Z-final-chaos-suite.log`
- Metrics files:
  - `docs/ami-client/chaos/metrics/20260304-050232Z-metrics-s1.md`
  - `docs/ami-client/chaos/metrics/20260304-050232Z-metrics-s2.md`
  - `docs/ami-client/chaos/metrics/20260304-050232Z-metrics-s3.md`
  - `docs/ami-client/chaos/metrics/20260304-050232Z-metrics-s4.md`
  - `docs/ami-client/chaos/metrics/20260304-050232Z-metrics-s5.md`
  - `docs/ami-client/chaos/metrics/20260304-050232Z-metrics-s6.md`
  - `docs/ami-client/chaos/metrics/20260304-050232Z-metrics-s7.md`
  - `docs/ami-client/chaos/metrics/20260304-050232Z-metrics-s8.md`
  - `docs/ami-client/chaos/metrics/20260304-050232Z-metrics-s9.md`
  - `docs/ami-client/chaos/metrics/20260304-050232Z-metrics-s10.md`
  - `docs/ami-client/chaos/metrics/20260304-050232Z-metrics-s11.md`
  - `docs/ami-client/chaos/metrics/20260304-050232Z-metrics-s12.md`
  - `docs/ami-client/chaos/metrics/20260304-050232Z-metrics-s13.md`

## Verdict
- `FAIL (environment-limited)`
- Outside-sandbox rerun is required for production-readiness gate closure.
