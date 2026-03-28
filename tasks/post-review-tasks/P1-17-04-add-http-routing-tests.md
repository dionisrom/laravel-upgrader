# P1-17-04: Add HTTP routing and SSE tests

**Source:** P1-17 review  
**Severity:** LOW  
**Requirements:** DB-01, DB-02, DB-03, TRD-DASH-003

## Problem

No tests exercise handleRequest(), serveIndex(), serveEvents(), serveHealth() or verify SSE response headers. The core HTTP behavior is completely untested.

## Fix

- Add tests that invoke handleRequest via reflection or by extracting route logic into testable methods.
- Verify SSE response headers (Content-Type, Cache-Control, X-Accel-Buffering).
- Verify health endpoint returns JSON with client count.
- Verify index returns 200 with HTML content.
- Verify 404 for unknown routes.
