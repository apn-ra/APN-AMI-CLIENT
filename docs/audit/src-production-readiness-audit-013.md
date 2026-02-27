# Production Readiness Audit — src/ (NBRC)

Date: 2026-02-27
Scope: `src/`, `config/`, `composer.json`, `docs/contracts/non-blocking-runtime-contract.md`

## 1) Executive Summary
Status: **Nearly Ready**

Top blocker:
- **P1:** NB-51 logger-failure containment is incomplete in runtime-path subcomponents (`TcpTransport`, `ConnectionManager`, `CorrelationRegistry`). Injected logger exceptions can still escape and terminate active tick flows.

NBRC compliance status: **Partially compliant** (single remaining P1 contract drift).

## 2) NBRC Compliance Matrix
| NBRC Clause | Status | Evidence |
|---|---|---|
| NB-01 No blocking calls in core tick path | **Pass** | Async non-blocking connect and explicit non-blocking mode; no runtime DNS resolution in tick path. `src/Transport/TcpTransport.php:70-85`, `src/Transport/TcpTransport.php:101-104`, `src/Transport/TcpTransport.php:120-153` |
| NB-02 Single selector per `tickAll()` | **Pass** | Reactor performs one selector call per tick and failure handler no longer performs secondary selector probes. `src/Transport/Reactor.php:56-99`, `src/Transport/Reactor.php:135-158` |
| NB-03 Budgeted per-tick work / fairness | **Pass** | Frame/event/connect budgets enforced with reconnect cursor fairness rotation. `src/Core/AmiClient.php:382-385`, `src/Core/AmiClient.php:549-576`, `src/Cluster/AmiClientManager.php:245-262` |
| NB-04 Session-boundary write safety | **Pass** | Non-graceful close clears write buffer and reconnect/error paths use `close(false)`. `src/Transport/TcpTransport.php:158-162`, `src/Transport/TcpTransport.php:259-261` |
| NB-10 IP-only default policy | **Pass** | Hostnames rejected when `enforceIpEndpoints=true`. `src/Transport/TcpTransport.php:127-133` |
| NB-11 Hostname mode requires injected resolver | **Pass** | Hostname mode fails fast if resolver absent and validates resolver output IP. `src/Transport/TcpTransport.php:135-150` |
| NB-20 Worker cadence required | **Pass** | Listen loop applies bounded cadence and only yields on explicit idle. `src/Laravel/Commands/ListenCommand.php:56-76` |
| NB-21 Allowed cadence modes | **Pass** | Mode A bounded selector wait (`tickAll/tick` with timeout) with idle-only deterministic sleep. `src/Laravel/Commands/ListenCommand.php:58-63`, `src/Laravel/Commands/ListenCommand.php:73-75` |
| NB-22 Explicit idle detection | **Pass** | Worker relies on `TickSummary::hasProgress()` before yielding. `src/Laravel/Commands/ListenCommand.php:73-76`, `src/Core/TickSummary.php:17-28` |
| NB-30 Timeout contract consistency | **Pass** | Contract 1 (honored timeout with bounded clamp) implemented across manager/reactor/transport. `src/Cluster/AmiClientManager.php:294-314`, `src/Transport/Reactor.php:239-259`, `src/Transport/TcpTransport.php:425-447` |
| NB-50 No synchronous stdout in tick path | **Pass** | Tick runtime uses PSR-3 logger; no direct stdout writes in core runtime paths. `src/Core/AmiClient.php:902-919`, `src/Cluster/AmiClientManager.php:656-673`, `src/Transport/Reactor.php:264-281` |
| NB-51 Logger must never throw | **Fail** | Runtime-path logging still has direct unguarded logger calls in transport/health/correlation hot paths. `src/Transport/TcpTransport.php:438-443`, `src/Transport/TcpTransport.php:594-598`, `src/Health/ConnectionManager.php:444`, `src/Correlation/CorrelationRegistry.php:132-137` |

## 3) Timeout Contract Drift Detection
- Timeout semantics remain aligned with NBRC Contract 1:
  - Negative values rejected.
  - Positive values honored up to bounded max.
  - Above-max values clamped with telemetry/logging.
- No timeout contract drift detected in API/runtime behavior.

## 4) Cadence / Hot-Spin Detection
- Worker cadence is explicitly bounded and idle-aware.
- No hot-spin loops detected in production worker path.
- Reactor and client tick loops are budgeted and selector-driven in active I/O paths.

## 5) Detailed Findings (with file + line)

### P1 — NB-51 logger containment gap in runtime subcomponents
- Problem: NB-51 requires logging failures to be fully contained. Several runtime logging sites invoke logger methods directly without local `try/catch` containment.
- Dialer impact: With throwing/injected logger backends, tick processing can fail during transport timeout clamping, transport error telemetry, circuit-state transitions, or correlation overflow handling.
- Evidence:
  - `src/Transport/TcpTransport.php:438-443`
  - `src/Transport/TcpTransport.php:594-598`
  - `src/Health/ConnectionManager.php:444`
  - `src/Correlation/CorrelationRegistry.php:132-137`
- NBRC clauses: NB-51

## 6) Production Readiness Score
**Score: 91 / 100 — Nearly Ready**

Rationale:
- No P0 hard-invariant violations identified.
- Core non-blocking, selector ownership, cadence, fairness, and bounded-memory invariants are in place.
- One high-severity runtime stability drift remains (NB-51), which must be closed for Production Ready classification.

## Required Audit Disclosures
- **NBRC compliance status:** Partially compliant (NB-51 fail).
- **Timeout contract drift:** Not detected.
- **Cadence/hot-spin risk:** Low.
- **Cross-node contamination risk:** Low (per-node client/correlation/queue isolation maintained).
- **Memory bound enforcement:** Enforced for event queue, parser, write buffer, and pending registry limits.
- **Reconnect fairness:** Enforced via capped connect attempts plus reconnect cursor advancement.

## Readiness Classification
**Nearly Ready**
