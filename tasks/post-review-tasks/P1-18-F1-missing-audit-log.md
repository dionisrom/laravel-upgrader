# P1-18-F1: ReportBuilder missing audit.log.json generation

**Severity:** HIGH  
**Requirement IDs:** TRD-REPORT-005, RP-06  
**Files:** `src-container/Report/ReportBuilder.php`, `tests/Unit/Report/ReportBuilderTest.php`

## Problem

`ReportBuilder::build()` generates only 3 files (report.html, report.json, manual-review.md). The required `audit.log.json` (JSON-ND, enriched with `run_id`, `host_version`, `repo_sha`) is never written.

## Required Fix

1. Create `AuditLogFormatter` in `src-container/Report/Formatters/` that:
   - Outputs JSON-ND (one JSON object per line)
   - Enriches each event with `run_id`, `host_version`, `repo_sha`
   - Strips any source code, file contents, or tokens from events
2. Add `audit.log.json` to `ReportBuilder::build()` output
3. Update `ReportBuilderTest` to assert 4 files
4. Add unit tests for `AuditLogFormatter` covering enrichment and security filtering
