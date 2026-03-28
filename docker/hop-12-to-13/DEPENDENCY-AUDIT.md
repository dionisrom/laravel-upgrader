# Dependency Audit: hop-12-to-13

**Hop:** Laravel 12 → Laravel 13  
**PHP Minimum:** 8.3  
**Rector Version:** ^2.0  
**rector-laravel Version:** ^2.0  
**Audit Date:** 2026-03-22

---

## Upstream Coverage (driftingly/rector-laravel)

The `driftingly/rector-laravel` package provides `LaravelSetList::LARAVEL_130` which covers:

- Model property type coercion changes
- Generic Laravel 13 API identifier renames tracked by the upstream project

**Always check if a rule is already covered upstream before writing a custom rule.**

---

## Custom Rules Required (Gap Analysis)

| Breaking Change | Coverage | Rule |
|---|---|---|
| PHP 8.3 version guard (composer.json check) | ❌ Not in upstream | `PhpVersionGuard` (standalone script) |
| `Model::unguard()` / `reguard()` removal | ❌ Not in upstream | `DeprecatedApiRemover` |
| Deprecated helper function removals | ❌ Not in upstream | `DeprecatedApiRemover` |
| Eloquent strict mode violations | 🔍 Manual review | No rule needed |
| Queue job serialization | ⚠️ Manual review | No rule needed |

---

## PHP Version Guard

The `PhpVersionGuard` is NOT a Rector rule — it's a standalone PHP script run as a pipeline stage in `entrypoint.sh` before Rector. It reads the workspace's `composer.json` and checks if the declared PHP constraint satisfies `>=8.3`. If not, it emits a JSON-ND warning event.

This feeds into the Phase 3 2D HopPlanner which interleaves PHP version hops with Laravel version hops.

---

## PHP Extension Requirements

None beyond standard PHP 8.3 extensions (json, mbstring, tokenizer, xml, fibers).

---

## Notes

- Always drain queues before upgrading to L13 (serialization format change)
- Eloquent strict mode violations may surface in tests after upgrade
- Run `php artisan config:clear && php artisan cache:clear` after hop completion
