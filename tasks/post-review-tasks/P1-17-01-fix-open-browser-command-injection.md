# P1-17-01: Fix openBrowser() command injection and Windows compatibility

**Source:** P1-17 review  
**Severity:** MEDIUM  
**Requirements:** TRD-DASH-005, OWASP A03:2021

## Problem

1. `openBrowser()` interpolates `$host`/`$port` into `shell_exec()` without escaping — command injection risk.
2. Unix redirect syntax (`> /dev/null 2>&1 &`) is used on all platforms including Windows where it's invalid.

## Fix

- Use `escapeshellarg()` on the URL.
- Use platform-specific redirect/background syntax.
- Add unit test that validates no shell metacharacters leak.
