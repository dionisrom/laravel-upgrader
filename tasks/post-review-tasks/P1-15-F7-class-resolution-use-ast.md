# P1-15-F7: ClassResolutionVerifier should use AST for use statement extraction

**Severity:** MEDIUM  
**Requirement:** VP-09  
**Source:** P1-15 review finding F7  

## Problem

Regex at `ClassResolutionVerifier::extractUseStatements` misses grouped use (`use App\{Foo, Bar};`) and aliased imports (`use App\Foo as Bar;`).

## Required Fix

Use php-parser to extract use statements from AST, handling all PHP use statement forms.

Note: This overlaps with P1-15-F2 (namespace scope). Implement both together.

## Files to Modify

- `src-container/Verification/ClassResolutionVerifier.php`
- `tests/Unit/Verification/ClassResolutionVerifierTest.php`
