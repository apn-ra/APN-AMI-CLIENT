# Baseline vs Final Delta

## Summary
- Baseline: 5 PASS / 13 total
- Final: 13 PASS / 13 total
- Net improvement: +8 scenarios

## Scenario Delta
| Scenario | Baseline | Final |
|---|---|---|
| S1 | FAIL | PASS |
| S2 | FAIL | PASS |
| S3 | FAIL | PASS |
| S4 | FAIL | PASS |
| S5 | PASS | PASS |
| S6 | PASS | PASS |
| S7 | PASS | PASS |
| S8 | PASS | PASS |
| S9 | FAIL | PASS |
| S10 | FAIL | PASS |
| S11 | PASS* | PASS (stress-validated) |
| S12 | FAIL | PASS |
| S13 | FAIL | PASS |

## Notes
- `S11` baseline pass was non-stress/incomplete; final includes deterministic 1k out-of-order validation.
