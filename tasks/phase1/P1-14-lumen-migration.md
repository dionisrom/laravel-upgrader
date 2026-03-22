# P1-14: Lumen Migration Suite

**Phase:** 1 — MVP  
**Priority:** Must Have  
**Estimated Effort:** 10-12 days  
**Dependencies:** P1-01 (Project Scaffold), P1-04 (Inventory Scanner), P1-05 (Breaking Change Registry), P1-09 (Docker Image — lumen-migrator)  
**Blocks:** P1-15 (Verification — runs on migrated Lumen code), P1-18 (Report — LumenAuditReport section), P1-20 (Integration Tests — LumenMigrationTest)  

---

## Agent Persona

**Role:** Laravel Migration Specialist  
**Agent File:** `agents/laravel-migration-specialist.agent.md`  
**Domain Knowledge Required:**
- Deep knowledge of Lumen framework architecture (bootstrap/app.php, no Kernel.php, no facades by default)
- Differences between Lumen and Laravel (routing, middleware, service providers, exception handling)
- `nikic/php-parser` AST parsing for PHP migration analysis
- Laravel 9 scaffold structure (all directories, config files, default providers)
- Lumen's `withFacades()`, `withEloquent()`, `$app->configure()` patterns
- Lumen exception handler vs Laravel exception handler differences
- Understanding that Lumen was discontinued after L9 — this is a one-time migration

---

## Objective

Implement all 10 Lumen migration sub-modules (5 original + 5 new from F-08 audit finding). This is the most complex single module in Phase 1 due to the fundamental architectural differences between Lumen and Laravel.

---

## Context from PRD & TRD

### Lumen Detection (TRD §10.1 — TRD-LUMEN-001)

`LumenDetector::detect()` MUST check BOTH:
1. `laravel/lumen-framework` in `composer.json` require/require-dev
2. Pattern `$app = new Laravel\Lumen\Application` in `bootstrap/app.php`

Both conditions → definitive. Either alone → `lumen_ambiguous` warning.

### ScaffoldGenerator (TRD §10.2 — TRD-LUMEN-002)

```bash
composer create-project laravel/laravel:^9.0 /tmp/laravel-scaffold --no-interaction
```
Then merge Lumen source files into scaffold. Original `bootstrap/app.php` preserved at `bootstrap/lumen-app-original.php`.

### Route Migration (TRD §10.3 — TRD-LUMEN-003)

Parse Lumen routes using `nikic/php-parser`. Map Lumen `$router->group()` syntax to Laravel `Route::group()`. Preserve names, middleware, prefixes. Flag Lumen-specific patterns for manual review.

### Facade & Eloquent Bootstrap (TRD §10.4 — TRD-LUMEN-004, F-08)

- `$app->withFacades()` detected → facades enabled; log for audit
- `$app->withEloquent()` detected → verify `config/database.php` migrated
- ABSENT → emit `lumen_feature_disabled` event (migrated app may have unexpected availability)

### Inline Config Extraction (TRD §10.5 — TRD-LUMEN-005, F-08)

Scan `bootstrap/app.php` for `$app->configure('...')`. For each config name:
1. Locate in Lumen `config/` → copy to scaffold
2. Not found → generate stub + flag manual review

### Exception Handler Migration (TRD §10.6 — TRD-LUMEN-006, F-08)

- Read Lumen `app/Exceptions/Handler.php`
- Detect overridden methods (`report`, `render`, `shouldReport`)
- Map to Laravel Handler method signatures (slightly different)
- Manual review for unmappable handler methods

### Module Structure

```
src-container/Lumen/
├── LumenDetector.php                # Detect Lumen framework
├── ScaffoldGenerator.php            # Generate Laravel 9 scaffold
├── RoutesMigrator.php               # Migrate route files
├── ProvidersMigrator.php            # Migrate service providers
├── MiddlewareMigrator.php           # Migrate middleware registrations
├── ExceptionHandlerMigrator.php     # NEW F-08 — Handler migration
├── FacadeBootstrapMigrator.php      # NEW F-08 — withFacades/withEloquent
├── EloquentBootstrapDetector.php    # NEW F-08 — Eloquent opt-in detection
├── InlineConfigExtractor.php        # NEW F-08 — $app->configure() extraction
└── LumenAuditReport.php             # NEW F-08 — Lumen-specific report section
```

### PRD Requirements

| ID | Requirement |
|---|---|
| LM-01 | Detect Lumen and activate migration mode automatically |
| LM-02 | Generate full Laravel 9 scaffold |
| LM-03 | Migrate routes (web.php + api.php) |
| LM-04 | Migrate service providers to config/app.php |
| LM-05 | Migrate middleware registrations to Kernel.php |
| LM-06 | Migrate exception handler (F-08) |
| LM-07 | Detect withFacades()/withEloquent() and migrate (F-08) |
| LM-08 | Extract inline config from bootstrap/app.php (F-08) |
| LM-09 | Generate LumenAuditReport (F-08) |
| LM-10 | Run full L8→L9 Rector rules after scaffold generation |

---

## Files to Create

| File | Location | Purpose |
|---|---|---|
| `LumenDetector.php` | `src-container/Lumen/` | Framework detection |
| `ScaffoldGenerator.php` | `src-container/Lumen/` | Laravel 9 scaffold creation |
| `RoutesMigrator.php` | `src-container/Lumen/` | Route syntax migration |
| `ProvidersMigrator.php` | `src-container/Lumen/` | Service provider migration |
| `MiddlewareMigrator.php` | `src-container/Lumen/` | Middleware registration migration |
| `ExceptionHandlerMigrator.php` | `src-container/Lumen/` | Exception handler migration (F-08) |
| `FacadeBootstrapMigrator.php` | `src-container/Lumen/` | Facade/Eloquent bootstrap (F-08) |
| `EloquentBootstrapDetector.php` | `src-container/Lumen/` | Eloquent opt-in detection (F-08) |
| `InlineConfigExtractor.php` | `src-container/Lumen/` | Config extraction (F-08) |
| `LumenAuditReport.php` | `src-container/Lumen/` | Lumen report section (F-08) |

---

## Acceptance Criteria

- [ ] Lumen detected via both `composer.json` AND `bootstrap/app.php` pattern
- [ ] Ambiguous detection (one condition only) emits warning
- [ ] Laravel 9 scaffold generated from `laravel/laravel:^9.0` template
- [ ] Original `bootstrap/app.php` preserved as `bootstrap/lumen-app-original.php`
- [ ] Routes migrated from Lumen `$router` syntax to Laravel `Route` syntax
- [ ] Service providers registered in `config/app.php`
- [ ] Middleware registered in `app/Http/Kernel.php`
- [ ] Exception handler methods mapped to Laravel equivalents
- [ ] `withFacades()` / `withEloquent()` correctly detected and handled
- [ ] Inline configs (`$app->configure()`) extracted to `config/` files
- [ ] Missing configs generate stubs with manual review flags
- [ ] LumenAuditReport generates Lumen-specific manual review section
- [ ] Full L8→L9 Rector rules run AFTER scaffold generation (LM-10)
- [ ] All migration steps emit JSON-ND events for dashboard tracking

---

## Implementation Notes

- This is the largest single module — consider splitting into sub-tasks
- Lumen migration is a ONE-TIME path (Lumen discontinued post-L9)
- The scaffold generator needs network access for `composer create-project` — separate container stage
- Route migration requires understanding Lumen's closure-based `$router->group()` vs Laravel's facades
- Test with the `lumen-8-sample` fixture (P1-20)
- The middleware migrator must understand Lumen's single-file middleware registration
- Exception handler differences are subtle — test thoroughly
