# P1-11-F3: AuditLogWriter sanitize blocklist `key` is too broad

**Severity:** MEDIUM  
**Source:** P1-11 Event Streaming review  
**Requirement:** TRD-SEC-003 — strip auth tokens, not generic fields

## Problem

The `sanitize()` blocklist includes `key` which will strip any event field named `key`, including legitimate data. TRD-SEC-003 targets auth tokens and secrets, not generic key fields.

## Fix

Replace `key` with more specific terms: `api_key`, `secret_key`, `private_key`, `auth_key`.

## Test Update

Update `AuditLogWriterTest::testSensitiveFieldsAreStripped` to reflect the new specific key names and add a test that a generic `key` field is preserved.

## Files

- `src/Orchestrator/AuditLogWriter.php`
- `tests/Unit/Orchestrator/AuditLogWriterTest.php`
