# P1-13-F3: Integrate EnvMigrator into Atomic Snapshot/Rollback

**Severity:** High  
**Requirement:** CM-01, CM-02, TRD-CONFIG-001 (atomic, all-or-nothing)  
**Finding:** EnvMigrator runs independently. If config migration fails after env migration succeeded, .env changes are not rolled back.

## Fix

Call `EnvMigrator::migrate()` inside `ConfigMigrator::migrate()` within the try/catch block, after the config migrations. The existing snapshot already covers .env files, so rollback will restore them.
