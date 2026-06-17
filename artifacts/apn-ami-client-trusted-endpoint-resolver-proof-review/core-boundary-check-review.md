# Core-Boundary Check Review

## Command
```
composer check:core-boundaries   # php scripts/validate_guidelines.php
```

## Result
```
ERROR: Forbidden sleep/usleep call in /src/Laravel/Commands/ListenCommand.php
(exit non-zero)
```

## Classification — pre-existing, unrelated to this task
- The **only** ERROR is the `usleep` in `src/Laravel/Commands/ListenCommand.php` (worker-layer cadence,
  permitted by NBRC Mode B but flagged by the script's simple regex).
- `git diff --name-only` shows `ListenCommand.php` is **not** modified by this task → the finding predates it.

## New/modified files add ZERO violations
Scoped grep of the boundary output for any new/modified file:
```
composer check:core-boundaries 2>&1 | grep ERROR | grep -Ei \
  "Endpoint|UntrustedEndpoint|EndpointHostUnresolvable|ResolvedIpOutside|AmbiguousEndpoint|ResolverUnavailable|ConfigLoader|AmiClientManager|InvalidConfiguration"
→ (no matches)
```
Manually confirmed against the guideline rules:
- All new files declare `strict_types=1`.
- No `Illuminate` imports in Core/Protocol (new code lives in `Cluster\Endpoint` and `Exceptions`).
- No `sleep`/`usleep`/`nanosleep` in new code.
- No `private/protected/public static $...` mutable state (utilities use static *methods*, not static
  mutable properties; `MultiIpPolicy` is an enum).

## Conclusion
**Q core-boundary: the non-zero exit is entirely attributable to the pre-existing ListenCommand finding; the
trusted endpoint resolver introduces no new boundary violations.** No fix attempted (out of scope; pre-existing
and NBRC-compliant in intent).
