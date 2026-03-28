# P1-21-R1: Expand RouteServiceProvider Migration Detail in L10→L11 Spike

**Source:** P1-21 post-review finding #1  
**Severity:** warning  
**File:** `docs/spikes/design-spike-L10-L11-slim-skeleton.md` §5.4  
**Impacted Requirements:** AC1, AC8 — Phase 2 task specification completeness  

## Problem

The L10→L11 spike provides thorough analysis for Kernel.php and Handler.php migrations with standard patterns, enterprise non-standard patterns, and L11 target code. The `RouteServiceProvider` migration in §5.4 is only a brief sketch despite being a Phase 2 module (`RouteServiceProviderMigrator` in §6).

## Missing Content

1. Common enterprise patterns in `RouteServiceProvider::map()`:
   - Versioned API routes (`mapApiV1Routes`, `mapApiV2Routes`)
   - Domain-based routing (`Route::domain(...)`)
   - Conditional route loading (feature flags, environment)
   - Route model binding customisations in `boot()`
   - Global route patterns (`Route::pattern('id', '[0-9]+')`)
2. `configureRateLimiting()` migration path (mentioned in §1.2 but not cross-referenced)
3. Full L11 `withRouting()` parameter mapping for all configurations
4. Enterprise non-standard patterns table (matching Kernel.php §1.2 format)

## Acceptance

- §5.4 expanded with standard patterns + code examples for source (L10) and target (L11)
- Enterprise non-standard patterns table added
- `configureRateLimiting()` migration destination clarified (AppServiceProvider vs withRouting callback)

## Effort

~1 hour of documentation work.
