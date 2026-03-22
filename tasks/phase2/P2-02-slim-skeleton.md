# P2-02: L10→L11 Slim Skeleton Migration

**Phase:** 2  
**Priority:** Critical (Most Complex Phase 2 Task)  
**Estimated Effort:** 18-22 days (6 weeks allocated in delivery plan)  
**Dependencies:** P1-21 (L10→L11 Design Spike — MANDATORY INPUT), Phase 1 Lumen scaffold pattern  
**Blocks:** P2-05 (Multi-Hop — needs all hops), P2-08 (Diff Viewer — skeleton diff section)  

---

## Agent Persona

**Role:** Laravel Migration Specialist  
**Agent File:** `agents/laravel-migration-specialist.agent.md`  
**Domain Knowledge Required:**
- Deep knowledge of Laravel 11's slim skeleton architecture
- Laravel 10's `app/Http/Kernel.php` and `app/Exceptions/Handler.php` anatomy
- Laravel 11's `bootstrap/app.php` configuration API
- AST parsing with `nikic/php-parser` for code analysis
- Scaffold generation patterns (established in Phase 1 Lumen migration)
- Understanding that this is NOT an AST transform — it's scaffold regeneration

---

## Objective

Implement the complete slim skeleton migration suite for L10→L11. This is the most complex hop because it replaces the traditional Laravel app structure with the slimmed L11 structure. The design spike from P1-21 is the mandatory specification for this work.

---

## Context from PRD & TRD

### Why This Hop Is Different (PRD §4.1)

> Standard AST transformation is insufficient. This is a scaffold regeneration informed by analysing the existing app — the same approach used for Lumen migration in Phase 1.

- `app/Http/Kernel.php` — **REMOVED** by default
- `app/Exceptions/Handler.php` — **REMOVED** by default  
- All middleware, exception handling, service config → `bootstrap/app.php`

### Module Structure (PRD §4.2)

```
src-container/SlimSkeleton/
├── SlimSkeletonGenerator.php        # generates new bootstrap/app.php
├── KernelMigrator.php               # reads Kernel.php → bootstrap/app.php
├── ExceptionHandlerMigrator.php     # reads Handler.php → bootstrap/app.php
├── MiddlewareRegistrationMigrator.php
├── ServiceProviderMigrator.php
├── CustomLogicDetector.php          # detects non-standard logic to preserve
└── SlimSkeletonAuditReport.php      # dedicated diff section in HTML report
```

### TRD Requirements (TRD §17.2 — TRD-P2SLIM-001, TRD-P2SLIM-002)

- `CustomLogicDetector` MUST preserve original files as `.lumen-backup` if they contain non-standard logic
- Backup files included in manual review report

### Functional Requirements (PRD §4.3)

| ID | Requirement |
|---|---|
| SK-01 | Analyse Kernel.php → generate bootstrap/app.php middleware |
| SK-02 | Analyse Handler.php → generate bootstrap/app.php exceptions |
| SK-03 | Migrate service provider registrations |
| SK-04 | Preserve custom Kernel/Handler if non-standard logic exists |
| SK-05 | Use design spike as specification |
| SK-06 | Dashboard shows slim skeleton progress sub-view |
| SK-07 | HTML report has dedicated skeleton diff section |
| SK-08 | Unknown patterns flagged as manual review |

---

## Files to Create

| File | Location | Purpose |
|---|---|---|
| `SlimSkeletonGenerator.php` | `src-container/SlimSkeleton/` | Main orchestrator |
| `KernelMigrator.php` | `src-container/SlimSkeleton/` | Kernel → bootstrap/app.php |
| `ExceptionHandlerMigrator.php` | `src-container/SlimSkeleton/` | Handler → bootstrap/app.php |
| `MiddlewareRegistrationMigrator.php` | `src-container/SlimSkeleton/` | Middleware migration |
| `ServiceProviderMigrator.php` | `src-container/SlimSkeleton/` | Provider migration |
| `CustomLogicDetector.php` | `src-container/SlimSkeleton/` | Non-standard logic detection |
| `SlimSkeletonAuditReport.php` | `src-container/SlimSkeleton/` | Report section |
| `Dockerfile` | `docker/hop-10-to-11/` | PHP 8.2 base image |
| `entrypoint.sh` | `docker/hop-10-to-11/` | Pipeline with skeleton stage |
| `breaking-changes.json` | `docker/hop-10-to-11/docs/` | L10→L11 changes |
| Test classes + fixtures | `tests/` | Unit + integration tests |

---

## Acceptance Criteria

- [ ] Design spike document used as specification (not ad-hoc analysis)
- [ ] Standard Kernel.php middleware groups migrated to `bootstrap/app.php`
- [ ] Standard Handler.php exceptions migrated to `bootstrap/app.php`
- [ ] Service providers migrated to `bootstrap/app.php`
- [ ] Custom/non-standard logic in Kernel.php/Handler.php preserved with backup files
- [ ] Backup files included in manual review report
- [ ] Unknown middleware/handler patterns flagged as manual review
- [ ] Dashboard shows slim skeleton migration progress
- [ ] HTML report includes dedicated skeleton migration section
- [ ] L10 fixture app upgrades to L11 with working `bootstrap/app.php`
- [ ] All fixture tests pass

---

## Implementation Notes

- This follows the same pattern as Lumen migration: analyse existing → generate new scaffold
- The design spike (P1-21) defines what patterns exist in enterprise Kernel.php / Handler.php
- Custom logic detection must be thorough — silently dropping custom middleware is catastrophic
- Consider that some apps have very complex Kernel.php with conditional logic
- 6 weeks allocated because this is the riskiest Phase 2 module
