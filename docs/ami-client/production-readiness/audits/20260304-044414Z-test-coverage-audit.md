# Test and Verification Coverage Audit

- Timestamp (UTC): 2026-03-04T04:44:14Z

## Runtime Evidence
- Full suite command: `vendor/bin/phpunit --colors=never`
- In-sandbox run: failed with local socket bind errors (`stream_socket_server(): Unable to connect to tcp://127.0.0.1:0 (Success)`).
- Outside-sandbox re-run: `OK, but some tests were skipped!` (285 tests, 2002025 assertions, 1 skipped).
- Classification per sandbox policy: `SANDBOX_ENVIRONMENT` for the in-sandbox socket failures; verified as environment limitation because outside-sandbox run passes.

## Required Coverage Mapping

### Parser tests (delimiter + caps + resync + duplicates)
- `tests/Unit/Protocol/ParserTest.php:154-197`
- `tests/Unit/Protocol/ParserHardeningTest.php:62-137`
- `tests/Integration/ParserCorruptionTest.php:40-106`

### Correlation tests (timeouts + error mapping + unmatched)
- `tests/Unit/Correlation/CorrelationRegistryTest.php:82-90`
- `tests/Unit/Correlation/PermissionErrorCorrelationTest.php:24-74`
- `tests/Integration/CorrelationStormTest.php:27-72`

### Completion strategies (follows + multi-event + single)
- `tests/Unit/Protocol/Strategies/FollowsResponseStrategyTest.php:16-62`
- `tests/Unit/Correlation/CompletionStrategyIntegrationTest.php:22-51`
- `tests/Unit/Protocol/ExtendedActionsTest.php:27-95`

### Multi-server fairness
- `tests/Unit/Cluster/FairnessTest.php:23-115`
- `tests/Integration/ReconnectFairnessTest.php:20`
- `tests/Performance/FloodSimulationTest.php:22-95`

### Reconnect storm tests
- `tests/Integration/ReconnectStormTest.php:26-94`
- `tests/Integration/NonBlockingConnectTest.php:183-228`

### Chaos suite availability and consistency
- Existing final artifact: `docs/ami-client/chaos/reports/20260304-043335Z-final-chaos-suite-results.md`
- Fresh suite re-run: all S1..S13 `PASS`.

## Gaps
- No dedicated `AsyncEventStrategy` class/test was found; async originate behavior currently depends on existing strategy implementations and callbacks.

## Verdict
- Test coverage is strong for dialer-grade runtime paths.
- One P1 completeness gap remains in explicit async completion strategy coverage.
