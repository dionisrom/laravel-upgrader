# Post-Review: P1-05 — Add TRD-REG-002 cross-check test

**Source:** P1-05 Breaking Change Registry review  
**Severity:** LOW  
**Violated:** TRD-REG-002 — Every custom Rector rule MUST have a corresponding entry in `breaking-changes.json`  

## Problem

No test verifies that every PHP file under `src-container/Rector/Rules/L8ToL9/` has a matching `rector_rule` entry in the registry. A new rule added without a registry entry silently violates TRD-REG-002.

## Fix

Add a PHPUnit test that:
1. Scans `src-container/Rector/Rules/L8ToL9/*.php` for class names.
2. Loads `breaking-changes.json` and collects all `rector_rule` values starting with `AppContainer\Rector\Rules\L8ToL9\`.
3. Asserts every rule class file has a corresponding registry entry.
