# Accepted Chaos Outcome Decision Record Template

- Timestamp (UTC): `YYYY-MM-DDTHH:MM:SSZ`
- Release Candidate: `vX.Y.Z-rcN`
- Decision Owner: `<name>`
- Scope: `<subsystems / scenarios>`

## Artifact Inputs
- Readiness score artifact: `docs/ami-client/production-readiness/audits/<timestamp>-production-readiness-score.md`
- Findings artifact: `docs/ami-client/production-readiness/findings/<timestamp>-findings.md`
- Chaos final artifact: `docs/ami-client/chaos/reports/<timestamp>-final-chaos-suite-results.md`

## Failing/Partial Chaos Scenarios
- Scenario IDs: `Sx, Sy`
- Invariant status: `<state whether parser/buffer/correlation/fairness/reconnect invariants remain intact>`
- Failure classification: `SANDBOX_ENVIRONMENT | ACTIONABLE_DEFECT`

## Acceptance Rationale
- Why release is accepted despite non-green chaos.
- Why risk is bounded for dialer workload.

## Mitigations and Follow-up
- Immediate mitigations in release notes / runtime flags (if any).
- Follow-up batch/issue IDs and deadlines.

## Decision
- `ACCEPTED` or `REJECTED`
- Approvers:
  - `<name1>`
  - `<name2>`
