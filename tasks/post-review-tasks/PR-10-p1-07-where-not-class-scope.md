# PR-10: WhereNotToWhereNotInRector — Scope to Validation Rule classes

**Source:** P1-07 review finding #2  
**Severity:** Medium  
**File:** `src-container/Rector/Rules/L8ToL9/WhereNotToWhereNotInRector.php`

## Problem

The rule renames any `->whereNot()` method call with 2 args to `->whereNotIn()`, regardless of the receiver type. Eloquent Builder's `whereNot()` is a valid method that should NOT be renamed.

## Required Fix

Add type-based scoping. Since Rector operates on AST without full type resolution in all cases, a pragmatic approach is to walk up the call chain and check if the receiver is a `Rule::unique()` or `Rule::exists()` static call. Alternatively, check if the method call chain originates from `Illuminate\Validation\Rule`.

## Test Gap

Add a skip fixture `skip_eloquent_where_not.php.inc` with an Eloquent Builder `->whereNot('column', 'value')` call that must NOT be renamed.

## Requirement

Task P1-07: rule description says "Rule::unique()->whereNot() to whereNotIn()".
