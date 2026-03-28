# PR-11: PasswordRuleRector — Scope to Password class

**Source:** P1-07 review finding #3  
**Severity:** Medium  
**File:** `src-container/Rector/Rules/L8ToL9/PasswordRuleRector.php`

## Problem

The rule renames any method call matching `requireLetters`, `requireNumbers`, etc. on any object. A custom class with a `requireLetters()` method would be incorrectly transformed.

## Required Fix

Check that the method call's variable type or call chain originates from `Illuminate\Validation\Rules\Password`. A pragmatic AST-only approach: walk the call chain to find a `Password::min()` or `Password::defaults()` static call at the root, or use Rector's node type resolver if available.

## Test Gap

Add a skip fixture `skip_non_password_class.php.inc` with a custom object calling `->requireLetters()` that must NOT be renamed.

## Requirement

Task P1-07: "Password rule namespace changes" — scoped to `Illuminate\Validation\Rules\Password`.
