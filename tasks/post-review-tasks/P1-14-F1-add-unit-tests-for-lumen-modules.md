# P1-14-F1: Add unit tests for all Lumen migration modules

**Parent Task:** P1-14  
**Severity:** Critical  
**Finding:** 9 of 10 Lumen modules have zero unit tests  

## Problem

Only `LumenDetectorTest` exists. No unit tests for: `ScaffoldGenerator`, `RoutesMigrator`, `ProvidersMigrator`, `MiddlewareMigrator`, `ExceptionHandlerMigrator`, `FacadeBootstrapMigrator`, `EloquentBootstrapDetector`, `InlineConfigExtractor`, `LumenAuditReport`.

## Required

Create `tests/Unit/Lumen/` test files for each module covering:
- Normal flow (happy path)
- Edge cases (missing files, empty bootstrap, parse errors)
- Manual review item generation
- JSON-ND event emission (capture stdout)

## Acceptance Criteria

- [ ] Each of the 9 untested modules has a dedicated test file
- [ ] Route migration tests verify AST transformation ($router→Route::)
- [ ] Provider migration tests verify deduplication and config insertion
- [ ] Middleware migration tests verify Kernel.php patching
- [ ] Exception handler tests verify parent class replacement
- [ ] Config extractor tests verify copy vs stub generation
- [ ] All tests pass with `vendor/bin/phpunit tests/Unit/Lumen`
