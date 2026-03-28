# P1-15-F6: Route verification should use AST, not regex

**Severity:** MEDIUM  
**Requirement:** TRD-VERIFY-006  
**Source:** P1-15 review finding F6  

## Problem

`StaticArtisanVerifier::extractControllerReferences` uses regex instead of php-parser. The `$parser` is passed to `verifyRouteControllers` but unused. TRD mandates AST-based route analysis.

## Required Fix

Use php-parser to extract controller class references from route files (string literals matching controller patterns, `::class` references).

## Files to Modify

- `src-container/Verification/StaticArtisanVerifier.php`
- `tests/Unit/Verification/StaticArtisanVerifierTest.php`

## Acceptance Criteria

- [ ] Route controller extraction uses php-parser AST
- [ ] `::class` constant references are resolved
- [ ] String literal controller references are still detected
- [ ] Test covers both invocable and action controllers
