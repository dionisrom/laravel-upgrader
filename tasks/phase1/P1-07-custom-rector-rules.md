# P1-07: Custom Rector Rules (L8→L9)

**Phase:** 1 — MVP  
**Priority:** Critical  
**Estimated Effort:** 8-10 days  
**Dependencies:** P1-05 (Breaking Change Registry), P1-06 (Rector Runner)  
**Blocks:** P1-20 (Test Suite — each rule needs fixture tests)  

---

## Agent Persona

**Role:** Rector/AST Transformation Engineer  
**Agent File:** `agents/rector-ast-engineer.agent.md`  
**Domain Knowledge Required:**
- Expert knowledge of Rector rule authoring (extending `AbstractRector`)
- Deep understanding of `nikic/php-parser` AST node types and visitors
- Familiarity with Laravel 8→9 breaking changes (official upgrade guide)
- Understanding of `driftingly/rector-laravel` existing L9 rule coverage
- Knowledge of `AbstractRectorTestCase` and `.php.inc` fixture testing pattern
- Understanding of which patterns are safe to auto-transform vs. flag for manual review

---

## Objective

Implement all custom Rector rules for the L8→L9 hop that are NOT already covered by `driftingly/rector-laravel`. Each rule maps to a specific breaking change in the registry (P1-05). Every rule must have corresponding `.php.inc` fixture tests.

---

## Context from PRD & TRD

### Key L8→L9 Breaking Changes (PRD §8.4, TRD §7)

Each breaking change entry in `breaking-changes.json` has a `rector_rule` field mapping to the responsible rule class. Rules where `rector_rule: null` are manual-review-only entries.

**Known L8→L9 Changes Requiring Custom Rules:**
- `$dates` property → `$casts` with datetime (ModelDatesRector)
- HTTP Kernel middleware priority changes (HttpKernelMiddlewareRector)
- Removed helper functions (e.g., `str_*`, `array_*` global helpers)
- `Model::unguard()` removal
- Updated Eloquent accessor/mutator syntax
- `Route::match()` signature changes
- Password rule namespace changes
- Job dispatch changes
- Event dispatcher signature updates

### Rule Architecture (TRD §6.2, PRD §7)

```
src-container/Rector/Rules/L8ToL9/
├── ModelDatesRector.php
├── HttpKernelMiddlewareRector.php
├── RemoveDeprecatedHelpersRector.php
├── ModelUnguardRector.php
├── AccessorMutatorRector.php
├── PasswordRuleRector.php
├── ... (one class per breaking change that can be automated)
```

### Test Pattern (TRD §15.1 — TRD-TEST-001, TRD-TEST-002)

Every rule MUST have a test class using `AbstractRectorTestCase`:

```php
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

class ModelDatesRectorTest extends AbstractRectorTestCase {
    public function provideData(): Iterator {
        return $this->yieldFilesFromDirectory(__DIR__ . '/Fixture');
    }
    public function provideConfigFilePath(): string {
        return __DIR__ . '/config/rector_test.php';
    }
}
```

Each fixture (`.php.inc`) uses `-----` separator:

```php
<?php
// Input code (before transformation)
class User extends Model {
    protected $dates = ['created_at', 'deleted_at'];
}
?>
-----
<?php
// Expected output (after transformation)
class User extends Model {
    protected $casts = ['created_at' => 'datetime', 'deleted_at' => 'datetime'];
}
```

### TRD-TEST-003: No Mocks

Test classes MUST NOT use mocks for Rector testing infrastructure. `AbstractRectorTestCase` provides real AST transformation.

### TRD-REG-002: Registry Alignment

Every custom rule class MUST have a corresponding entry in `breaking-changes.json`. The `rector_rule` field MUST match the fully-qualified class name exactly.

---

## Files to Create

| File | Location | Purpose |
|---|---|---|
| `ModelDatesRector.php` | `src-container/Rector/Rules/L8ToL9/` | `$dates` → `$casts` datetime |
| `HttpKernelMiddlewareRector.php` | `src-container/Rector/Rules/L8ToL9/` | Middleware priority updates |
| `RemoveDeprecatedHelpersRector.php` | `src-container/Rector/Rules/L8ToL9/` | Remove deprecated global helpers |
| `ModelUnguardRector.php` | `src-container/Rector/Rules/L8ToL9/` | Remove `Model::unguard()` calls |
| `AccessorMutatorRector.php` | `src-container/Rector/Rules/L8ToL9/` | New accessor/mutator syntax |
| `PasswordRuleRector.php` | `src-container/Rector/Rules/L8ToL9/` | Password rule namespace move |
| Additional rules as identified | `src-container/Rector/Rules/L8ToL9/` | One per automated breaking change |
| Test classes | `tests/Unit/Rector/Rules/L8ToL9/` | One test per rule |
| Fixture files | `tests/Unit/Rector/Rules/L8ToL9/Fixture/` | `.php.inc` files with before/after |
| Test configs | `tests/Unit/Rector/Rules/L8ToL9/config/` | `rector_test.php` per rule |

---

## Acceptance Criteria

- [ ] Every automated entry in `breaking-changes.json` has a corresponding Rector rule
- [ ] Every rule has at least one `.php.inc` fixture test
- [ ] All fixture tests pass via `AbstractRectorTestCase`
- [ ] No mocks used in Rector rule tests (TRD-TEST-003)
- [ ] Rules handle edge cases: empty `$dates`, multiple models in one file, inherited models
- [ ] Magic methods (`__call`, `__get`, etc.) are NOT transformed — flagged as manual review
- [ ] Each rule emits the correct breaking change ID for event tracking
- [ ] Rules are compatible with `rector/rector ^1.0` and `driftingly/rector-laravel`
- [ ] Gap analysis performed: identify what `rector-laravel` already covers vs. custom rules needed

---

## Implementation Notes

- Start with a gap analysis: run `driftingly/rector-laravel` L9 rules on a Laravel 8 fixture and identify what's NOT covered
- Custom rules fill the gaps only — don't duplicate what rector-laravel already handles
- Each rule should be a focused, single-responsibility AST transformation
- Use `PhpDocInfo` and `TypeAnalysis` from Rector for type-aware transformations
- Test fixtures should include edge cases (empty arrays, mixed types, inheritance)
- Rules that cannot safely transform complex patterns should emit manual review events
