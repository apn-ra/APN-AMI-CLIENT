# Invariants Audit

- Timestamp (UTC): 2026-03-04T05:06:18Z
- Prompt phase: 2 (I1-I5)

## I1 Framing Safety
- Delimiter handling (`\r\n\r\n` and fallback `\n\n`): `src/Protocol/Parser.php:134-153`
- Max frame size enforced: `src/Protocol/Parser.php:163-165`
- Parser buffer cap enforced: `src/Protocol/Parser.php:79-92`
- Desync recovery exists: `src/Protocol/Parser.php:94-104`, `244-263`
- Test coverage:
  - `tests/Unit/Protocol/ParserHardeningTest.php:26-58`, `60-68`, `110-117`, `119-138`
  - `tests/Integration/ParserCorruptionTest.php:40`
- Result: PASS

## I2 Backpressure Safety
- Write buffer hard cap + typed exception: `src/Transport/WriteBuffer.php:30-39`
- Transport terminates on write-buffer overflow: `src/Transport/TcpTransport.php:185-191`
- Partial writes handled via `advance($written)`: `src/Transport/TcpTransport.php:351-368`
- Event queue bounded with drop-oldest policy + metric: `src/Core/EventQueue.php:25`, `48-55`
- Result: PASS

## I3 Correlation Determinism
- ActionID uniqueness + server-aware format + ASCII normalization: `src/Correlation/ActionIdGenerator.php:43-64`, `96-111`
- Length bound enforced by generator max: `src/Correlation/ActionIdGenerator.php:13-16`, `29-32`, `44-50`
- Response `Error` mapped to typed failure: `src/Correlation/CorrelationRegistry.php:112-122`
- Deterministic timeout cleanup: `src/Correlation/CorrelationRegistry.php:186-200`, `271-276`
- Unmatched responses are diagnostics only: `src/Correlation/CorrelationRegistry.php:101-104`, `301-315`
- Tests:
  - `tests/Unit/Correlation/CorrelationRegistryTest.php:79-97`, `188-198`, `235-251`
  - `tests/Integration/CorrelationStormTest.php:72-73`
- Result: PASS

## I4 Fairness / Starvation Prevention
- Per-tick budgets in client options: `src/Cluster/ClientOptions.php:41-47`, `108-115`
- Client frame/event budget loops: `src/Core/AmiClient.php:392-395`, `559-560`
- Cluster connect-attempt cap and round-robin reconnect cursor: `src/Cluster/AmiClientManager.php:246-263`
- Tests:
  - `tests/Unit/Cluster/FairnessTest.php:21`
  - `tests/Integration/ReconnectFairnessTest.php:22`, `61-63`
  - `tests/Integration/ReconnectStormTest.php:99-166`
- Result: PASS

## I5 Reconnect Herd Control
- Exponential backoff with jitter: `src/Health/ConnectionManager.php:352-367`
- Per-tick reconnect attempt budget: `src/Health/ConnectionManager.php:313-315`, `347-350`
- Cluster-level connect-attempt ceiling: `src/Cluster/AmiClientManager.php:246-258`
- Tests:
  - `tests/Integration/ReconnectStormTest.php:26-78`
  - `tests/Integration/ReconnectStormTest.php:99-166`
- Result: PASS

## Invariant Gate Outcome
- I1-I5 invariant failures: none identified from code and tests.
- Invariant score cap triggered: no.
- Note: overall release gate remains blocked by latest chaos status FAIL (environment-limited) from Phase 0.
