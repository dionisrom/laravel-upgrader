# P2-06: Package Rule Sets

**Phase:** 2  
**Priority:** Must Have  
**Estimated Effort:** 12-15 days  
**Dependencies:** P2-01 (PackageRuleActivator), P1-06 (Rector Runner), P1-04 (Detection/Inventory)  
**Blocks:** P2-09 (Phase 2 Hardening)  

---

## Agent Persona

**Role:** Rector/AST Transformation Engineer  
**Agent File:** `agents/rector-ast-engineer.agent.md`  
**Domain Knowledge Required:**
- Spatie packages migration patterns (media-library, permissions, activity-log, data, settings)
- Livewire V2→V3 migration (wire:model, lifecycle hooks, namespace change)
- Sanctum/Passport API changes across Laravel versions
- Filament V2→V3 migration patterns
- Nova, Horizon, Telescope version-specific changes
- TRD §18: Package Rule Architecture (version matrix, conditional activation)

---

## Objective

Implement conditional Rector rule sets for popular Laravel ecosystem packages. Rules activate only when the package is detected in `composer.lock`. Each package has a version matrix mapping source→target versions with corresponding transformation rules.

---

## Context from PRD & TRD

### Supported Packages (PRD §6)

| Package | Key Migrations |
|---|---|
| **Spatie (media-library, permissions, activity-log, data, settings)** | Namespace changes, config changes, API renames |
| **Livewire** | V2→V3: `wire:model` → `wire:model.live`, lifecycle hooks, namespace `Livewire\Component` |
| **Sanctum** | Token abilities, middleware changes per Laravel version |
| **Passport** | Scope changes, route registration changes |
| **Filament** | V2→V3: panel builder, resource classes, form/table reorganization |
| **Nova** | Field API changes, resource updates per version |
| **Horizon** | Config structure changes, balancing strategy updates |

### PackageRuleActivator (TRD §18)

```php
final class PackageRuleActivator
{
    /**
     * @param ComposerLockAnalysis $lock
     * @param string $hopVersion  e.g., "8-to-9"
     * @return RectorConfig[]  Additional configs to merge
     */
    public function activate(ComposerLockAnalysis $lock, string $hopVersion): array;
}
```

### Version Matrix (TRD §18)

```php
// Example: spatie/laravel-permission version mapping
return [
    'hop-8-to-9'   => ['from' => '^4.0', 'to' => '^5.0', 'rules' => [SpatiePermission4to5::class]],
    'hop-9-to-10'  => ['from' => '^5.0', 'to' => '^5.5', 'rules' => []],  // minor, no rules needed
    'hop-10-to-11' => ['from' => '^5.5', 'to' => '^6.0', 'rules' => [SpatiePermission5to6::class]],
    // ...
];
```

### Conditional Activation Flow

1. Detection scanner (P1-04) produces `ComposerLockAnalysis`
2. `PackageRuleActivator` checks which packages are present and their versions
3. For each detected package, loads the version matrix for the current hop
4. Returns additional Rector configs to merge into the hop's main config

---

## Files to Create

| File | Location | Purpose |
|---|---|---|
| `PackageRuleActivator.php` | `src/Package/` | Core activator (skeleton from P2-01, full impl here) |
| `PackageVersionMatrix.php` | `src/Package/` | Version mapping registry |
| `AbstractPackageRuleSet.php` | `src/Package/Rules/` | Base class for package rule sets |
| `SpatiePackageRules.php` | `src/Package/Rules/Spatie/` | Spatie suite rules |
| `LivewireRules.php` | `src/Package/Rules/Livewire/` | Livewire V2→V3 rules |
| `SanctumRules.php` | `src/Package/Rules/Sanctum/` | Sanctum migration rules |
| `PassportRules.php` | `src/Package/Rules/Passport/` | Passport migration rules |
| `FilamentRules.php` | `src/Package/Rules/Filament/` | Filament V2→V3 rules |
| `NovaRules.php` | `src/Package/Rules/Nova/` | Nova version rules |
| `HorizonRules.php` | `src/Package/Rules/Horizon/` | Horizon config rules |
| Per-package tests | `tests/Unit/Package/Rules/` | Fixture-based tests per package |
| Version matrix configs | `config/package-rules/` | YAML/JSON version matrices per package |

---

## Acceptance Criteria

- [ ] `PackageRuleActivator` detects packages from `composer.lock` analysis
- [ ] Version matrix correctly maps package versions to rule sets per hop
- [ ] Spatie suite rules handle namespace/config/API changes
- [ ] Livewire V2→V3 rules handle `wire:model`, lifecycle hooks, namespace
- [ ] Sanctum rules handle token abilities and middleware changes
- [ ] Passport rules handle scope and route changes
- [ ] Filament V2→V3 rules handle panel builder migration
- [ ] Nova and Horizon rules handle version-specific changes
- [ ] Rules only activate when package is present in `composer.lock`
- [ ] Each package rule set has fixture-based tests
- [ ] Unknown/unsupported package versions emit warnings (not errors)

---

## Implementation Notes

- Livewire V2→V3 is the most complex package migration — consider splitting into sub-rules
- Filament V2→V3 is similarly complex (panel builder, resources, forms/tables)
- Version matrices should be data-driven (JSON/YAML), not hardcoded in PHP
- The P1-21 design spike for Livewire V2→V3 should provide insights — reference its output
- Packages not in `composer.lock` should be completely skipped (zero overhead)
- Consider: some packages may need config migration too (feed into ConfigMigrator from P1-13)
