# P1-20-F2: Missing laravel-8-no-tests fixture directory

**Severity:** MEDIUM  
**Violated Requirements:** P1-20 task spec (fixture listing)  
**Source Task:** P1-20  

## Problem

The task spec requires `tests/Fixtures/laravel-8-no-tests/` — a Laravel 8 app with no unit tests directory. This fixture does not exist.

## Fix

1. Create `tests/Fixtures/laravel-8-no-tests/` with a minimal Laravel 8 structure.
2. Include `composer.json` (laravel/framework ^8.75, no phpunit dev dependency).
3. Include minimal `app/`, `config/`, `routes/` structure.
4. Do NOT include a `tests/` directory (that is the point of this fixture).

## Acceptance

- [ ] Directory exists at `tests/Fixtures/laravel-8-no-tests/`
- [ ] Has a valid Laravel 8 composer.json
- [ ] Does NOT have a `tests/` directory
