# PR-16: Strengthen Lumen Contract Tests To Catch No-Op Pipeline Regressions

**Phase:** Post-Review  
**Priority:** High  
**Estimated Effort:** 0.5-1 day  
**Dependencies:** PR-14, PR-15

---

## Objective

Replace the current shallow Lumen integration assertions with requirement-driven tests that fail when scaffold creation, target-path migration, Composer preservation, or report generation are missing.

## Source Finding

Senior Staff Lumen-path audit found that [tests/Integration/LumenMigrationTest.php](c:/dev/laravel-upgrader/tests/Integration/LumenMigrationTest.php) only checks for `pipeline_complete`, a report file, confidence score, and a missing `Laravel\\Lumen\\Application` string. Those assertions do not prove the Lumen migration contract actually ran.

## Evidence

- The test does not assert that `bootstrap/lumen-app-original.php` is preserved
- The test does not assert that a Laravel scaffold target was created and promoted back into the workspace
- The test does not assert that `routes`, `config/app.php`, or `app/Http/Kernel.php` were migrated from the Lumen bootstrap
- The test does not assert that Composer metadata from the source repo survives the migration

## Acceptance Criteria

- [ ] Integration coverage asserts scaffold preservation and promotion into the workspace root
- [ ] Integration coverage asserts route, provider, middleware, and exception-handler migration outcomes on the sample fixture
- [ ] Integration coverage asserts the migrated Composer manifest no longer depends on `laravel/lumen-framework` and still preserves source application packages
- [ ] A regression test would fail against the current entrypoint implementation that only emits `stage_complete` events