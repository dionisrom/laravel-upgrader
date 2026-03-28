# P2-04-F1: Implement DeprecatedApiRemover Rector Rule

**Severity:** HIGH  
**Source:** P2-04 review finding F1  
**Violated:** P2-04 acceptance criteria ("Deprecated API removals handled"), PRD §3.1 (`EloquentBreakingChangeRector`), DEPENDENCY-AUDIT.md gap analysis

## Problem

Task P2-04 requires `src-container/Rector/Rules/L12ToL13/DeprecatedApiRemover.php` to handle deprecated API removal patterns in L12→L13 upgrades. The file does not exist. The fixture `tests/Fixtures/laravel-12-app/app/Models/LegacyOrder.php` expects it to flag `Model::unguard()`/`reguard()` patterns.

## Required

1. Create `src-container/Rector/Rules/L12ToL13/DeprecatedApiRemover.php` as a Rector rule that:
   - Detects `Model::unguard()` / `Model::reguard()` patterns and replaces with `$this->forceFill()` or flags for manual review
   - Detects deprecated helper function removals specific to L13
2. Create fixture test(s) in `tests/Unit/Rector/Rules/L12ToL13/` with `.php.inc` fixtures
3. Register the rule in `rector-configs/rector.l12-to-l13.php` via `->withRules()`

## Validation

- PHPUnit fixture tests pass
- The fixture `LegacyOrder.php` pattern is handled
- Rule is registered in rector config
