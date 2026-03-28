# P2-04-F3: Fix PhpVersionGuard satisfiesMinimum for OR/Range Constraints

**Severity:** MEDIUM  
**Source:** P2-04 review finding F3  
**Violated:** P2-04 acceptance criteria ("PHP version guard warns if source project < 8.3") — false positives for valid constraints

## Problem

`PhpVersionGuard::satisfiesMinimum()` uses a regex that extracts only the first version number. For constraints like `^8.2 || ^9.0` or `>=8.2 <9.0`, it extracts `8.2` and returns `false`, even though both constraints accept PHP 8.3+.

## Required

1. Handle OR constraints (`||`): split on `||` and check if ANY part satisfies the minimum
2. Handle range constraints (`>=X.Y <Z.W`): check if the lower bound allows >=8.3 or if the upper bound is >8.3
3. Add test cases for: `^8.2 || ^9.0`, `>=8.2 <9.0`, `>=8.3 <9.0`, `^8.1 || ^8.2`

## Validation

- `^8.2 || ^9.0` → `true` (9.0 satisfies)
- `>=8.2 <9.0` → `true` (8.3 is in this range)
- `^8.1 || ^8.2` → `false` (neither part satisfies)
- All existing tests still pass
