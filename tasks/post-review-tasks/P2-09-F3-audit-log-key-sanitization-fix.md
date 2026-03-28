# P2-09-F3: AuditLogWriter sanitize() blocked generic 'key' field

**Source:** P2-09 Hardening review  
**Severity:** Medium  
**Requirement:** TRD-SEC-003

## Problem

`AuditLogWriter::sanitize()` included `'key'` in its blocked field list, which stripped any event field named `key` — including legitimate data. The test `testSensitiveFieldsAreStripped` expected `key` to be preserved, causing a test failure.

Cryptographic/API keys are already covered by `api_key`, `secret_key`, `private_key`, `auth_key`.

Note: This contradicts the earlier P1-22-F1 task which recommended adding `key`. P1-22-F1 is superseded — the test is the source of truth.

## Fix

Remove `'key'` from the `$blocked` array. **Already applied.**

## Validation

`testSensitiveFieldsAreStripped` passes (135/135 Orchestrator tests green).

## Status

**FIXED** — applied during review.
