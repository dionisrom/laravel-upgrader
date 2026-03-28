# P2-03-F1: Remove OnceHelperIntroducer rule (misattributed to L12)

**Severity:** HIGH  
**Source finding:** F1 from P2-03 review  
**Requirement violated:** P2-03 AC "once() helper introduced where applicable"

## Problem

The `once()` helper was introduced in **Laravel 11**, not Laravel 12. The `OnceHelperIntroducer` rule and the `l12_once_helper_added` breaking-changes.json entry are factually wrong.

## Action

1. Remove `OnceHelperIntroducer.php` from `src-container/Rector/Rules/L11ToL12/`.
2. Remove registration from `rector-configs/rector.l11-to-l12.php`.
3. Remove `l12_once_helper_added` entry from `docker/hop-11-to-12/docs/breaking-changes.json`.
4. Remove `OnceHelperIntroducerTest.php` from `tests/Unit/Rector/Rules/L11ToL12/`.

## Validation

- Rector config loads without error.
- PHPUnit passes with no missing class references.
