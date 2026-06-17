# APN-AMI-CLIENT — Trusted Endpoint Resolver Proof/Review

Claim: `APN_AMI_CLIENT_TRUSTED_ENDPOINT_RESOLVER_PROOF_REVIEW_COMPLETE`
Type: **evidence-only proof/review** (no source change, no commit, no tag, no publish).

Reviews the uncommitted implementation claimed under
`APN_AMI_CLIENT_TRUSTED_ENDPOINT_RESOLVER_IMPLEMENTED` (RB-ENFORCE-IP, Authority
A — package-owned trusted endpoint resolver, static IP fallback only).

## Verdict (summary)
**PASS — ready for package commit packaging.** The implementation makes
`apntalk/ami-client` the owner of trusted endpoint host authority; preserves
strict literal-IP-only default under `enforce_ip_endpoints=true`; admits
hostnames only via an explicit, fail-closed policy; validates allowlist before
DNS and CIDR/reserved ranges before connection; hands the transport a validated
literal IP; and adds a precise exception taxonomy that stays
`InvalidConfigurationException`-compatible. No commit/tag/publish performed.
Not yet ready for tag/publish (maintainer version decision required) and not yet
ready for APNTalk integration.

## Independent verification performed (read-only)
- Git state capture; HEAD confirmed `d029ab6…` (unchanged).
- Full review of all tracked diffs and new source/test files.
- New tests: 35/68 green. Touched-area regression set: 53/126 green.
- Full suite: 342 tests, 6 failures, 1 skipped — 6 failures proven pre-existing
  (reproduced at baseline with edits stashed; none reference the resolver).
- `composer check:core-boundaries`: non-zero only from pre-existing
  `ListenCommand.php` usleep; **zero** violations in new/modified files.
- PHP lint: clean on every new/modified file.
- SSRF bypass-vector probe: IPv4-mapped-IPv6 metadata/loopback, metadata,
  loopback, public, and CIDR network/broadcast all fail closed; untrusted host
  rejected with **0 DNS calls** (allowlist-before-DNS proven).
- Static scan: no Docker socket, shell-out, secrets, `docker/.env`, or
  `APNTALK_ASTERISK_*` in new code.

## Files in this artifact set
See the 18 files listed in the task; `package_closure.yml` is the machine-readable record.

## Boundaries honored
No APNTalk source/composer change, no commit/tag/publish, no Docker socket, no
secrets, no SIP/WebRTC/RTP/Stage-3 claims, no DB/Redis/migration/seeder actions.
