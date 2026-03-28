# P1-15-F2: ClassResolutionVerifier must check all use statements

**Severity:** HIGH  
**Requirement:** VP-09  
**Source:** P1-15 review finding F2  

## Problem

`ClassResolutionVerifier` only checks `use App\...` statements via regex. VP-09 requires "All `use` statements resolve to existing classes." Renamed or removed vendor classes after an upgrade will not be caught.

## Required Fix

1. Remove the `App\` filter — check all `use` statements
2. Use php-parser AST instead of regex to properly handle grouped use, aliased imports
3. Skip known-unresolvable patterns (e.g., function imports, const imports)

## Files to Modify

- `src-container/Verification/ClassResolutionVerifier.php`
- `tests/Unit/Verification/ClassResolutionVerifierTest.php`

## Acceptance Criteria

- [ ] All `use` class/interface/trait imports are checked, not just `App\`
- [ ] Grouped use statements (`use App\{Foo, Bar}`) are handled
- [ ] Function and const imports are excluded
- [ ] Tests cover vendor namespace resolution failures
