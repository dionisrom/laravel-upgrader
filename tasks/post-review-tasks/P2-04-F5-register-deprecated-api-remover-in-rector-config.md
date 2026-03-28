# P2-04-F5: Register DeprecatedApiRemover in Rector Config

**Severity:** LOW  
**Source:** P2-04 review finding F5 (depends on F1)  
**Violated:** P2-04 acceptance criteria, TRD-P2HOP-001

## Problem

`rector-configs/rector.l12-to-l13.php` only registers package rules via `->withRules($packageRules)`. Once `DeprecatedApiRemover` is created (F1), it must also be registered in the rector config.

## Required

1. After F1 is implemented, add `DeprecatedApiRemover::class` to the `->withRules()` call in `rector.l12-to-l13.php`

## Validation

- Rector config includes the new rule
- PHPStan passes on the config file
