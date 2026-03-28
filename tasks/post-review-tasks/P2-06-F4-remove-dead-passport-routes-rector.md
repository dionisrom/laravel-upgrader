# P2-06-F4: Remove dead PassportRoutesRector no-op rule

**Source:** P2-06 review finding #4 (LOW)  
**Requirement:** Clean code, no dead code

## Problem

`PassportRoutesRector` always returns null. It is not registered in any Rector config or rule set. Its test verifies it does nothing. It exists only as documentation.

## Fix

Remove `PassportRoutesRector.php`, its test `PassportRoutesRectorTest.php`, and its fixture directory. If the documentation value is wanted, convert the class docblock content into a note in the `laravel-passport.json` matrix file.

## Files to remove

- `src-container/Rector/Rules/Package/Passport/PassportRoutesRector.php`
- `tests/Unit/Rector/Rules/Package/Passport/PassportRoutesRectorTest.php`
- `tests/Unit/Rector/Rules/Package/Passport/Fixture/` (directory)
- `tests/Unit/Rector/Rules/Package/Passport/config/` (directory)
