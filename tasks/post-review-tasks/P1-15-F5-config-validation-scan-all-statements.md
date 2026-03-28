# P1-15-F5: Config validation should scan all statements for return array

**Severity:** MEDIUM  
**Requirement:** VP-05, TRD-VERIFY-006  
**Source:** P1-15 review finding F5  

## Problem

`StaticArtisanVerifier::verifyConfigFiles` only checks `$stmts[0]`. Config files with variable assignments, conditionals, or multiple statements before the return array are flagged as invalid even if they do contain a valid `return [...]`.

## Required Fix

Scan all statements in the file for at least one `Return_` containing an `Array_` expression instead of only checking the first statement.

## Files to Modify

- `src-container/Verification/StaticArtisanVerifier.php`
- `tests/Unit/Verification/StaticArtisanVerifierTest.php`

## Acceptance Criteria

- [ ] Config files with return array NOT as first statement are accepted
- [ ] Config files with NO return array anywhere are rejected
- [ ] Test added for config files with statements before the return
