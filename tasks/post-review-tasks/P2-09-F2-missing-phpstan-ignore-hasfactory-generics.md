# P2-09-F2: Missing @phpstan-ignore missingType.generics on HasFactory in fixture-monolith and fixture-api

**Source:** P2-09 Hardening review  
**Severity:** Medium  
**Requirement:** P2-09 acceptance — "PHPStan level 6 passes on all final outputs"

## Problem

`fixture-minimal` and `fixture-modular` User models have `/** @phpstan-ignore missingType.generics */` on the `use HasFactory` line. `fixture-monolith` and `fixture-api` User models do not.

After upgrading to Laravel 13, PHPStan level 6 flags the missing generic type parameter on `HasFactory`. This will cause the final-output PHPStan assertion to fail for these fixtures.

## Fix

Add `/** @phpstan-ignore missingType.generics */` annotation to the `use HasFactory` line in:
- `tests/Fixtures/fixture-monolith/app/Models/User.php`
- `tests/Fixtures/fixture-api/app/Models/User.php`

## Validation

Rebuild hop images and run `FixtureMonolithTest` and `FixtureApiTest`. PHPStan level 6 must pass.
