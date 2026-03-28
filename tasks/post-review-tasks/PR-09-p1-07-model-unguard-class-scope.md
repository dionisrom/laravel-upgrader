# PR-09: ModelUnguardRector — Scope to Model class

**Source:** P1-07 review finding #1  
**Severity:** High  
**File:** `src-container/Rector/Rules/L8ToL9/ModelUnguardRector.php`

## Problem

The rule removes any `StaticCall` with method name `unguard` or `reguard` regardless of the target class. A call like `SomeOtherClass::unguard()` would be incorrectly removed.

## Required Fix

Add a class name check in `refactor()` to verify the static call target is `Illuminate\Database\Eloquent\Model` or a known alias. Use `$this->isName($staticCall->class, 'Illuminate\Database\Eloquent\Model')` or check against common import aliases (`Model`).

## Test Gap

Add a skip fixture `skip_non_model_unguard.php.inc` with a `SomeService::unguard()` call that must NOT be removed.

## Requirement

Task P1-07 acceptance criterion: "Rules handle edge cases".
