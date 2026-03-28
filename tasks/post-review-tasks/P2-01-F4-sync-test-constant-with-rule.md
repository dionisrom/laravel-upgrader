# P2-01-F4: Sync test CLASS_METHOD_RETURN_TYPES with actual rule

**Severity:** LOW  
**Source:** P2-01 review  

## Problem

`LaravelModelReturnTypeRectorTest` duplicates `CLASS_METHOD_RETURN_TYPES` and re-implements `applyRule()` locally. If the map changes in the rule class (as in F1), the test constant must be updated manually. Drift between these two will silently pass tests while the rule is wrong or vice versa.

## Fix

After F1 adds new entries to the rule, update the test's duplicated constant to match. Add a reflective assertion or a comment pointing to the source of truth.

## Files

- `tests/Unit/Rector/Rules/L9ToL10/LaravelModelReturnTypeRectorTest.php`
