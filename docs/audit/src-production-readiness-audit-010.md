# Production Readiness Audit (NBRC) — src/

Date: 2026-02-27
Scope: `src/`, `config/`, `composer.json`, `docs/contracts/non-blocking-runtime-contract.md`

---

## 1. Executive Summary

**Verdict:** Architecturally Unstable (NBRC hard invariants violated)

**Production-readiness:** 64%

**Top 3 strengths**
- Single-selector multiplexing via `Reactor` enforces cross-node fairness and avoids per-node `stream_select` calls. (`src/Transport/Reactor.php`)
- Bounded buffers/queues are in place: `WriteBuffer`, `EventQueue`, parser buffer caps, and correlation max pending limits. (`src/Transport/WriteBuffer.php`, `src/Core/EventQueue.php`, `src/Protocol/Parser.php`, `src/Correlation/CorrelationRegistry.php`)
- Async connect verification is implemented with socket helper + fallback probes. (`src/Transport/TcpTransport.php`)

**Top 5 blockers**
1. **P0** — Timeout contract is silently clamped to `0` across manager/reactor/transport; `tickAll()` ignores caller timeout. (`src/Cluster/AmiClientManager.php:275-284`, `src/Transport/Reactor.php:220-231`, `src/Transport/TcpTransport.php:369-380`)
2. **P1** — Worker cadence violates NBRC: `ami:listen` uses `tickAll(0)` plus fixed sleep without idle detection; no bounded wait in `stream_select`. (`src/Laravel/Commands/ListenCommand.php:55-152`)
3. **P1** — No explicit tick progress summary or idle signal for cadence policy; `tick()` returns `void` and `processTick()` only reports connect attempts. (`src/Core/AmiClient.php:309-341`, `src/Core/Contracts/AmiClientInterface.php:49-69`)
4. **P1** — Hostname mode can still resolve via `stream_socket_client()` inside `open()` if `TcpTransport` is used directly with `enforceIpEndpoints=false` (DNS in tick path). (`src/Transport/TcpTransport.php:63-88`)
5. **P2** — Required NBRC observability metrics (`tick_duration_ms`, `tick_progress`, `selector_wait_ms`, etc.) are not emitted anywhere in core paths. (`src/*`, no matches for `tick_*` metrics)

**NBRC compliance status:** Non-compliant (hard invariants violated)

**Timeout contract status:** **Silently clamped** (P0)

**Cadence status:** **Non-compliant** (no idle detection; no bounded selector wait)

---

## 2. NBRC Compliance Matrix (MANDATORY)

| NBRC ID | Status | Evidence | Severity |
| --- | --- | --- | --- |
| NB-01 | **Partial** | `TcpTransport::open()` allows hostnames when `enforceIpEndpoints=false`; `stream_socket_client()` can trigger DNS in reconnect/tick paths. | P1 |
| NB-02 | **Pass** | `AmiClientManager::tickAll()` delegates to single `Reactor::tick()` which runs one `stream_select` over all transports. (`src/Cluster/AmiClientManager.php:216-265`, `src/Transport/Reactor.php:60-121`) | P3 |
| NB-03 | **Pass** | Budgets enforced: `maxBytesReadPerTick` in `TcpTransport::read()`, `maxFramesPerTick`/`maxEventsPerTick` in `AmiClient::processTick()`, `maxConnectAttemptsPerTick` in manager loop. | P3 |
| NB-04 | **Pass** | Non-graceful close clears write buffer to prevent cross-session replay. (`src/Transport/TcpTransport.php:127-136`, `src/Core/AmiClient.php:1088-1120`) | P3 |
| NB-20 | **Fail** | Worker cadence uses fixed sleep after every loop; no bounded selector wait or idle-aware yield. (`src/Laravel/Commands/ListenCommand.php:55-152`) | P1 |
| NB-21 | **Fail** | Neither Mode A (bounded selector wait) nor Mode B (idle-only yield) is implemented. | P1 |
| NB-22 | **Fail** | No explicit idle/progress signal in tick APIs; `ami:listen` yields blindly. (`src/Core/AmiClient.php:309-341`, `src/Laravel/Commands/ListenCommand.php:55-152`) | P1 |
| NB-30 | **Fail** | `timeoutMs` is accepted but always clamped to `0` silently in manager/reactor/transport. (`src/Cluster/AmiClientManager.php:275-284`, `src/Transport/Reactor.php:220-231`, `src/Transport/TcpTransport.php:369-380`) | **P0** |
| NB-31 | **Fail** | No enforcement of a bounded timeout range because Contract 1 is not implemented; `timeoutMs` is always zeroed. | P0 |
| NB-32 | **Pass** | Core remains non-blocking in selector usage (bounded by 0ms currently). | P3 |
| NB-40 | **Pass** | Async connect uses `STREAM_CLIENT_ASYNC_CONNECT` and verification on write readiness. (`src/Transport/TcpTransport.php:80-362`) | P3 |
| NB-41 | **Pass** | Connect timeout enforced via `ConnectionManager::isConnectTimedOut()` with wall-clock checks. (`src/Health/ConnectionManager.php:150-175`) | P3 |
| NB-42 | **Pass** | `close()` is immediate and non-blocking; graceful logoff is queued via tick. (`src/Transport/TcpTransport.php:127-139`, `src/Core/AmiClient.php:1000-1120`) | P3 |
| NB-50 | **Pass** | Core uses PSR-3 logger; no direct stdout writes in tick paths. (`src/Core/AmiClient.php`, `src/Cluster/AmiClientManager.php`) | P3 |
| NB-51 | **Partial** | Internal `Logger` swallows errors, but external PSR-3 loggers are invoked without safety wrappers in tick paths. | P2 |

---

## 3. Timeout Contract Drift Detection

**Status:** **Silently clamped (P0)**

Evidence:
- `AmiClientManager::normalizeRuntimeTimeoutMs()` always returns `0`. (`src/Cluster/AmiClientManager.php:275-284`)
- `Reactor::normalizeTimeoutMs()` always returns `0`. (`src/Transport/Reactor.php:220-231`)
- `TcpTransport::normalizeTimeoutMs()` always returns `0`. (`src/Transport/TcpTransport.php:369-380`)
- `ami:listen` reads `tick-timeout-ms` but always calls `tickAll(0)` or `tick(..., 0)`. (`src/Laravel/Commands/ListenCommand.php:55-61`)

This violates NBRC NB-30 (must honor or reject), and is a **hard invariant failure**.

---

## 4. Cadence & Hot-Spin Detection

- **Hot-spin under idle:** Not observed in current worker because `ami:listen` always sleeps to fill cadence. However, it does so **unconditionally**, not based on idle detection.
- **Idle detection:** **Missing.** There is no progress signal in `tick()`/`tickAll()`; worker cannot tell if progress occurred.
- **Bounded wait/yield:** **Non-compliant.** No bounded selector wait is used and Mode B is implemented without idle detection, violating NB-21/NB-22.

Result: **Cadence contract violated (P1)**.

---

## 5. Detailed Findings

### 5.1 P0 — Timeout contract silently clamped to `0`
- **Location:** `src/Cluster/AmiClientManager.php:275-284`, `src/Transport/Reactor.php:220-231`, `src/Transport/TcpTransport.php:369-380`, `src/Laravel/Commands/ListenCommand.php:55-61`
- **Dialer impact:** Worker cadence cannot honor caller-specified timeout; may hot-spin or underutilize I/O waits; violates expected API semantics and NBRC enforcement tests.
- **NBRC clauses:** NB-30, NB-31, NB-32
- **Fix direction:** Pick NBRC Contract 1 (honor bounded timeout) or Contract 2 (reject non-zero). Implement consistently across interfaces, manager/reactor/transport, and worker loop; update docs/tests.

### 5.2 P1 — Cadence policy violates NBRC (no idle-aware yield)
- **Location:** `src/Laravel/Commands/ListenCommand.php:55-152`
- **Dialer impact:** Throughput is reduced under load (blind sleeps), and idle handling cannot be verified via NBRC tests; cadence policy is not enforceable or observable.
- **NBRC clauses:** NB-20, NB-21, NB-22
- **Fix direction:** Implement Mode A (preferred) using `tickAll($timeoutMs)` with bounded selector wait, or Mode B with explicit idle detection and `usleep()` only when no progress.

### 5.3 P1 — No tick progress summary for idle detection
- **Location:** `src/Core/Contracts/AmiClientInterface.php:49-69`, `src/Core/AmiClient.php:309-341`
- **Dialer impact:** Worker cannot decide whether to yield; cadence policy cannot be enforced without guessing. Prevents NB-T01/NB-T02 style tests.
- **NBRC clauses:** NB-22, NB-08 (observability prerequisites)
- **Fix direction:** Return a structured progress summary from `tick()`/`tickAll()` (bytes read/written, frames parsed, events dispatched, state transitions, reconnect attempts) and use it in worker loop for idle detection.

### 5.4 P1 — Hostname mode can still invoke DNS inside tick path
- **Location:** `src/Transport/TcpTransport.php:63-88`
- **Dialer impact:** If `TcpTransport` is instantiated directly with hostnames and `enforceIpEndpoints=false`, DNS resolution can occur in the reconnect/tick path, violating non-blocking guarantees.
- **NBRC clauses:** NB-01, NB-11
- **Fix direction:** Require pre-resolved IPs or injected resolver at construction time; make hostname resolution impossible in `open()` unless resolver provided outside tick; enforce rejection when policy is violated.

### 5.5 P2 — Missing NBRC-required tick observability metrics
- **Location:** `src/*` (no `tick_duration_ms`, `tick_progress`, `selector_wait_ms`, `tick_bytes_read`, `tick_events_dispatched`, etc.)
- **Dialer impact:** Inability to verify cadence, detect hot-spin, or confirm non-blocking behavior in production; acceptance tests NB-T01..NB-T05 cannot be instrumented reliably.
- **NBRC clauses:** Section 8 (Required Runtime Observability)
- **Fix direction:** Emit required metrics in tick/tickAll/worker paths; include per-node labels and tick progress status.

### 5.6 P2 — Logger safety depends on injected logger implementation
- **Location:** Multiple call sites; e.g. `src/Core/AmiClient.php:356-381`, `src/Cluster/AmiClientManager.php:228-263`
- **Dialer impact:** If injected logger throws, tick loop can be disrupted; violates NBRC logger safety expectations.
- **NBRC clauses:** NB-51
- **Fix direction:** Wrap logger calls in a safety helper (try/catch) or enforce a non-throwing logger adapter in core.

---

## 6. Production Readiness Score

| Percentage | Classification |
| --- | --- |
| 64% | Architecturally Unstable |

**Notes:** Score capped below 69% due to timeout contract drift (NB-30). Hard invariant violations block production readiness.

---

STAGE 1 COMPLETE
