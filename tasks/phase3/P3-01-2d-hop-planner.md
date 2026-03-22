# P3-01: 2D HopPlanner

**Phase:** 3  
**Priority:** Must Have  
**Estimated Effort:** 10-12 days  
**Dependencies:** P2-05 (Multi-Hop Orchestration), P1-10 (Orchestrator/HopPlanner)  
**Blocks:** P3-07 (Dashboard 2D Timeline), P3-08 (Combined Mode Testing)  

---

## Agent Persona

**Role:** PHP Orchestrator Engineer  
**Agent File:** `agents/php-orchestrator-engineer.agent.md`  
**Domain Knowledge Required:**
- Phase 2 `MultiHopPlanner` chain logic (extend to 2 dimensions)
- PHP minimum constraints per Laravel version (critical ordering rules)
- Topological sorting / constraint-based scheduling
- TRD §22: 2D HopPlanner Architecture

---

## Objective

Extend the `HopPlanner` to accept both Laravel and PHP version dimensions. It produces an interleaved `HopSequence` that respects PHP minimum constraints per Laravel version, ensuring PHP reaches the required version before any Laravel hop that needs it.

---

## Context from PRD & TRD

### PHP Minimum Constraint Table (PRD §10.3)

| Laravel Version | PHP Minimum |
|---|---|
| Laravel 9 | PHP 8.0 |
| Laravel 10 | PHP 8.1 |
| Laravel 11 | PHP 8.2 |
| Laravel 12 | PHP 8.2 |
| Laravel 13 | PHP 8.3 |

### Example: L8+PHP8.0 → L13+PHP8.3 (PRD §10.3)

Produces this exact 8-hop sequence:
```
1. L8→L9         (PHP 8.0 already meets L9 minimum)
2. PHP 8.0→8.1   (required before L10)
3. L9→L10
4. PHP 8.1→8.2   (required before L11)
5. L10→L11
6. L11→L12       (PHP 8.2 still meets L12 minimum)
7. PHP 8.2→8.3   (required before L13)
8. L12→L13
```

### Extended Interface (PRD §12.1)

```php
class HopPlanner {
    public function plan(
        string $currentLaravel,  // e.g. "8"
        string $targetLaravel,   // e.g. "13"
        string $currentPhp,      // e.g. "8.0"
        string $targetPhp,       // e.g. "8.3"
    ): HopSequence;
}
```

### New CLI Flags (PRD §10.4)

| Flag | Description |
|---|---|
| `--from-php=8.1 --to-php=8.4` | PHP-only upgrade; no Laravel hop |
| `--from-laravel=8 --to-laravel=13 --from-php=8.0 --to-php=8.3` | Combined; planner interleaves |
| `upgrader analyse --mode=php` | PHP-only dry-run |
| `--skip-extension-check` | Bypass extension compatibility check |

### Functional Requirements (PRD §12.2)

- HP-01: Accept `--from-php` and `--to-php` alongside Laravel flags
- HP-02: Compute interleaved hop sequence respecting PHP constraints
- HP-03: Display computed hop plan before execution; require user confirmation
- HP-04: Support PHP-only mode
- HP-05: Reject invalid combinations (e.g., `--to-laravel=13` with `--to-php=8.1`) with clear error
- HP-06: Persist hop plan in workspace for resume capability

---

## Files to Create

| File | Location | Purpose |
|---|---|---|
| `TwoDimensionalPlanner.php` | `src/Orchestrator/` | 2D planning with constraint interleaving |
| `PhpConstraintTable.php` | `src/Orchestrator/` | PHP minimum per Laravel version |
| `HopSequence.php` | `src/Orchestrator/` | Ordered hop list (both types) |
| `HopType.php` | `src/Orchestrator/` | Enum: Laravel, Php |
| `PlanValidator.php` | `src/Orchestrator/` | Reject invalid combinations |
| `PlanPreviewRenderer.php` | `src/Orchestrator/` | CLI plan display for confirmation |
| `TwoDimensionalPlannerTest.php` | `tests/Unit/Orchestrator/` | Planner tests |
| `PlanValidatorTest.php` | `tests/Unit/Orchestrator/` | Validation tests |
| `HopSequenceTest.php` | `tests/Unit/Orchestrator/` | Sequence generation tests |

---

## Acceptance Criteria

- [ ] L8+PHP8.0→L13+PHP8.3 produces correct 8-hop interleaved sequence
- [ ] PHP-only mode (`--from-php`/`--to-php` without Laravel flags) works
- [ ] Laravel-only mode (Phase 2 behavior) still works unchanged
- [ ] Invalid combinations rejected with clear error message
- [ ] Plan displayed before execution with user confirmation prompt
- [ ] Hop plan persisted in workspace for resume
- [ ] All constraint edge cases tested (e.g., L11→L12 needs no extra PHP hop)
