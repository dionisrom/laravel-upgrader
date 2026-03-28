# P1-20-F1: Missing PassportRoutesRector fixture test

**Severity:** HIGH  
**Violated Requirements:** TS-01, TRD-TEST-001, TRD-TEST-003  
**Source Task:** P1-20  

## Problem

`src-container/Rector/Rules/Package/Passport/PassportRoutesRector.php` has no corresponding test class or `.php.inc` fixture. Every custom Rector rule must have at least one fixture test using `AbstractRectorTestCase`.

## Fix

1. Create `tests/Unit/Rector/Rules/Package/Passport/PassportRoutesRectorTest.php` extending `AbstractRectorTestCase`.
2. Create `tests/Unit/Rector/Rules/Package/Passport/Fixture/PassportRoutes/basic.php.inc` with before/after code.
3. Create `tests/Unit/Rector/Rules/Package/Passport/config/passport_routes_rector.php` test config.
4. Run the new test and verify it passes.

## Acceptance

- [ ] PassportRoutesRectorTest exists and extends AbstractRectorTestCase
- [ ] At least one `.php.inc` fixture exists with real before/after transformation
- [ ] No mocks used (TRD-TEST-003)
- [ ] Test passes in `composer test`
