# Sandbox Environment Detection

- Timestamp (UTC): `2026-03-04T05:30:16Z`
- Classification: `SANDBOX_ENVIRONMENT`
- Sub-classification: `SANDBOX_SOCKET_RESTRICTION`

## Command Executed

```bash
php tests/Chaos/run_scenario.php --scenario=docs/ami-client/chaos/scenarios/s1-permission-denied.json --duration-ms=1200
```

## Raw Error Output

```text
scenario=permission-denied
scenario_id=S1
duration_ms=0
metrics_file=/home/ramjf/projects/APN-AMI-CLIENT/tests/Chaos/../../docs/ami-client/chaos/metrics/20260304-052908Z-metrics-s1.md
error=Unable to start fake AMI server: Success (0)
classification=SANDBOX_ENVIRONMENT
result=FAIL
failed_expectations=runtime_error
```

## Classification Reason

- Failure signature occurred at fake TCP server startup and blocked listener creation.
- The scenario runner itself tagged the result as `SANDBOX_ENVIRONMENT`.
- This matches the repository policy for socket bind/listener restrictions in sandboxed runtimes.

## Retry Environment Used

- Outside sandbox execution (escalated command permissions)
- Same workspace and same command family (`php tests/Chaos/run_scenario.php ...`)

## Final Decision

- `VERIFIED_ENVIRONMENT_LIMITATION`
- Evidence: outside-sandbox rerun succeeded and produced `RUNTIME_OK` with `result=PASS` for S1 and then for full S1..S13 matrix.
- Aggregate outside-sandbox evidence log: `docs/ami-client/chaos/fixtures/20260304-053016Z-chaos-suite.log`
