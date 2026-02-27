# Production Readiness Progress vs Looping Report

Assumption: audit ordering is inferred by filename sort (`src-production-readiness-audit-000.md` -> `009.md`) because explicit dates are mostly missing.

## A) Executive Summary
- **Verdict:** **MIXED (Progress present, but active LOOPING signals)**.
- Clear progress exists from early audits to mid-sequence: major P0/P1 categories from 000-005 (blocking connect, listener isolation, transport suppression) are later reduced or downgraded in 006 (`src-production-readiness-audit-000.md`, `002.md`, `005.md`, `006.md`, section 1).
- The same non-blocking runtime root cause reappears in **three consecutive audits** (007-009) under different wording: busy spin, blocking-timeout semantics, cadence hot-loop (`src-production-readiness-audit-007.md`, `008.md`, `009.md`, section 1 and findings).
- Severity and readiness scores are non-monotonic (88% in 006 -> 72% in 007 -> 86% in 008 -> 84% in 009), which is a loop/bounce pattern, not steady closure (`006.md`, `007.md`, `008.md`, `009.md`, section 1).
- Several tasks are marked completed for categories that still appear in latest audit 009 (notably cadence/timeout contract and config loading/validation), indicating closure criteria are not consistently preventing recurrence (`docs/tasks.md`, Append BATCH-PR-20260226-09 vs `src-production-readiness-audit-009.md`, findings 1-4).

## B) Timeline Table (oldest -> newest)

| Audit | Date (if known) | Verdict | Top P0/P1 blockers | Invariants failed | Notable deltas |
|---|---|---|---:|---:|---|
| `src-production-readiness-audit-000.md` | Unknown | Not Ready | 4 | Not explicitly enumerated (at least non-blocking) | Baseline severe blockers: blocking connect (P0), fairness, listener isolation, send gating |
| `src-production-readiness-audit-001.md` | Unknown | Nearly Ready | 2 | Not explicitly enumerated | Shift from transport/connect blockers to parser/correlation correctness |
| `src-production-readiness-audit-002.md` | Unknown | Nearly Ready (not prod-ready) | 3 | 2/10 fail | New high-severity correctness issues: orphan pending rollback, callback isolation |
| `src-production-readiness-audit-003.md` | Unknown | Not Ready (68%) | 2 | 1 fail | Fairness/correlation stronger; non-blocking invariant still failing |
| `src-production-readiness-audit-004.md` | Unknown | Not Ready (74%) | 1 | 1 fail | Narrowed primary failure to DNS/non-blocking invariant |
| `src-production-readiness-audit-005.md` | Unknown | Nearly Ready (84%) | 2 | 1 fail | Error suppression + logger-throw path become dominant blockers |
| `src-production-readiness-audit-006.md` | Unknown | Nearly Ready (88%) | 0 | 0 fail | Best state: all invariants passing, only P2 improvements |
| `src-production-readiness-audit-007.md` | Unknown | Not Ready (72%) | 4 | 1 fail | Regression spike: P0 cross-session replay + P1 hot-path logging/busy-spin |
| `src-production-readiness-audit-008.md` | Unknown | Nearly Ready (86%) | 1 | 1 fail | P0 cleared; non-blocking opt-out still unresolved |
| `src-production-readiness-audit-009.md` | Unknown | Nearly Ready (84%) | 1 | 0 fail | Invariants pass, but cadence/API/config contract gaps remain practical blockers |

## C) Root Cause Heatmap

| Root-cause category | Occurrences across audits | Latest severity seen | Evidence citations |
|---|---:|---|---|
| Non-blocking correctness (connect/DNS/close/hidden waits/cadence) | 9 | P1 | `000` P0 blocking connect; `002-004` DNS/close waits; `007-009` busy-spin/timeout-contract/cadence (`000.md`, `002.md`, `003.md`, `004.md`, `007.md`, `008.md`, `009.md`) |
| Reconnect fairness/starvation | 1 (as blocker), then repeatedly PASS | P1 historical -> PASS later | `000` fairness starvation blocker; `003/004/008/009` explicitly PASS |
| Correlation correctness (missing response/orphan pending/rollback) | 2 | P1 historical | `001` synthetic success risk; `002` rollback gap/orphan pending |
| Parser robustness (frame size/desync/config invariants) | 2 | P2 | `001` hard frame cap; `006` bufferCap vs frame-size misconfiguration |
| Listener/callback isolation | 4 | P2 | `000` listener isolation missing; `002` callback propagation; `005` logger throw breaks isolation; `006` callback-handler failure silence |
| Backpressure & queue bounds | 4 | P2 | `002` event queue capacity validation; `003` silent correlation drop; `007` per-drop log storms; `009` option bounds include queue limits |
| Metrics/observability wiring | 5 | P2 | `000` swallowed connect failures/queue context inconsistency; `002` NullMetrics wiring; `005` transport suppression; `008` logger drop visibility metric-only; `009` API contract ambiguity impacts operability |
| Security redaction/log safety | 4 | P2 | `001` narrow redaction; `004` value-redaction gap; `005` regex suppression + logger throw risk; `008` fallback path outside unified logger |
| ActionID constraints (length/uniqueness) | 1 | P2 | `001` unbounded ActionID length |

## D) Tasks vs Findings Alignment

| Category | Completed Tasks (from `docs/tasks.md`) | Still appears in latest audit (009)? | Notes |
|---|---|---|---|
| Non-blocking correctness | `PR-P0-02`, `PR3-P1-01`, `PR4-P1-01`, `PR7-P1-03`, `PR7-P2-01`, `PR8-P1-01`, `PR9-P1-01`, `PR9-P2-01` | **Yes** | 009 still flags cadence/hot-spin and timeout contract drift (Findings 1-2). Strong loop signal. |
| Reconnect fairness/starvation | `PR-P0-05` | No | Blocker from 000 appears resolved and remains PASS in later audits. |
| Correlation correctness | `PR2-P1-03`, `PR2-P1-04`, `PR3-P1-03` | No (in 009) | Earlier high-risk issues reduced; keep regression coverage. |
| Parser robustness | `PR2-P1-01`, `PR2-P1-02`, `PR6-P2-03`, `PR6-P2-04` | No (in 009) | 006 still had parser invariant gap before later batches. |
| Listener/callback isolation | `PR-P1-01`, `PR-P1-02`, `PR3-P1-04`, `PR5-P1-02`, `PR6-P2-01`, `PR6-P2-02` | No (in 009) | Category improved materially after recurring 000/002/005/006 findings. |
| Backpressure & queue bounds | `PR3-P2-02`, `PR7-P1-02`, `PR9-P2-03` | Indirectly yes | 009 flags numeric range validation and runtime stability impact; queue bounds are part of that contract. |
| Metrics/observability wiring | `PR3-P2-01`, `PR5-P1-01`, `PR8-P2-02`, `PR8-P3-02` | Partial | 009 focuses on cadence/API/config, but observability regressions repeatedly re-entered in 005/008. |
| Security redaction/log safety | `PR2-P2-02`, `PR2-P2-03`, `PR4-P3-01`, `PR4-P3-02`, `PR5-P2-01` | No (in 009) | Looks improved relative to 001/004/005. |
| ActionID constraints | `PR2-P2-04`, `PR2-P2-05` | No (in 009) | No recurrence after 001. |

## E) Loop Signals

- **Consecutive recurrence of same high-severity category after completed tasks:** non-blocking/cadence category appears in 007, 008, and 009 despite completed PR7/PR8/PR9 tasks addressing loop cadence and timeout semantics (`docs/tasks.md` Append BATCH-PR-20260226-07/08/09; `src-production-readiness-audit-007.md` finding 4, `008.md` finding 1, `009.md` findings 1-2).
- **Score/invariant bounce instead of stable improvement:** 006 reports 88% with 0 invariant failures, then 007 drops to 72% with a P0 and 1 invariant failure, then bounces (86%, 84%) (`006.md`, `007.md`, `008.md`, `009.md`, section 1 + invariants).
- **Repeated code areas under changed wording:** recurring mentions around `ListenCommand`, `AmiClientManager` timeout normalization, and transport/reactor timeout path across 007-009.
- **Task closure without blocker disappearance in immediate next audit:** PR9 tasks marked completed for timeout contract, hostname resolver injection, and numeric option validation while 009 still lists these as blockers (`docs/tasks.md` BATCH-PR-20260226-09 vs `src-production-readiness-audit-009.md` findings 2-4).

## F) Recommendations (Prioritized)

1. **Category:** Non-blocking correctness  
   **Concrete next step:** Add one end-to-end acceptance test that fails if `ami:listen` idle loop exceeds a CPU/cadence threshold and if timeout semantics diverge from published contract.  
   **Suggested tests:** `tests/Integration/ListenLoopCadenceTest.php`, `tests/Integration/NonBlockingConnectTest.php`, `tests/Unit/Transport/TimeoutContractTest.php`.

2. **Category:** API contract consistency (`timeoutMs`)  
   **Concrete next step:** Choose one contract (honor vs reject/clamp), update interfaces/docs/runtime together, and block merge unless all three match.  
   **Suggested tests:** `tests/Unit/Transport/TimeoutContractTest.php`, `tests/Integration/RuntimeProfilesTest.php`.

3. **Category:** Config bootstrap correctness (hostname resolver + numeric ranges)  
   **Concrete next step:** Enforce startup validation gate in CI for hostname resolver requirements and numeric bounds before runtime starts.  
   **Suggested tests:** `tests/Integration/ConfigLoaderHostnameResolverTest.php`, `tests/Unit/Cluster/ClientOptionsValidationTest.php`.

4. **Category:** Backpressure/queue safety drift  
   **Concrete next step:** Tighten acceptance criteria so queue/buffer validation tasks are not closed until latest audit shows downgrade/removal of related finding category.  
   **Suggested tests:** `tests/Unit/Core/EventQueueTest.php`, `tests/Integration/FloodSimulationTest.php`, `tests/Performance/FloodSimulationTest.php`.

5. **Category:** Observability durability  
   **Concrete next step:** Require dual-signal evidence (metric + structured log) for transport/log-drop/callback-failure paths and treat missing one as failed acceptance.  
   **Suggested tests:** `tests/Integration/LoggerBackpressureIsolationTest.php`, `tests/Unit/Logging/LoggerRedactionTest.php`.

6. **Category:** Process fix (loop prevention)  
   **Concrete next step:** Do not close a task if the same root-cause category appears as P0/P1 in the next audit; require one audit-cycle burn-in.

7. **Category:** Process fix (acceptance criteria hardening)  
   **Concrete next step:** Add explicit “category exit criteria” per task: include target audit category, severity target (e.g., P1->P3), and invariant linkage.

8. **Category:** Process fix (CI invariants)  
   **Concrete next step:** Add a CI job that runs invariant-focused tests and fails on non-blocking/cadence regressions before merge.

9. **Category:** Process fix (audit-task traceability)  
   **Concrete next step:** Add a mandatory mapping field in each append task: `addresses_audit: [audit-id, finding-id]`, and verify closure only when latest audit no longer reports that finding class.

10. **Category:** Stability governance  
    **Concrete next step:** During active hardening, run mini-audits after each batch (not only at batch end) to catch recurrence earlier and avoid severity rebounds.
