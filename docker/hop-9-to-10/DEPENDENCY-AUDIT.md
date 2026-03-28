# DEPENDENCY-AUDIT.md — hop-9-to-10

**Generated:** 2026-03-22  
**Container:** `upgrader/hop-9-to-10:1.0.0`  
**TRD Reference:** TRD-BUILD-001 (committed lock file), TRD-BUILD-002 (semver image tags)

---

## Pinned Dependency Versions

| Package | Constraint | Resolved Version | Source |
|---|---|---|---|
| `rector/rector` | `^1.2` | `1.2.10` | packagist |
| `driftingly/rector-laravel` | `^1.2` | `1.2.6` | packagist |
| `nikic/php-parser` | `^4.19` | `4.19.5` | packagist |
| `php` (runtime) | `^8.1` | `8.1-cli-alpine` | Docker Hub |

---

## rector-laravel Audit

### Upstream Package: `driftingly/rector-laravel`

`driftingly/rector-laravel` is the maintained fork of `rector/rector-laravel` (archived). It provides `LaravelSetList::LARAVEL_100` which covers the majority of L9→L10 breaking changes automatically.

**Version pinned:** `1.2.6`  
**Constraint:** `^1.2` (patch/minor auto-updates allowed within major)  
**Why this version:** Earliest `1.2.x` release that includes `LaravelSetList::LARAVEL_100` with rule coverage for L9→L10 changes.

### Upstream Coverage vs Custom Rules

| Breaking Change Category | Covered by upstream | Custom rule required |
|---|---|---|
| `LaravelSetList::LARAVEL_100` ruleset | ✅ ~35 rules auto-applied | — |
| Native return types on framework classes | Partial (common classes only) | `LaravelModelReturnTypeRector` |
| `assertDeleted()` → `assertModelMissing()` | No | `AssertDeletedToAssertModelMissingRector` |
| `dispatchNow()` → `dispatchSync()` | ✅ Upstream coverage | — |
| `$dates` → `$casts` migration | ✅ `UnifyModelDatesWithCastsRector` | — |
| RouteServiceProvider `$namespace` removal | No | Manual review flagged |
| Middleware return types | Partial | Manual review flagged |
| Exception handler return types | Partial | Manual review flagged |
| Password rule defaults | No | Manual review flagged |

See `docker/hop-9-to-10/docs/breaking-changes.json` for the full 15-entry registry.
