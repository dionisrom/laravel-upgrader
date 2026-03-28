# DEPENDENCY-AUDIT.md — hop-8-to-9

**Generated:** 2026-03-21  
**Container:** `upgrader/hop-8-to-9:1.0.0`  
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

`driftingly/rector-laravel` is the maintained fork of `rector/rector-laravel` (archived). It provides `LaravelSetList::LARAVEL_90` which covers the majority of L8→L9 breaking changes automatically.

**Version pinned:** `1.2.6`  
**Constraint:** `^1.2` (patch/minor auto-updates allowed within major)  
**Why this version:** Earliest `1.2.x` release that includes `LaravelSetList::LARAVEL_90` with rule coverage for the 29 breaking changes in `docs/breaking-changes.json`.

### Upstream Coverage vs Custom Rules

| Breaking Change Category | Covered by upstream | Custom rule required |
|---|---|---|
| `LaravelSetList::LARAVEL_90` ruleset | ✅ ~40 rules auto-applied | — |
| `HttpKernel` middleware signature | Partial | `HttpKernelMiddlewareRector` |
| `Model::unguard()` removal | No | `ModelUnguardRector` |
| Password validation rule class | No | `PasswordRuleRector` |
| `whereNot()` → `whereNotIn()` | No | `WhereNotToWhereNotInRector` |

See `docker/hop-8-to-9/docs/breaking-changes.json` for the full 48-entry registry.

---

## vendor-patches/rector-laravel-fork

A local fork of `rector-laravel` is bundled at `/upgrader/vendor-patches/rector-laravel-fork/` inside the image as a **fallback only**. It is not loaded by Composer or autoloaded at runtime. It exists in case the upstream package needs emergency patching without a full image rebuild.

To activate the fork (emergency use only), a maintainer can:
1. Override the autoloader path in the entrypoint via the `RECTOR_VENDOR_PATCH` env var
2. Rebuild a patched image layer using the fork

---

## Lock File Policy (TRD-BUILD-001)

The committed lock file (`composer.hop-8-to-9.lock`) pins all transitive dependency versions exactly. This ensures:

- **Reproducible builds**: every `docker build` installs the identical dependency tree
- **No supply-chain drift**: pinned SHAs prevent upstream mutation attacks
- **Auditability**: the lock file is committed in version control alongside the Dockerfile

**To update the lock file:**
```bash
# On an amd64 Linux host or inside a build container:
cp docker/hop-8-to-9/composer.hop-8-to-9.json composer.json
composer update --no-dev --no-scripts --no-interaction
cp composer.lock docker/hop-8-to-9/composer.hop-8-to-9.lock
git add docker/hop-8-to-9/composer.hop-8-to-9.lock
git commit -m "chore: update hop-8-to-9 composer lock"
```

---

## Image Tag Policy (TRD-BUILD-002)

Images follow the `{image}:{semver}` pattern:

- `upgrader/hop-8-to-9:1.0.0` — release tag (immutable)
- `upgrader/hop-8-to-9:latest` — moving tag (CI/CD convenience)

Semver meaning in this context:
- **patch** (1.0.x): dependency updates, bug fixes, security patches
- **minor** (1.x.0): new custom Rector rules added or rule behaviour changes
- **major** (x.0.0): breaking change to entrypoint API, JSON-ND schema changes, or PHP base image major version bump

---

## Security Notes

- No credentials, tokens, or secrets are present in any image layer
- Image runs as non-root user `upgrader` (UID 1000)
- `--network=none` is enforced by the **host orchestrator** at `docker run` time — it is NOT baked into the image
- All bundled assets (`diff2html`, `breaking-changes.json`, `package-compatibility.json`) are static files with no network fetch at runtime
