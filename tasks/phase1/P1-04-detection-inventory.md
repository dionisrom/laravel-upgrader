# P1-04: Version & Framework Detection + Inventory Scanner

**Phase:** 1 — MVP  
**Priority:** Must Have  
**Estimated Effort:** 3-4 days  
**Dependencies:** P1-01 (Project Scaffold)  
**Blocks:** P1-14 (Lumen Detection), P1-07 (Custom Rector Rules)  

---

## Agent Persona

**Role:** Laravel Migration Specialist  
**Agent File:** `agents/laravel-migration-specialist.agent.md`

---

## Objective

Implement the detection layer that reads `composer.json` and `composer.lock` to determine the current Laravel/Lumen version, PHP version constraints, and performs a full file inventory scan of the project.

---

## Context from PRD & TRD

### Module Location

```
src-container/Detector/
├── FrameworkDetector.php      # Detects Laravel vs Lumen
├── VersionDetector.php        # Reads composer.lock for versions
└── InventoryScanner.php       # Maps all PHP, config, route files
```

### PRD Requirements

| ID | Requirement |
|---|---|
| VD-01 | Read Laravel version from `composer.lock` |
| VD-02 | Detect Lumen via `laravel/lumen-framework` in `composer.json` |
| VD-03 | Detect current PHP version from `composer.json` require constraint |
| VD-04 | Fail with clear message if version outside Phase 1 scope |
| VD-05 | Scan and inventory all PHP files, config files, route files |

### Lumen Detection (TRD-LUMEN-001)

`LumenDetector::detect()` MUST check for the presence of `laravel/lumen-framework` in `composer.json` AND the pattern `$app = new Laravel\Lumen\Application` in `bootstrap/app.php`. Both conditions = definitive. Either alone = `lumen_ambiguous` warning.

### Event Output

The inventory scanner emits a `pipeline_start` event:
```json
{
  "event": "pipeline_start",
  "total_files": 342,
  "php_files": 289,
  "config_files": 15,
  "hop": "8_to_9",
  "ts": 1710000001,
  "seq": 1
}
```

---

## Acceptance Criteria

- [ ] `VersionDetector` correctly reads Laravel/Lumen version from `composer.lock`
- [ ] `VersionDetector` reads PHP version constraint from `composer.json`
- [ ] `FrameworkDetector` distinguishes Laravel from Lumen
- [ ] Lumen detection uses dual check (composer.json + bootstrap/app.php pattern)
- [ ] Ambiguous detection emits `lumen_ambiguous` warning event
- [ ] `InventoryScanner` counts and categorizes all files (PHP, config, routes, views, migrations)
- [ ] Version outside Phase 1 scope throws `InvalidHopException` with clear message
- [ ] `pipeline_start` event emitted with file counts
- [ ] Unit tests with fixture `composer.lock` files for Laravel 8, 9, Lumen 8, 9
