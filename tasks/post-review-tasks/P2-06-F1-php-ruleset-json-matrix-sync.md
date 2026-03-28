# P2-06-F1: Sync PHP RuleSet classes with JSON version matrices

**Source:** P2-06 review finding #1 (HIGH)  
**Requirement:** "Version matrix correctly maps package versions to rule sets per hop"

## Problem

`LivewireRuleSet`, `SpatiePackageRules`, and `FilamentRules` PHP classes declare rules for hop `10-to-11`, but the corresponding JSON matrices have no `rector_config` for that hop. The JSON matrices are the authoritative activation mechanism. The PHP classes give a false picture of coverage.

## Fix

Remove hop `10-to-11` from the PHP RuleSet `getRuleClasses()` and `supportedHops()` methods for all three classes, aligning them with the JSON matrices. Alternatively, if rules ARE needed for 10-to-11, add `rector_config` entries to the JSON files — but based on the JSON notes (e.g., "No breaking PHP API changes"), removal is correct.

## Files to change

- `src-container/Rector/Rules/Package/Livewire/LivewireRuleSet.php`
- `src-container/Rector/Rules/Package/Spatie/SpatiePackageRules.php`
- `src-container/Rector/Rules/Package/Filament/FilamentRules.php`

## Validation

Add a test in `PackageRuleSetTest` that cross-checks PHP RuleSet `supportedHops()` against the JSON matrix files to prevent future drift.
