# Post-Review: P1-05 — Fix validate() schema completeness

**Source:** P1-05 Breaking Change Registry review  
**Severity:** HIGH  
**Violated:** TRD-REG-001 — `BreakingChangeRegistry::load()` MUST validate the JSON against the schema on startup  

## Problem

`validate()` only checks 5 per-entry keys (`id`, `severity`, `category`, `title`, `automated`) and 4 top-level keys.

TRD §7.1 requires per-entry: `description`, `rector_rule` (key presence), `affects_lumen`, `manual_review_required`, `migration_example` (with `before`/`after`), `official_doc_anchor`.

Top-level: `php_minimum` and `last_curated` are missing from validation.

## Fix

1. Add `php_minimum` and `last_curated` to `$requiredTopLevel` in `validate()`.
2. Add `description`, `affects_lumen`, `manual_review_required`, `migration_example`, `official_doc_anchor` to `$requiredEntryKeys`.
3. Add sub-key validation for `migration_example.before` and `migration_example.after`.
4. Validate `rector_rule` key is present (value may be `null` or a string).
