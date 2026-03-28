# P2-09-F1: Missing middleware stub files in fixture-monolith and fixture-api

**Source:** P2-09 Hardening review  
**Severity:** High  
**Requirement:** P2-09 acceptance — "All 5 fixture repos complete L8→L13 chain successfully" + "PHPStan level 6 passes on all final outputs"

## Problem

`tests/Fixtures/fixture-monolith/app/Http/Kernel.php` and `tests/Fixtures/fixture-api/app/Http/Kernel.php` reference `App\Http\Middleware\*` classes (Authenticate, TrimStrings, EncryptCookies, VerifyCsrfToken, PreventRequestsDuringMaintenance, RedirectIfAuthenticated) that have no corresponding stub files. This was already fixed for `fixture-minimal` and `fixture-modular` but not propagated to these two fixtures.

Live Docker E2E tests for fixture-monolith and fixture-api will fail because:
1. Rector may error on unresolvable class references
2. PHPStan level 6 final-output verification will fail on missing classes

## Fix

Create the missing middleware stub files for both fixtures, matching the pattern already used in `fixture-minimal`:
- `app/Http/Middleware/Authenticate.php`
- `app/Http/Middleware/TrimStrings.php`
- `app/Http/Middleware/EncryptCookies.php`
- `app/Http/Middleware/VerifyCsrfToken.php`
- `app/Http/Middleware/PreventRequestsDuringMaintenance.php`
- `app/Http/Middleware/RedirectIfAuthenticated.php`

fixture-api only needs: Authenticate, TrimStrings, PreventRequestsDuringMaintenance (from its Kernel.php references).

## Validation

Rebuild hop images and run `FixtureMonolithTest` and `FixtureApiTest` Docker E2E tests. PHPStan level 6 must pass on final output.
