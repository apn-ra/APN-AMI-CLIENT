# Security Boundary Review

## SSRF protections (strengthened, single-sourced in the package)
- **Allowlist before DNS** — untrusted names are rejected before any resolution, so an attacker-controlled
  hostname can never trigger a lookup or a connection. (Test asserts 0 DNS calls for untrusted hosts.)
- **CIDR confinement** — every resolved IP must fall inside an explicitly configured allowed CIDR; default
  policy permits only RFC1918/ULA private space.
- **Reserved-range deny-by-default** — loopback, link-local **including cloud metadata `169.254.169.254`**,
  multicast, unspecified, broadcast, and public addresses are denied unless explicitly opted in (test-only).
- **Network/broadcast boundary deny** — the CIDR's `.0`/`.255` (and IPv6 equivalents) are rejected by
  default; explicit `deniedIps` cover gateway/bridge addresses.
- **Multi-IP fail-closed** — ambiguous or mixed valid/invalid DNS results are rejected; deterministic
  selection happens only when every IP validates.
- **DNS rebinding window** — the connection layer uses the **validated literal IP**, not the hostname; the
  hostname is retained for diagnostics only and never used to open the socket.

## enforce_ip_endpoints intent
- Default remains `true`; literal-IP-only when no policy is installed.
- Trusted hostnames are admitted **only** via an explicit policy/resolver — there is no global enforce-off
  path and none is recommended.
- `enforce_ip_endpoints=false` + no resolver/policy + hostname is still rejected (fail-closed).
- A declared-but-unusable policy fails closed at load (`ResolverUnavailableException`) — no accidental
  "trust everything".

## No Docker / no shell / no runtime coupling
- No `docker.sock`, no `proc_open`/`popen`/`exec`/`shell_exec`/`system`/`passthru` in the implementation.
- Resolution is pure PHP: an injectable DNS callable (default `gethostbynamel()`) + `inet_pton`-based CIDR
  math. The package exposes generic primitives only; no APNTalk runtime assumptions.

## Secret hygiene
- No `APNTALK_ASTERISK_*` value is printed, stored, hardcoded, or referenced.
- Exception/log messages contain only host / IP / CIDR — never credentials. `Core/SecretRedactor` remains
  available to consumers.
- No inspection of `docker/.env`, supervisor logs, DB, or Redis.

## Out of scope (not touched, not claimed)
SIP REGISTER, WebRTC/WSS, RTP/media, Stage 3, provider parity, full lifecycle closure — none performed or
claimed. No migrations/seeders/DB/Redis mutations. APNTalk source untouched.
