# Dependency Audit: hop-11-to-12

**Hop:** Laravel 11 → Laravel 12  
**PHP Minimum:** 8.2  
**Rector Version:** ^2.0  
**rector-laravel Version:** ^2.0  
**Audit Date:** 2026-03-22

---

## Upstream Coverage (driftingly/rector-laravel)

The `driftingly/rector-laravel` package provides `LaravelSetList::LARAVEL_120` which covers:

- Model `$casts` property → `casts()` method (if not already migrated in L11)
- Generic Laravel 12 API identifier renames tracked by the upstream project

**Always check if a rule is already covered upstream before writing a custom rule.**

---

## Custom Rules Required (Gap Analysis)

| Breaking Change | Coverage | Rule |
|---|---|---|
| Route model binding audit | ❌ Not in upstream | `RouteBindingAuditor` (detect-only) |
| once() helper detection | ❌ Not in upstream | `OnceHelperIntroducer` (suggest-only) |
| Concurrency facade info | ℹ️ Info only | No rule needed |
| Starter kits restructure | 🔍 Manual review | No rule needed |

---

## PHP Extension Requirements

None beyond standard PHP 8.2 extensions (json, mbstring, tokenizer, xml).

---

## Notes

- Laravel 12 has a smaller breaking-change surface than L11 (slim skeleton migration is already done)
- The main risk is third-party package compatibility — verify each package before upgrade
- Carbon 3 behaviour changes for DST edge cases can cause subtle test failures
