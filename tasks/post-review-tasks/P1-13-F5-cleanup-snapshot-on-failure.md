# P1-13-F5: Cleanup Snapshot Tar on Failure Path

**Severity:** Medium  
**Requirement:** Resource hygiene  
**Finding:** On rollback, `restore()` is called but `cleanup()` is not — the tar remains on disk.

## Fix

Add `$this->snapshotManager->cleanup($snapshotPath)` after `restore()` in the catch block of `ConfigMigrator::migrate()`.
