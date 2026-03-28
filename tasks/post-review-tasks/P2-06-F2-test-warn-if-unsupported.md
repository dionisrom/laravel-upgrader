# P2-06-F2: Add test for warnIfUnsupported() warning emission

**Source:** P2-06 review finding #2 (MEDIUM)  
**Requirement:** "Unknown/unsupported package versions emit warnings (not errors)"

## Problem

`PackageVersionMatrix::warnIfUnsupported()` calls `trigger_error(..., E_USER_WARNING)` but no test verifies this. The acceptance criterion is unvalidated.

## Fix

Add a test in `PackageVersionMatrixTest` that uses `set_error_handler` or PHPUnit's `expectWarning` equivalent to assert that `warnIfUnsupported()` emits `E_USER_WARNING` when a package is detected but has no matrix entry for the given hop.

Also add a negative test confirming no warning is emitted when the hop IS in the matrix.

## Files to change

- `tests/Unit/Package/PackageVersionMatrixTest.php`
