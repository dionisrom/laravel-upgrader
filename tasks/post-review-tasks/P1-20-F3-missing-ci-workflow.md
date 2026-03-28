# P1-20-F3: Missing CI workflow for unit tests on push/PR

**Severity:** MEDIUM  
**Violated Requirements:** TS-06, TRD-TEST-006  
**Source Task:** P1-20  

## Problem

No GitHub Actions workflow runs `composer test`, `composer phpstan`, or `composer cs-check` on every push to `main` and every PR. Only `slow-e2e.yml` exists (scheduled/manual). TRD-TEST-006 requires automated CI on every push/PR.

## Fix

1. Create `.github/workflows/ci.yml` triggered on push to `main` and all PRs.
2. Jobs: unit tests (`composer test`), PHPStan (`composer phpstan`), code style (`composer cs-check`).
3. Unit test job must complete in < 60 seconds.
4. Use PHP 8.2 setup.

## Acceptance

- [ ] `.github/workflows/ci.yml` exists
- [ ] Triggers on push to main and all PRs
- [ ] Runs `composer test`, `composer phpstan`, `composer cs-check`
- [ ] Uses PHP 8.2
