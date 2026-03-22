# P1-13: Config & Env Migrator

**Phase:** 1 — MVP  
**Priority:** Must Have  
**Estimated Effort:** 3-4 days  
**Dependencies:** P1-01 (Project Scaffold), P1-05 (Breaking Change Registry)  
**Blocks:** P1-15 (Verification — config validation), P1-18 (Report)  

---

## Agent Persona

**Role:** Laravel Migration Specialist  
**Agent File:** `agents/laravel-migration-specialist.agent.md`  
**Domain Knowledge Required:**
- Laravel config file structure (PHP array returns in `config/*.php`)
- `.env` file format (key=value, comments, quotes, multiline)
- Deep merge strategies for PHP arrays
- Atomic file operations (snapshot → apply → rollback pattern)
- Laravel 8→9 specific config changes (`config/auth.php`, etc.)

---

## Objective

Implement `ConfigMigrator.php` (atomic snapshot-based config migration) and `EnvMigrator.php` (.env key renames and additions) in `src-container/Config/`. The config migrator uses a snapshot-rollback pattern where partial success is not a valid state.

---

## Context from PRD & TRD

### Atomic Config Migration (TRD §9.1 — TRD-CONFIG-001, F-10)

```php
public function migrate(string $workspacePath): MigrationResult
{
    $snapshot = $this->snapshotAllConfigs($workspacePath);
    try {
        foreach ($this->getMigrationsForHop() as $migration) {
            $migration->apply($workspacePath);
        }
        return MigrationResult::success();
    } catch (\Throwable $e) {
        $this->restoreSnapshot($snapshot, $workspacePath);
        return MigrationResult::failure($e->getMessage());
    }
}
```

### Snapshot Requirements (TRD-CONFIG-002)

`snapshotAllConfigs()` MUST copy all `config/*.php` and `.env*` files to a temporary snapshot directory. Snapshot MUST be written atomically as a tar archive.

### Deep Merge Strategy (TRD-CONFIG-003, PRD CM-06)

- Custom keys NOT in standard Laravel config MUST be preserved verbatim
- Only keys matching known-changed keys in the breaking change registry are touched
- NEVER overwrite custom config values — merge, never replace

### EnvMigrator (TRD §9.2 — TRD-CONFIG-004, PRD CM-04)

Must parse `.env` preserving:
- Comments (`#` lines)
- Blank lines
- Quoted values
- Multiline values (`\n` escapes)

Renamed keys: add new name, keep old with `# DEPRECATED: use {new_key}` comment.

### PRD Requirements

| ID | Requirement |
|---|---|
| CM-01 | Snapshot all config files before touching any |
| CM-02 | Restore snapshot on any failure (full rollback) |
| CM-03 | Migrate `config/auth.php` changes for L9 |
| CM-04 | Migrate `.env` key renames and additions |
| CM-05 | Flag deprecated config keys with suggested replacements |
| CM-06 | Never overwrite custom config values |

---

## Files to Create

| File | Location | Purpose |
|---|---|---|
| `ConfigMigrator.php` | `src-container/Config/` | Atomic snapshot-based config migration |
| `EnvMigrator.php` | `src-container/Config/` | .env key migration |
| `ConfigSnapshotManager.php` | `src-container/Config/` | Tar-based snapshot/restore |
| `MigrationResult.php` | `src-container/Config/` | Value object for migration outcome |
| `ConfigMerger.php` | `src-container/Config/` | Deep merge with custom key preservation |

---

## Acceptance Criteria

- [ ] All `config/*.php` and `.env*` files snapshotted before any migration
- [ ] Snapshot is a tar archive (atomic)
- [ ] ANY migration failure rolls back all config files from snapshot
- [ ] Partial migration state is impossible — all or nothing
- [ ] Deep merge preserves custom config keys
- [ ] Known-changed keys updated per breaking change registry
- [ ] `.env` comments, blank lines, and quoted values preserved
- [ ] Renamed `.env` keys: new key added, old key kept with deprecation comment
- [ ] Deprecated config keys flagged with suggested replacements
- [ ] Config migration events emitted for dashboard/report consumption

---

## Implementation Notes

- The snapshot tar is temporary — deleted after successful migration
- Use `nikic/php-parser` to safely parse PHP config array files (don't use `include`)
- `.env` parser should be line-by-line (don't use `vlucas/phpdotenv` — different semantics)
- The specific L8→L9 config changes should be data-driven from the breaking change registry
- Keep the migration list extensible — Phase 2 adds more config migrations per hop
