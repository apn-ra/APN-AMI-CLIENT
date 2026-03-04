# Invariants Audit

- Timestamp (UTC): `2026-03-04T05:47:04Z`
- Prompt phase: `Phase 2`

## I1 Framing Safety
- Status: **PASS**
- Evidence: `src/Protocol/Parser.php:136-167`, `src/Protocol/Parser.php:82-107`

## I2 Backpressure Safety
- Status: **PASS**
- Evidence: `src/Transport/WriteBuffer.php:30-42`, `src/Transport/TcpTransport.php:185-190`, `src/Core/EventQueue.php:46-55`

## I3 Correlation Determinism
- Status: **PASS**
- Evidence:
  - Error response rejection remains typed: `src/Correlation/CorrelationRegistry.php:116-125`
  - Unmatched response diagnostics preserved: `src/Correlation/CorrelationRegistry.php:301-323`
  - New explicit server-segment defensive validation:
    - response path: `src/Correlation/CorrelationRegistry.php:105-108`
    - event path: `src/Correlation/CorrelationRegistry.php:145-148`
    - mismatch diagnostics/metric: `src/Correlation/CorrelationRegistry.php:360-377`

## I4 Fairness / Starvation Prevention
- Status: **PASS**
- Evidence: `src/Core/AmiClient.php:394-397`, `src/Core/AmiClient.php:562-563`, `src/Cluster/AmiClientManager.php:254-279`

## I5 Reconnect Herd Control
- Status: **PASS**
- Evidence: `src/Health/ConnectionManager.php:37-40`, `src/Health/ConnectionManager.php:313-315`, `src/Cluster/AmiClientManager.php:251-277`

## Invariant Cap Outcome

- No invariant failures.
- **No score cap applied**.
