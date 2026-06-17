# APN-AMI-CLIENT — Trusted Endpoint Resolver Implementation

Claim: `APN_AMI_CLIENT_TRUSTED_ENDPOINT_RESOLVER_IMPLEMENTED`
Status: **implemented, uncommitted** (no commit / tag / publish in this task).

This directory documents the package-owned trusted endpoint resolver added to
`apntalk/ami-client` for RB-ENFORCE-IP under **Authority A** (package-owned
trusted resolver; static IP is fallback only).

## What was built
A fail-closed, package-level trusted endpoint resolver that lets a consumer use
trusted hostnames/aliases (e.g. `apntalk-asterisk-app`) **without disabling
`enforce_ip_endpoints`**. A hostname is admitted only when it matches an explicit
allowlist/pattern, resolves (DNS, at bootstrap only) to a literal IP inside an
allowed CIDR, and passes reserved-range checks. The connection layer always
receives a validated literal IP; arbitrary DNS, metadata/loopback/link-local,
and global enforce-off are never allowed.

## Files in this artifact set
| File | Purpose |
|---|---|
| `README.md` | This overview. |
| `current-state.md` | Repo state before/after, baseline test reality. |
| `implementation-summary.md` | What changed and why, file by file. |
| `public-api-summary.md` | New/changed public surface. |
| `enforce-ip-boundary-preservation.md` | How the enforce_ip_endpoints intent is preserved/redefined. |
| `trusted-resolver-policy.md` | Policy model and resolution algorithm. |
| `exception-classification.md` | Exception taxonomy and BC mapping. |
| `backward-compatibility.md` | BC guarantees. |
| `tests-added-or-updated.md` | Test matrix coverage. |
| `validation-results.md` | Commands run and their outputs. |
| `security-boundary-review.md` | SSRF/Docker/secret boundary review. |
| `apntalk-integration-notes.md` | How APNTalk will consume this later (no changes made here). |
| `blockers.md` | Pre-existing issues, none task-blocking. |
| `rollback-plan.md` | How to revert. |
| `package_closure.yml` | Machine-readable closure record. |

## Boundaries honored
No APNTalk source/composer changes, no tag, no publish, no commit, no Docker
socket, no secrets, no SIP/WebRTC/RTP/Stage-3 claims.
