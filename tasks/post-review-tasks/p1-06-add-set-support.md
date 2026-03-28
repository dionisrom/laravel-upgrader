# Post-Review: P1-06 — RectorConfigBuilder missing rule set support

**Source:** P1-06 validation review, Finding 5
**Severity:** LOW
**Requirement:** TRD-RECTOR-004
**Status:** Fixed

## Finding

`RectorConfigBuilder::build()` only accepted individual rule class names. TRD-RECTOR-004 requires the generated config to include "All rules from `driftingly/rector-laravel` applicable to the hop," which are registered via set lists (e.g. `LaravelSetList::LARAVEL_90`), not individual rules.

## Fix Applied

Added an optional `$sets` parameter to `build()` that accepts PHP constant references (e.g. `'RectorLaravel\Set\LaravelSetList::LARAVEL_90'`). These are emitted as constant references in the generated config via `$rectorConfig->sets([...])`, not as string literals.

## Validation

`RectorConfigBuilderTest::test_build_includes_sets` verifies:
- `$rectorConfig->sets(` appears in output
- The constant reference is present
- It is NOT wrapped in quotes (i.e. it's a PHP constant, not a string)
