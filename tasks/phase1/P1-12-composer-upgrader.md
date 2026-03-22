# P1-12: Composer Dependency Upgrader

**Phase:** 1 — MVP  
**Priority:** Must Have  
**Estimated Effort:** 4-5 days  
**Dependencies:** P1-01 (Project Scaffold), P1-05 (Breaking Change Registry — package-compatibility.json)  
**Blocks:** P1-15 (Verification — ComposerVerifier), P1-18 (Report — dependency blockers)  

---

## Agent Persona

**Role:** Laravel Migration Specialist  
**Agent File:** `agents/laravel-migration-specialist.agent.md`  
**Domain Knowledge Required:**
- Composer.json and composer.lock structure and semantics
- PHP package version constraint syntax (`^`, `~`, `>=`, `||`)
- Laravel ecosystem package compatibility (common packages and their L9 support)
- `composer install` and `composer validate` behaviour
- Understanding of dependency resolution conflicts
- Knowledge of which packages are critical blockers vs. optional

---

## Objective

Implement `DependencyUpgrader.php`, `CompatibilityChecker.php`, and `ConflictResolver.php` in `src-container/Composer/`. These modules handle updating `composer.json` dependency versions for the target Laravel version, checking package compatibility, and resolving conflicts.

---

## Context from PRD & TRD

### DependencyUpgrader (TRD §8.1 — TRD-COMP-001, TRD-COMP-002, TRD-COMP-003)

`upgrade()` MUST:
1. Read `composer.json` from workspace
2. Bump `laravel/framework` to `^9.0`
3. For each package in `require` and `require-dev`, check bundled `package-compatibility.json`
4. Apply known-good version bumps
5. Flag `l9_support: false` or `unknown` packages as blockers

**Blocker behaviour (TRD-COMP-002):**
- Blockers emitted as `dependency_blocker` events BEFORE Rector transforms begin
- Critical severity blockers halt pipeline unless `--ignore-dependency-blockers` is passed

**Post-modification (TRD-COMP-003):**
After modifying `composer.json`, run:
```bash
composer install --no-interaction --prefer-dist --no-scripts
```
On failure → emit `composer_install_failed` event and halt.

### Package Compatibility Schema (TRD §8.2)

```typescript
interface PackageCompatibilityMatrix {
    generated: string;    // ISO date
    packages: Record<string, PackageSupport>;
}
interface PackageSupport {
    "l9_support": boolean | "unknown";
    "l10_support": boolean | "unknown";
    "recommended_version": string | null;  // e.g. "^3.0"
    "notes": string;
}
```

### PRD Requirements

| ID | Requirement |
|---|---|
| CD-01 | Bump `laravel/framework` to `^9.0` |
| CD-02 | Bump all known L9-compatible package versions |
| CD-03 | Flag packages with no known L9 support as blockers before transforms |
| CD-04 | Run `composer install` in container after version bumps |
| CD-05 | Surface composer conflicts in dashboard and report |

---

## Files to Create

| File | Location | Purpose |
|---|---|---|
| `DependencyUpgrader.php` | `src-container/Composer/` | Main dependency upgrade logic |
| `CompatibilityChecker.php` | `src-container/Composer/` | Check packages against compat matrix |
| `ConflictResolver.php` | `src-container/Composer/` | Handle and surface dependency conflicts |
| `DependencyBlocker.php` | `src-container/Composer/` | Value object for blocker information |
| `UpgradeResult.php` | `src-container/Composer/` | Value object for upgrade outcome |

---

## Acceptance Criteria

- [ ] `laravel/framework` bumped to `^9.0` in `composer.json`
- [ ] Known-compatible packages auto-bumped from bundled matrix
- [ ] Packages with `l9_support: false` flagged as blockers
- [ ] Packages with `l9_support: "unknown"` flagged as warnings
- [ ] `dependency_blocker` events emitted BEFORE Rector transforms begin
- [ ] Critical blockers halt pipeline (unless override flag set)
- [ ] `composer install --no-interaction --prefer-dist --no-scripts` runs after bumps
- [ ] `composer_install_failed` event emitted on failure
- [ ] Custom/private packages (not in matrix) flagged as unknown with guidance
- [ ] Original `composer.json` preserved in snapshot for rollback
- [ ] Version bumps are conservative (use recommended version from matrix)

---

## Implementation Notes

- The `package-compatibility.json` is curated and bundled in the Docker image
- Don't attempt to resolve conflicts automatically — surface them clearly
- The compatibility checker should be extensible for Phase 2 package rule sets
- Consider that some packages may need removal (e.g., abandoned packages)
- `composer install` runs inside the container where network may be available for this stage only
- The `--no-scripts` flag is critical — never run package scripts during upgrade
