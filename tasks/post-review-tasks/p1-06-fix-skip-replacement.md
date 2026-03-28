# Post-Review: P1-06 — RectorConfigBuilder skip() only retains last path

**Source:** P1-06 validation review
**Severity:** HIGH
**Requirement:** TRD-RECTOR-004
**Status:** Fixed

## Finding

`RectorConfigBuilder::build()` called `$rectorConfig->skip()` once per skip path. Rector's `skip()` replaces the entire list on each call, so only the **last** skip path (`storage/`) survived. The critical `.upgrader-state` directory was **not** excluded from Rector processing.

## Fix Applied

Consolidated all skip paths into a single `$rectorConfig->skip([...])` call.

## Validation

`RectorConfigBuilderTest::test_build_generates_valid_config_with_skip_paths` asserts exactly one `skip()` call and that `.upgrader-state` is present.
