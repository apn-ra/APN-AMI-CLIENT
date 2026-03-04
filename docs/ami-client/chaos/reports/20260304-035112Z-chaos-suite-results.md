# Chaos Suite Results

## Environment Summary
- Date (UTC): 2026-03-04
- OS: Linux (WSL2)
- PHP: 8.4.18
- Test runner: PHPUnit 12.5.14
- Core evidence command output: `docs/ami-client/chaos/fixtures/test-output/20260304-034215Z-mapped-chaos-tests.txt`

## Sandbox Note
Local TCP server binding failed in sandbox (`stream_socket_server(): Unable to connect to tcp://127.0.0.1:0 (Success)`).
Per sandbox policy, harness verification was rerun outside sandbox and passed:
- `docs/ami-client/chaos/fixtures/20260304-035218Z-harness-smoke-output.txt`

Scenario runner execution evidence (runnable DSL check):
- `docs/ami-client/chaos/fixtures/20260304-034923Z-s1-permission-denied-runner.txt`
- `docs/ami-client/chaos/fixtures/20260304-034924Z-s4-banner-error-interleaving-runner.txt`
- `docs/ami-client/chaos/fixtures/20260304-034924Z-s5-partial-writes-chunked-frames-runner.txt`

## Scenario Matrix
| Scenario | Pass/Fail | Primary Invariants | Evidence |
|---|---|---|---|
| S1 Permission Denied | PASS | `Response: Error` parsed and resolves pending as explicit failure | `tests/Unit/Protocol/ParserPermissionErrorTest.php:16`, `tests/Unit/Correlation/PermissionErrorCorrelationTest.php:24`, `docs/ami-client/chaos/fixtures/test-output/20260304-034215Z-mapped-chaos-tests.txt` |
| S2 Permission Denied (`\n\n`) | PASS | LF delimiter parsing without stall | `tests/Unit/Protocol/ParserPermissionErrorTest.php:81`, `tests/Unit/Protocol/ParserHardeningTest.php:26` |
| S3 Error Missing ActionID | PASS | unmatched response counted/logged; no crash | `tests/Unit/Correlation/PermissionErrorCorrelationTest.php:45` |
| S4 Banner + Error Interleaving | PASS | banner handled then error parsed | `tests/Unit/Protocol/ParserPermissionErrorTest.php:43`, `tests/Unit/Protocol/ParserTest.php:26` |
| S5 Partial Writes/Chunked Frames | PASS | partial frames reassembled deterministically | `tests/Unit/Protocol/ParserTest.php:74` |
| S6 Truncated Frame + Recovery | PASS | safety cap/desync handling and subsequent recovery | `tests/Unit/Protocol/ParserHardeningTest.php:60`, `tests/Integration/ParserCorruptionTest.php:40` |
| S7 Garbage Injection/Desync Recovery | PASS | garbage does not poison subsequent valid frame | `tests/Integration/ParserCorruptionTest.php:14` |
| S8 Oversized Frame Enforcement | PASS | oversized frame rejected; parser recovers for next frame | `tests/Unit/Protocol/ParserTest.php:142`, `tests/Integration/ParserCorruptionTest.php:88` |
| S9 Follows Oversize + Terminator Rules | PASS | follows strategy enforces output cap with `ProtocolException` | `tests/Unit/Protocol/Strategies/FollowsResponseStrategyTest.php:52` |
| S10 Event Flood (10k+) | PASS | queue cap + drop policy + per-tick dispatch fairness | `tests/Integration/FloodSimulationTest.php:45`, `tests/Performance/FloodSimulationTest.php:22` |
| S11 Correlation Storm (1k, out-of-order) | FAIL | missing dedicated 1k out-of-order stress scenario | gap identified; create batch 002 |
| S12 Multi-Server Fairness | PASS (inferred) | no starvation under flood + per-server isolation | `tests/Performance/FloodSimulationTest.php:67`, `tests/Integration/MultiServerIsolationTest.php:20`, `tests/Integration/ReconnectFairnessTest.php:20` |
| S13 Reconnect Storm/Herd Control | PASS | backoff+jitter + reconnect fairness + attempt ceilings | `tests/Integration/ReconnectStormTest.php:19`, `tests/Integration/ReconnectFairnessTest.php:20`, `src/Cluster/AmiClientManager.php:245`, `src/Health/ConnectionManager.php:352` |

## Top Failures by Severity
- P1: S11 missing explicit 1k out-of-order correlation storm regression scenario; current tests validate correlation semantics but not required storm profile.
- P2: Harness runner result predicate is success-centric and does not yet score error-terminal scenarios as pass conditions.
- P2: Per-scenario memory before/after snapshots are not yet automatically captured in harness artifacts.

## Verdict
**Nearly Ready**

Reason: core invariants are mostly covered and passing, but S11 required stress case is still missing as a dedicated regression scenario.
