# ~~P1-22-F1: AuditLogWriter missing bare `key` in sanitization blocklist~~

> **SUPERSEDED by P2-09-F3.** The recommendation below was incorrect — `key` is a legitimate generic field name used in events. Blocking it strips valid data. Cryptographic keys are already covered by `api_key`, `secret_key`, `private_key`, `auth_key`. The authoritative test `testSensitiveFieldsAreStripped` expects `key` to be preserved. The `'key'` entry was removed from the blocked list in P2-09-F3.

**Source:** P1-22 Hardening review  
**Severity:** ~~High~~ N/A (superseded)  
**Requirement:** TRD-SEC-003  
**Status:** SUPERSEDED — do not apply

## Original Problem (incorrect)

`AuditLogWriter::sanitize()` blocks `api_key`, `secret_key`, `private_key`, `auth_key` but not bare `key`. Events containing `'key' => '...'` write plaintext to audit log.

## Original Fix (incorrect — do not apply)

~~Add `'key'` to the `$blocked` array in `src/Orchestrator/AuditLogWriter.php` line 71.~~

## Validation

`testSensitiveFieldsAreStripped` asserts `key` is **preserved**, not stripped.
