# P1-17-02: Add GET /static/* route

**Source:** P1-17 review  
**Severity:** MEDIUM  
**Requirements:** TRD-DASH-001

## Problem

The TRD specifies a `GET /static/*` route to serve assets from the public directory. The current `handleRequest()` only has routes for `/`, `/events`, `/health` and falls through to 404.

## Fix

- Add static file serving for paths starting with `/static/`.
- Sanitize the path to prevent directory traversal.
- Add test for static file serving including traversal prevention.
