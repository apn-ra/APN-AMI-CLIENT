# Security Boundary Review

All claims verified against source + an empirical SSRF probe (read-only, throwaway).

## SSRF posture — strong, single-sourced, fail-closed
- **Allowlist before DNS** — untrusted hosts never resolve (probe: dns_called=0 for `evil.example.com`).
- **CIDR confinement** — resolved IP must be inside a configured allowed CIDR (default policy: private only).
- **Reserved-range deny-by-default** — loopback, link-local incl. metadata `169.254.169.254`, multicast,
  unspecified, broadcast, public all denied unless explicitly opted in.
- **IPv4-mapped IPv6 not a bypass** — `::ffff:169.254.169.254` / `::ffff:127.0.0.1` classified RESERVED and
  fail the IPv4 CIDR (family mismatch); both REJECTED in the probe.
- **Network/broadcast boundary deny** — `.0`/`.255` rejected by default; `denied_ips` covers gateways.
- **Multi-IP fail-closed** — ambiguous/mixed rejected; deterministic selection only when all valid.
- **No DNS-rebinding window** — the socket target is the validated literal IP, not the hostname; the hostname
  is diagnostic-only.

## Probe result (policy: allowedCidrs=['172.30.10.0/24'], exact alias)
```
::ffff:169.254.169.254  => REJECTED ResolvedIpOutsideAllowedCidrException
::ffff:127.0.0.1        => REJECTED
169.254.169.254         => REJECTED
127.0.0.1               => REJECTED
8.8.8.8                 => REJECTED
172.30.10.0 / .255      => REJECTED (network/broadcast)
172.30.10.50            => ACCEPTED (legit in-CIDR)
evil.example.com        => REJECTED UntrustedEndpointHostException (dns called=0)
```

## Boundary controls
- **No Docker socket** — no `docker.sock` reference; no `docker/.env` inspection.
- **No shell-out** — no `exec`/`shell_exec`/`system`/`passthru`/`proc_open`/`popen` in new code. (Two `exec(`
  in `tests/Integration/Chaos*Test.php` are pre-existing chaos-runner invocations, not added here, not Docker.)
- **No secret exposure** — no `APNTALK_ASTERISK_*`, no credentials; exception messages contain only
  host/IP/CIDR. `getenv()` not used in new code.
- **No arbitrary DNS** — only `gethostbynamel()` (or injected callable), reachable only after the allowlist
  gate.
- **enforce-off not recommended/required** — no `enforce_ip_endpoints=false` recommendation in new src; the
  trusted path runs under enforce=true.

## Default-safe
Without an installed policy the package is literal-IP-only under enforce=true — the new attack surface is
inert until a consumer explicitly opts in with allowlist+CIDR data.

**Security boundary review: PASS.**
