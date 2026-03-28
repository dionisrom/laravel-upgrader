# P1-13-F1: Add Unit Tests for All P1-13 Classes

**Severity:** Critical  
**Requirement:** All acceptance criteria  
**Finding:** No unit tests exist for ConfigMigrator, EnvMigrator, ConfigSnapshotManager, ConfigMerger, or MigrationResult.

## Required Tests

- ConfigMerger: merge preserves custom keys, adds new keys, overwrites only knownChangedKeys scalars, deep-merges nested arrays, renderPhpConfig round-trips
- ConfigSnapshotManager: snapshot creates tar, restore overwrites files, cleanup removes tar, missing file handling
- MigrationResult: success/failure factory methods
- EnvMigrator: MIX_* → VITE_* rename, deprecation comment added, blank/comment lines preserved, quoted values preserved, no-op when no MIX_* keys, VITE_APP_NAME added when MIX_APP_URL present
- ConfigMigrator: full happy path, rollback on failure, each individual migration (auth, cache, filesystem, mail, session)
- EnvMigrationResult: constructor
