# Decision: Production Readiness Verdict Thresholds

- Timestamp: 2026-03-04T04:59:25Z
- Status: Accepted

## Thresholds

- `Ready`
  - Score `>= 95`
  - Invariant hard fails: `0`
  - Chaos final suite: `PASS`
  - No open P0/P1 batches

- `Nearly Ready`
  - Score `90-94.99`
  - Invariant hard fails: `0`
  - Chaos final suite: `PASS` or explicitly accepted non-invariant environment gap
  - Remaining work limited to P2-P4 or approved non-runtime risk

- `Not Ready`
  - Score `< 90`, or
  - Any invariant hard fail present, or
  - Chaos final suite not green for mapped invariant scenarios

## Rationale

Readiness labeling must prioritize runtime safety and chaos behavior over documentation completeness. A high score cannot override invariant or chaos regressions.
