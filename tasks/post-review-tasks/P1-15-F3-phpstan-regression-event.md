# P1-15-F3: Emit phpstan_regression event on baseline delta failure

**Severity:** HIGH  
**Requirement:** TRD-VERIFY-004  
**Source:** P1-15 review finding F3  

## Problem

`PhpStanVerifier` returns a failed result when error count increases but does not emit a `phpstan_regression` event. TRD-VERIFY-004 specifies: "post > pre → `phpstan_regression` event → FAIL."

## Required Fix

Accept an `EventEmitter` in `PhpStanVerifier` and emit `phpstan_regression` with pre/post counts before returning the failure result.

## Files to Modify

- `src-container/Verification/PhpStanVerifier.php`
- `tests/Unit/Verification/PhpStanVerifierTest.php`

## Acceptance Criteria

- [ ] `phpstan_regression` event emitted with pre and post error counts
- [ ] Event emitted before the failed VerifierResult is returned
- [ ] Test verifies event emission on regression
