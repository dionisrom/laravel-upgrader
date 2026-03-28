# P2-01-F5: Fix PackageRuleActivator invocation in entrypoint.sh

**Severity:** MEDIUM  
**Source:** P2-01 review  

## Problem

`entrypoint.sh` line calls `"${PHP_BIN}" "${SRC}/Rector/PackageRuleActivator.php" "${WORKSPACE}/composer.lock" "${HOP}"` but `PackageRuleActivator.php` is a namespaced class definition — it declares a class and exits. The `|| true` hides the no-op. Package rules are never actually activated at runtime.

## Fix

Either:
1. Create a CLI wrapper script (e.g., `src-container/Rector/activate-package-rules.php`) that bootstraps autoload, instantiates `PackageRuleActivator`, and calls `activate()`, OR
2. Integrate package rule activation into the Rector config itself (the config could read composer.lock and register additional rules dynamically).

Option 2 is likely more correct since the rules need to end up in the Rector config before `rector process` runs.

## Files

- `docker/hop-9-to-10/entrypoint.sh`
- New: `src-container/Rector/activate-package-rules.php` (if option 1)
- Or: `rector-configs/rector.l9-to-l10.php` (if option 2)
