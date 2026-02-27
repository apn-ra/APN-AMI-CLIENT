# Production Readiness Audit — src/ (NBRC)

Date: 2026-02-27
Scope: `src/`, `docs/contracts/non-blocking-runtime-contract.md`

## 1) Executive Summary
Status: **Not Ready**

Top blockers:
- **P0:** Timeout contract is silently clamped to 0 in core/manager/reactor/transport, violating NB-30 and NB-01.
- **P0:** Hostname endpoints can trigger blocking DNS inside the tick-driven connect path when `TcpTransport` is used directly with `enforceIpEndpoints=false`.
- **P1:** Worker cadence yields blindly and cannot detect idle vs progress; tick APIs return no progress summary (NB-20..NB-22).

NBRC compliance status: **Non-compliant** due to hard invariant violations.

## 2) NBRC Compliance Matrix
| NBRC Clause | Status | Evidence |
|---|---|---|
| NB-01 No blocking calls in core tick path | **Fail** | `TcpTransport::open()` allows hostname resolution via `stream_socket_client` when `enforceIpEndpoints=false`, which performs blocking DNS in tick-driven reconnects. `src/Transport/TcpTransport.php:63-88` |
| NB-02 Single selector per tickAll | **Pass** | `AmiClientManager::tickAll()` uses `Reactor::tick()` once per call. `src/Cluster/AmiClientManager.php:216-240` |
| NB-03 Budgeted tick work | **Partial** | Per-client budgets exist for frames/events, but tick progress summary is missing and bytes read budget is enforced only at transport level. `src/Core/AmiClient.php:342-515`, `src/Transport/TcpTransport.php:254-289` |
| NB-04 Session boundary safety | **Pass** | `close(false)` clears write buffer; non-graceful paths call it. `src/Transport/TcpTransport.php:127-140` |
| NB-10 IP-only default | **Pass (Manager path)** | Manager rejects non-IP when `enforceIpEndpoints=true`. `src/Cluster/AmiClientManager.php:555-578` |
| NB-11 Hostname resolver required | **Fail (Transport direct use)** | `TcpTransport::open()` does not require resolver and allows hostname when `enforceIpEndpoints=false`. `src/Transport/TcpTransport.php:65-88` |
| NB-20 Cadence strategy mandatory | **Fail** | Worker loop uses fixed sleep without idle detection. `src/Laravel/Commands/ListenCommand.php:55-152` |
| NB-21 Allowed cadence modes | **Fail** | No Mode A wait; Mode B used without idle detection or progress summary. `src/Laravel/Commands/ListenCommand.php:55-152` |
| NB-22 Idle detection explicit | **Fail** | `tick()`/`tickAll()` return void, no progress summary for idle detection. `src/Core/AmiClient.php:309-326`, `src/Cluster/AmiClientManager.php:216-265` |
| NB-30 Timeout contract chosen/enforced | **Fail** | Timeout silently clamped to 0 across core/manager/reactor/transport. `src/Cluster/AmiClientManager.php:275-284`, `src/Transport/Reactor.php:48-56,220-230`, `src/Transport/TcpTransport.php:197-204,369-379` |
| NB-50/51 Logger non-throwing | **Partial** | Logger usage is guarded in some places; no explicit non-throwing wrapper for all tick path logging. (No dedicated logger wrapper enforcing NB-51.) |
| NB-08 Required observability | **Fail** | Required tick metrics (tick_duration_ms, tick_progress, selector_wait_ms, idle_yield_count, etc.) are not emitted. `rg -n "tick_|idle_yield|selector_wait" src` returned no matches. |

## 3) Timeout Contract Drift Detection
- **Silent clamping detected**: `normalizeRuntimeTimeoutMs()` in `AmiClientManager` returns 0 for any positive input; `Reactor::normalizeTimeoutMs()` and `TcpTransport::normalizeTimeoutMs()` do the same. This violates NB-30 and NB-31, and contradicts the listen command’s `tick-timeout-ms` configuration which implies a cadence timeout. See:
  - `src/Cluster/AmiClientManager.php:275-285`
  - `src/Transport/Reactor.php:48-56,220-230`
  - `src/Transport/TcpTransport.php:197-204,369-379`
- **Worker timeout mismatch**: `ListenCommand` accepts `--tick-timeout-ms` but does not pass it to `tickAll()`/`tick()`. It uses `usleep` after each tick unconditionally, so core timeout semantics are bypassed. `src/Laravel/Commands/ListenCommand.php:55-152`

## 4) Cadence / Hot-Spin Detection
- **Hot-spin risk**: If external callers use `tickAll(0)` in a tight loop (non-Laravel worker), there is no built-in idle yield. In the Laravel worker, cadence uses unconditional sleep and does not rely on idle detection.
- **NB-22 violation**: No explicit progress summary is returned by `tick()`/`tickAll()` to drive idle yield decisions; yields happen regardless of progress.

## 5) Detailed Findings (with file + line)

### P0 — Silent timeout clamping across runtime tick APIs
- **Problem**: `timeoutMs > 0` is silently clamped to 0 in core/manager/reactor/transport, violating NB-30 and NB-31 and creating misleading API semantics.
- **Dialer impact**: Timeouts requested by worker loops are ignored, leading to hot-spin or external sleeps, and inconsistent latency under load.
- **Evidence**:
  - `src/Cluster/AmiClientManager.php:275-285`
  - `src/Transport/Reactor.php:48-56,220-230`
  - `src/Transport/TcpTransport.php:197-204,369-379`
- **NBRC clauses**: NB-30, NB-31, NB-01

### P0 — Blocking DNS possible in tick-driven reconnect when TcpTransport used directly
- **Problem**: `TcpTransport::open()` allows hostnames when `enforceIpEndpoints=false` and directly calls `stream_socket_client()` with the hostname, which performs blocking DNS resolution. This can occur in tick-driven reconnect paths.
- **Dialer impact**: Tick loop can block on DNS under network failure or resolver slowness, violating non-blocking guarantees.
- **Evidence**: `src/Transport/TcpTransport.php:63-88`
- **NBRC clauses**: NB-01, NB-10, NB-11

### P1 — Worker cadence yields blindly; no idle detection or progress summary
- **Problem**: `ListenCommand` always sleeps for remaining cadence after each iteration, regardless of progress. `tick()`/`tickAll()` return void; no progress summary exists to drive idle detection.
- **Dialer impact**: Throughput throttled under load; violates NB-20..NB-22 and prevents correct idle-yield logic.
- **Evidence**:
  - `src/Laravel/Commands/ListenCommand.php:55-152`
  - `src/Core/AmiClient.php:309-326`
  - `src/Cluster/AmiClientManager.php:216-265`
- **NBRC clauses**: NB-20, NB-21, NB-22

### P2 — Missing required runtime observability metrics
- **Problem**: Required metrics/logs (tick_duration_ms, tick_progress, selector_wait_ms, idle_yield_count, etc.) are not emitted.
- **Dialer impact**: Operational visibility gaps; cannot enforce NBRC requirements in production.
- **Evidence**: No matching metrics in `src/` (`rg -n "tick_|idle_yield|selector_wait" src` returned none).
- **NBRC clauses**: NB-08

### P2 — Timeout contract messaging inconsistent with worker configuration
- **Problem**: Interface and comments say timeouts are clamped to 0, but the Laravel worker exposes `--tick-timeout-ms` as cadence. This is a mismatch between public API and runtime behavior.
- **Dialer impact**: Operators believe they are configuring tick waits; instead they configure post-tick sleep, masking latency behavior.
- **Evidence**:
  - `src/Core/Contracts/AmiClientInterface.php:52-57`
  - `src/Laravel/Commands/ListenCommand.php:40-152`
- **NBRC clauses**: NB-30

## 6) Production Readiness Score
**Score: 55 / 100 — Not Ready**

Rationale:
- Multiple NBRC hard invariant violations (timeout clamping, blocking DNS risk).
- Missing cadence correctness and required observability.
- Some bounded-memory and multi-server isolation protections are in place.

## Required Audit Disclosures
- **NBRC compliance status**: Non-compliant (P0 violations present).
- **Timeout contract drift**: Present (silent clamping + worker mismatch).
- **Cadence/hot-spin risk**: Present (no idle detection; external loops can hot-spin).
- **Cross-node contamination risk**: Low (ActionID generator is server-key scoped; registries are per-client).
- **Memory bound enforcement**: Mostly enforced (EventQueue, WriteBuffer, Parser, CorrelationRegistry are capped).
- **Reconnect fairness**: Present (reconnect cursor rotates in `AmiClientManager::tickAll()`).

## Readiness Classification
**Not Ready** (NBRC violations cap readiness below 80%).
