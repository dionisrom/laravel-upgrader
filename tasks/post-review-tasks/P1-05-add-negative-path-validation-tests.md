# Post-Review: P1-05 — Add negative-path validation tests

**Source:** P1-05 Breaking Change Registry review  
**Severity:** MEDIUM  
**Violated:** TRD-REG-001  

## Problem

Only `title` is covered by a negative test for missing entry fields. The 6 newly-validated fields need their own `testLoadThrowsOnMissing*` tests to prove schema enforcement.

## Fix

Add failure-case PHPUnit tests for:
- Missing top-level `php_minimum`
- Missing top-level `last_curated`
- Missing entry `description`
- Missing entry `migration_example`
- Invalid `migration_example` (missing `before`/`after`)
- Missing entry `affects_lumen`
- Missing entry `manual_review_required`
- Missing entry `official_doc_anchor`
- Missing entry `rector_rule`
