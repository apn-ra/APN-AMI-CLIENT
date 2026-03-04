# Test Coverage Audit

- Timestamp (UTC): 2026-03-04T05:06:18Z
- Prompt phase: 4

## Coverage Evidence by Required Area

### Parser: delimiter/caps/resync/duplicates
- Unit parser hardening suite: `tests/Unit/Protocol/ParserHardeningTest.php:26-138`
- Core parser suite includes desync recovery and duplicate semantics: `tests/Unit/Protocol/ParserTest.php:154`, `178`, `261`
- Integration parser corruption and chaos scenarios:
  - `tests/Integration/ParserCorruptionTest.php:40`
  - `tests/Integration/ChaosParserRecoveryTest.php:15-61`
- Status: covered

### Correlation: timeouts/error mapping/unmatched response
- Registry tests for timeout, unmatched, missing response:
  - `tests/Unit/Correlation/CorrelationRegistryTest.php:79-97`, `188-198`, `235-251`
- Permission/error mapping tests:
  - `tests/Unit/Correlation/PermissionErrorCorrelationTest.php:40`, `45-54`, `61-74`
- Stress/out-of-order integration:
  - `tests/Integration/CorrelationStormTest.php:14`, `72-73`
- Status: covered

### Completion strategies: follows, multi-event, async
- Follows strategy tests: `tests/Unit/Protocol/Strategies/FollowsResponseStrategyTest.php:14-65`
- Multi-event/async integration coverage:
  - `tests/Unit/Correlation/CompletionStrategyIntegrationTest.php:53`, `76-95`
  - `tests/Integration/AsyncOriginateCompletionTest.php:14-58`
- Status: covered

### Multi-server fairness
- Unit fairness: `tests/Unit/Cluster/FairnessTest.php:21`
- Integration reconnect fairness/storm controls:
  - `tests/Integration/ReconnectFairnessTest.php:18-63`
  - `tests/Integration/ReconnectStormTest.php:99-166`
- Flood simulation: `tests/Performance/FloodSimulationTest.php:20`, `83`
- Status: covered

### Reconnect storm
- Backoff+jitter validation and cluster cap assertions: `tests/Integration/ReconnectStormTest.php:26-78`, `99-166`
- Status: covered

### Chaos suite existence/runnability
- Exists: `docs/ami-client/chaos/scenarios/*.json` and runner `tests/Chaos/run_scenario.php`.
- Latest final chaos artifact currently fails in this environment: `docs/ami-client/chaos/reports/20260304-050232Z-final-chaos-suite-results.md`.
- Status: exists, but latest run not green.

## Runtime Verification This Pass
- Command: `vendor/bin/phpunit`
- Result: `Tests: 296, Assertions: 2001967, Errors: 4, Failures: 29, Warnings: 7, Skipped: 2`
- Dominant signatures:
  - `Unable to start fake AMI server: Success (0)`
  - `stream_socket_server(): Unable to connect to tcp://127.0.0.1:0 (Success)`
- Classification: `SANDBOX_ENVIRONMENT` limitation (socket server startup unavailable in this runtime).

## Coverage Verdict
- Test breadth is strong and aligned to dialer-grade concerns.
- Gate remains blocked by execution environment constraints and latest chaos status, not by obvious missing suites.
