# APNTalk Integration Notes (FUTURE — nothing changed in APNTalk here)

This task implemented only the package side. **No APNTalk source, composer.json,
or composer.lock was modified.** Integration is a later, separate, bounded task
(recommended coder: Codex, only after this package work is proof-reviewed and
tagged).

## How APNTalk will consume this (later)
1. Bump `apntalk/ami-client` from the currently consumed `v1.0.1` to the tagged version that includes this
   resolver (tag created in a later task, not now).
2. Keep `enforce_ip_endpoints=true` (do **not** set it false).
3. Provide allowlist/CIDR **data only** via `config['trusted_endpoint_policy']`, e.g.:
   ```php
   'trusted_endpoint_policy' => [
       'exact_allowlist'    => ['apntalk-asterisk-app'],
       'allowlist_patterns' => ['/^apntalk-asterisk-[a-z0-9-]+-app$/'],
       'allowed_cidrs'      => [/* APNTalk control-plane CIDR(s) */],
       'multi_ip'           => 'reject',
   ],
   ```
   The real APNTalk Docker CIDR is supplied **by APNTalk config at integration time** — the package does not
   hardcode or require it. The CIDRs used in package tests (`172.30.10.0/24`, `10.250.0.0/16`) are
   documentation/test-only.
4. Optionally map the package exceptions to precise readiness blockers in the reconciler, e.g.
   `UntrustedEndpointHostException → ASTERISK_AMI_ENDPOINT_HOST_UNTRUSTED`,
   `ResolvedIpOutsideAllowedCidrException → ASTERISK_AMI_RESOLVED_IP_OUT_OF_CIDR`,
   `EndpointHostUnresolvableException → ASTERISK_AMI_ENDPOINT_HOST_UNRESOLVABLE`. Post-resolution socket
   failures remain `ConnectionException`.

## Why this avoids the unsafe state APNTalk previously faced
APNTalk no longer needs `enforce_ip_endpoints=false` (the split, weakenable boundary). Trust lives in the
package, is single-sourced and test-covered, and APNTalk contributes only data.

## Multi-PBX
`allowlist_patterns` + `allowed_cidrs` let N provider nodes use slug aliases
(`apntalk-asterisk-node-a-app`, `-node-b-app`, …) on a known control-plane subnet without per-node static IPs.

## Explicit non-actions in this task
No runtime reconcile, no browser login, no seeders/migrations, no DB/Redis mutation, no SIP/WebRTC/RTP,
no Stage 3, no external trunk/PSTN, no real customer identity, no inspection of `docker/.env` or supervisor
logs.
