# Correctness and Safety Invariants Audit

- Timestamp (UTC): 2026-03-04T04:44:14Z
- Invariant verdict: I1 PASS, I2 PASS, I3 PASS, I4 PASS, I5 PASS
- Hard invariant cap: NOT APPLIED

## I1 Framing Safety: PASS
- Delimiter detection supports both CRLF and LF framing: `src/Protocol/Parser.php:136-151`
- Max frame size enforced with explicit exception: `src/Protocol/Parser.php:162-164`
- Parser buffer cap enforced: `src/Protocol/Parser.php:81-93`
- Desync recovery implemented: `src/Protocol/Parser.php:250-263`
- Test evidence:
  - `tests/Unit/Protocol/ParserHardeningTest.php:62-76`
  - `tests/Unit/Protocol/ParserHardeningTest.php:110-137`
  - `tests/Integration/ParserCorruptionTest.php:40-60`

## I2 Backpressure Safety: PASS
- Write buffer hard cap + explicit `BackpressureException`: `src/Transport/WriteBuffer.php:30-39`
- Partial write accounting via `advance($written)`: `src/Transport/TcpTransport.php:366-368`
- Event queue bounded and drop-oldest with counter/metric: `src/Core/EventQueue.php:48-55`
- Correlation event overflow drops with warning + metric: `src/Correlation/CorrelationRegistry.php:150-160`
- Test evidence:
  - `tests/Unit/Transport/WriteBufferTest.php:48`
  - `tests/Integration/FloodSimulationTest.php:55-68`
  - `tests/Unit/Correlation/CorrelationRegistryTest.php:381-400`

## I3 Correlation Determinism: PASS (with hardening gap)
- ActionID generation centralized: `src/Correlation/ActionIdGenerator.php:36-62`
- Pending registry enforces deterministic register/complete/fail/cleanup:
  - Register: `src/Correlation/CorrelationRegistry.php:67-93`
  - Complete/fail/cleanup: `src/Correlation/CorrelationRegistry.php:239-276`
  - Timeout sweep: `src/Correlation/CorrelationRegistry.php:186-199`
- `Response: Error` is rejected as typed exception, not success: `src/Correlation/CorrelationRegistry.php:113-121`
- Unmatched responses are diagnostic warnings and non-fatal: `src/Correlation/CorrelationRegistry.php:301-315`
- Test evidence:
  - `tests/Unit/Correlation/PermissionErrorCorrelationTest.php:24-40`
  - `tests/Unit/Correlation/PermissionErrorCorrelationTest.php:45-74`
  - `tests/Unit/Correlation/CorrelationRegistryTest.php:82-90`
- Gap: ASCII-safe ActionID is not explicitly enforced/sanitized (see findings).

## I4 Fairness / Starvation Prevention: PASS
- Per-tick budgets configured and validated:
  - `src/Cluster/ClientOptions.php:75-82`
  - `src/Cluster/ClientOptions.php:108-114`
- Client tick frame/event budgets:
  - `src/Core/AmiClient.php:392-395`
  - `src/Core/AmiClient.php:559-560`
- Cluster reconnect fairness via rotating cursor and shared connect budget:
  - `src/Cluster/AmiClientManager.php:249-263`
- Test evidence:
  - `tests/Unit/Cluster/FairnessTest.php:23-115`
  - `tests/Integration/ReconnectFairnessTest.php:20`
  - `tests/Performance/FloodSimulationTest.php:22-95`

## I5 Reconnect Herd Control: PASS
- Exponential backoff + jitter:
  - `src/Health/ConnectionManager.php:352-367`
- Per-tick connect attempt cap:
  - `src/Health/ConnectionManager.php:313-315`
  - `src/Cluster/AmiClientManager.php:247-263`
- No tight-loop reconnect; next reconnect time is scheduled:
  - `src/Health/ConnectionManager.php:317-322`
  - `src/Health/ConnectionManager.php:333-335`
- Test evidence:
  - `tests/Integration/ReconnectStormTest.php:26-94`
  - `tests/Integration/NonBlockingConnectTest.php:228`

## Verdict
- No invariant failures detected.
- Score cap conditions were not triggered.
