# P1-05: Breaking Change Registry

**Phase:** 1 — MVP  
**Priority:** Must Have  
**Estimated Effort:** 4-5 days  
**Dependencies:** P1-01 (Project Scaffold)  
**Blocks:** P1-07 (Custom Rector Rules), P1-12 (Composer Upgrader), P1-18 (Report Generator)  

---

## Agent Persona

**Role:** Laravel Migration Specialist  
**Agent File:** `agents/laravel-migration-specialist.agent.md`

---

## Objective

Create the breaking change registry system and curate the complete L8→L9 breaking changes JSON data file. This includes the schema, the loader with validation, and the actual breaking change documentation that powers both transformations and reports.

---

## Context from PRD & TRD

### Schema (TRD §7.1)

```typescript
interface BreakingChangeRegistry {
  hop: string;              // e.g. "8_to_9"
  laravel_from: string;     // "8.x"
  laravel_to: string;       // "9.x"
  php_minimum: string;      // "8.0"
  last_curated: string;     // ISO date
  breaking_changes: BreakingChange[];
}

interface BreakingChange {
  id: string;               // globally unique, e.g. "l9_model_dates_removed"
  severity: "blocker" | "high" | "medium" | "low";
  category: "eloquent" | "routing" | "middleware" | "config" | "helpers"
           | "environment" | "package" | "lumen";
  title: string;
  description: string;
  rector_rule: string | null;    // fully-qualified class, or null if manual
  automated: boolean;
  affects_lumen: boolean;
  manual_review_required: boolean;
  detection_pattern?: string;    // regex or AST pattern hint
  migration_example: {
    before: string;              // PHP code snippet
    after: string;               // PHP code snippet
  };
  official_doc_anchor: string;   // URL fragment e.g. "#dates"
}
```

### Package Compatibility Schema (TRD §8.2)

```typescript
interface PackageCompatibilityMatrix {
  generated: string;
  packages: Record<string, PackageSupport>;
}
interface PackageSupport {
  "l9_support": boolean | "unknown";
  "l10_support": boolean | "unknown";
  "recommended_version": string | null;
  "notes": string;
}
```

### Key Requirements

**TRD-REG-001** — `BreakingChangeRegistry::load()` MUST validate JSON against schema on startup. If validation fails, throw `RegistryCorruptException`.

**TRD-REG-002** — Every custom Rector rule in `Rector\Rules\L8ToL9\` MUST have a corresponding entry in `breaking-changes.json`. The `rector_rule` field MUST match exactly.

### File Locations

```
docker/hop-8-to-9/docs/
├── breaking-changes.json         # all L8→L9 breaking changes
└── package-compatibility.json    # ecosystem package support matrix
```

---

## Deliverables

1. **`BreakingChangeRegistry.php`** — Loader class with schema validation
2. **`breaking-changes.json`** — Complete L8→L9 breaking change data (curated from official Laravel 9 upgrade guide)
3. **`package-compatibility.json`** — Major ecosystem package support matrix

---

## Acceptance Criteria

- [ ] `BreakingChangeRegistry::load()` parses and validates JSON against schema
- [ ] `RegistryCorruptException` thrown on invalid/missing JSON
- [ ] `breaking-changes.json` contains ALL breaking changes from Laravel 8→9 upgrade guide
- [ ] Each entry has: id, severity, category, title, description, automated flag, before/after examples
- [ ] Entries for manual-review-only changes have `rector_rule: null` and `manual_review_required: true`
- [ ] `package-compatibility.json` covers major packages (Spatie, Livewire, Sanctum, Passport, Horizon)
- [ ] Registry can filter by: category, severity, automated status
- [ ] Unit tests verify schema validation catches malformed entries
- [ ] Unit tests verify all entries have unique IDs
