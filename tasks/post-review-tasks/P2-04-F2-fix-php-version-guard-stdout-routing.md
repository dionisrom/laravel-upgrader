# P2-04-F2: Fix PhpVersionGuard JSON-ND Stdout Routing in Entrypoint

**Severity:** HIGH  
**Source:** P2-04 review finding F2  
**Violated:** TRD non-negotiable ("Container stdout must remain JSON-ND compatible"), P2-04 acceptance criteria ("PHP version guard warns if source project < 8.3")

## Problem

In `docker/hop-12-to-13/entrypoint.sh` line 125, the PhpVersionGuard invocation uses `>&2 2>&1` which sends ALL output to stderr. The PhpVersionGuard PHP script writes JSON-ND warning events to stdout, but these are silently lost because the entrypoint redirects them to stderr. The host orchestrator never sees the PHP version warnings.

## Required

1. Change the entrypoint to let PhpVersionGuard's stdout flow through to container stdout (JSON-ND channel), while still directing its stderr to container stderr
2. The fix should use `2>&2` or simply drop the `>&2` redirect so stdout flows naturally, while keeping stderr on stderr

## Validation

- PhpVersionGuard JSON-ND warning events reach container stdout
- Non-JSON diagnostic output stays on stderr
- Other `run_stage` calls are unaffected
