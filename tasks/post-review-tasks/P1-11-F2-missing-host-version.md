# P1-11-F2: AuditLogWriter missing `host_version` enrichment

**Severity:** HIGH  
**Source:** P1-11 Event Streaming review  
**Requirement:** TRD-REPORT-005 — audit log enriched with `run_id`, `host_version`, `repo_sha`

## Problem

`AuditLogWriter` constructor accepts `logPath`, `runId`, `repoSha` but not `hostVersion`. The `consume()` method enriches events with `run_id`, `repo_sha`, `host_ts` but omits `host_version`.

## Fix

1. Add `string $hostVersion` parameter to `AuditLogWriter` constructor.
2. Include `'host_version' => $this->hostVersion` in the enrichment array in `consume()`.
3. Update all call sites constructing `AuditLogWriter`.

## Test Update

`AuditLogWriterTest::testEventIsEnrichedWithRunMetadata` must assert `host_version` is present and correct.

## Files

- `src/Orchestrator/AuditLogWriter.php`
- `tests/Unit/Orchestrator/AuditLogWriterTest.php`
