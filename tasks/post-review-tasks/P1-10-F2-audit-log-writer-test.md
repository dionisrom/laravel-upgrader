# P1-10-F2: Add AuditLogWriterTest

**Severity:** HIGH  
**Finding:** AuditLogWriter has zero test coverage. Log enrichment, sanitization, and append semantics are untested.  
**Violated:** TRD-SEC-003 (sensitive field sanitization), Task AC (stderr appended to audit log)

## Required Tests

1. Event is enriched with run_id, repo_sha, host_ts
2. Sensitive fields (token, password, secret, key, source_code, file_contents, content) are stripped
3. Output is valid JSON-ND (one JSON object per line, append mode)
4. json_encode failure is handled gracefully (no exception, logged via error_log)
5. File open failure is handled gracefully

## Implementation Notes

- Use a temp file for the log path
- Test file: `tests/Unit/Orchestrator/AuditLogWriterTest.php`
