# Invariants Audit

- Timestamp (UTC): `2026-03-04T05:32:58Z`
- Prompt phase: `Phase 2 — Correctness & Safety Invariants`

## I1 Framing Safety

- Status: **PASS**
- Evidence:
  - Delimiter support `\r\n\r\n` and `\n\n`: `src/Protocol/Parser.php:136-151`
  - Max frame size enforcement: `src/Protocol/Parser.php:162-164`
  - Parser buffer cap + desync exception: `src/Protocol/Parser.php:82-93`
  - No-delimiter safety recovery: `src/Protocol/Parser.php:95-106`
  - Recovery path: `src/Protocol/Parser.php:250-260`

## I2 Backpressure Safety

- Status: **PASS**
- Evidence:
  - Write buffer hard cap + explicit `BackpressureException`: `src/Transport/WriteBuffer.php:17-39`
  - Buffer overflow forces transport termination path: `src/Transport/TcpTransport.php:185-190`
  - Partial writes handled via `advance($written)`: `src/Transport/TcpTransport.php:366-368`
  - Event queue bounded with drop-oldest + counter metric: `src/Core/EventQueue.php:46-55`

## I3 Correlation Determinism

- Status: **PASS (with hardening gap)**
- Evidence:
  - ActionID generation globally unique, server-aware, ASCII-safe and bounded: `src/Correlation/ActionIdGenerator.php:38-64`, `src/Correlation/ActionIdGenerator.php:96-111`
  - Pending timeout cleanup deterministic: `src/Correlation/CorrelationRegistry.php:186-200`, `src/Correlation/CorrelationRegistry.php:271-276`
  - `Response: Error` mapped to typed failure: `src/Correlation/CorrelationRegistry.php:112-123`
  - Unmatched responses are diagnostic/non-fatal: `src/Correlation/CorrelationRegistry.php:301-315`

Hardening gap recorded for findings:
- Correlation matching trusts ActionID presence/equality and does not explicitly validate server-segment ownership before match/complete (`src/Correlation/CorrelationRegistry.php:98-126`).

## I4 Fairness / Starvation Prevention

- Status: **PASS**
- Evidence:
  - Per-client frame/event budgets in tick: `src/Core/AmiClient.php:394-397`, `src/Core/AmiClient.php:562-563`
  - Per-transport bytes-read-per-tick budget: `src/Transport/TcpTransport.php:302-307`
  - Cluster connect attempts budget + round-robin reconnect cursor: `src/Cluster/AmiClientManager.php:254-279`
  - Config-level budget validation: `src/Cluster/ClientOptions.php:108-115`

## I5 Reconnect Herd Control

- Status: **PASS**
- Evidence:
  - Reconnect backoff + jitter config: `src/Health/ConnectionManager.php:37-40`
  - Per-tick reconnect limit: `src/Health/ConnectionManager.php:313-315`
  - Cluster-level connect budget coordination: `src/Cluster/AmiClientManager.php:251-277`
  - Reconnect timeout scheduling paths: `src/Core/AmiClient.php:434-480`, `src/Core/AmiClient.php:486-517`

## Invariant Cap Outcome

- No invariant is failed.
- **No score cap applied**.
