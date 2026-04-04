# PR-14: Replace No-Op Lumen Entrypoint Stages With A Real Pipeline Runner

**Phase:** Post-Review  
**Priority:** High  
**Estimated Effort:** 1-2 days  
**Dependencies:** PR-13

---

## Objective

Replace the current shell-driven Lumen entrypoint with a real container-side pipeline runner that invokes implemented Lumen migration modules in the PRD/TRD order and writes transformed Laravel output back to the mounted workspace.

## Source Finding

Senior Staff Lumen-path audit found that [docker/lumen-migrator/entrypoint.sh](c:/dev/laravel-upgrader/docker/lumen-migrator/entrypoint.sh) executes library files directly as if they were CLIs. Several of those files do not contain any command-line bootstrap, so the container can emit `stage_complete` and `pipeline_complete` while doing little or no real migration work.

## Evidence

- [docker/lumen-migrator/entrypoint.sh](c:/dev/laravel-upgrader/docker/lumen-migrator/entrypoint.sh) calls `php /upgrader/src/Lumen/LumenDetector.php`, `Config/ConfigMigrator.php`, `Verification/VerificationPipeline.php`, and `Report/ReportBuilder.php`
- Those files do not expose a direct CLI runner via `if (isset($argv) && realpath($argv[0]) === realpath(__FILE__))`
- The entrypoint does not invoke the implemented Lumen modules that require a scaffold target, including `ScaffoldGenerator`, `RoutesMigrator`, `ProvidersMigrator`, `MiddlewareMigrator`, `InlineConfigExtractor`, or an LM-10 Rector stage

## Acceptance Criteria

- [ ] The Lumen container uses a dedicated PHP pipeline runner instead of treating library classes as scripts
- [ ] The runner executes scaffold generation, route/provider/middleware migration, exception handler migration, facade and eloquent analysis, inline config extraction, Lumen audit generation, Rector L8→L9 execution, verification, and report generation in a deterministic order
- [ ] The runner preserves JSON-ND stdout compatibility
- [ ] The final transformed Laravel workspace is written back to the mounted repository root only after the pipeline succeeds
- [ ] Existing tests would fail if the runner were replaced by the current no-op stage wiring