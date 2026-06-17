# Trusted Resolver Policy

## Trust model
A hostname is trusted **only** through an explicit policy object/resolver. Two gates, both fail-closed:

1. **Host trust (before DNS)** — `AllowedEndpointHostPolicy`:
   - `exactAllowlist` — exact trusted names (case-insensitive), e.g. `apntalk-asterisk-app`.
   - `allowlistPatterns` — full PCRE patterns for provider-node aliases, e.g.
     `/^apntalk-asterisk-[a-z0-9-]+-app$/` matching `apntalk-asterisk-node-a-app`,
     `apntalk-asterisk-node-b-app`, … (multi-PBX without per-node static IPs).
   - A host matching neither → `UntrustedEndpointHostException`, **before any DNS lookup**.

2. **IP trust (after DNS)** — every resolved IP must:
   - pass `ReservedRangePolicy` (defaults deny loopback, link-local/metadata `169.254.169.254`,
     multicast, unspecified, broadcast, and public; only private allowed by default; explicit `deniedIps`
     for gateway/bridge addresses);
   - be inside one of `allowedCidrs`;
   - not be the network/broadcast boundary of its CIDR (unless `allowNetworkAndBroadcastAddress`).

## Multi-IP handling (fail-closed)
- `MultiIpPolicy::Reject` (default): more than one resolved IP → `AmbiguousEndpointResolutionException`.
- `MultiIpPolicy::DeterministicAllValid`: **all** resolved IPs must pass validation; if any fails →
  `AmbiguousEndpointResolutionException`; otherwise the lowest address (binary order) is chosen
  deterministically. A mixed valid/invalid set is always rejected.

## Resolution timing & mechanism
- Resolution runs at **bootstrap/config time** (manager construction → `resolveHost`), never inside the
  non-blocking tick path — consistent with NBRC and the existing hostname-resolution path.
- DNS uses an **injectable callable** (default `gethostbynamel()`); tests inject a hook → fully offline.
- **No Docker socket, no shelling out.** Pure PHP resolver + pure CIDR/IP math (`inet_pton`).

## Example (test/docs only — not real APNTalk values)
```php
$policy = new AllowedEndpointHostPolicy(
    exactAllowlist:    ['apntalk-asterisk-app'],
    allowlistPatterns: ['/^apntalk-asterisk-[a-z0-9-]+-app$/'],
    allowedCidrs:      ['172.30.10.0/24', '10.250.0.0/16'],   // documentation/test networks
    multiIp:           MultiIpPolicy::Reject,
);
$resolver = new DefaultTrustedEndpointResolver($policy);
$ip = $resolver->resolve('apntalk-asterisk-node-a-app')->validatedIp; // validated literal IP
```

Or via config (off by default): `config['trusted_endpoint_policy']` with `exact_allowlist`,
`allowlist_patterns`, `allowed_cidrs`, `multi_ip`, `reserved{...}` — `ConfigLoader::load()` builds the
`DefaultTrustedEndpointResolver` and fails closed if the policy can't establish trust.
