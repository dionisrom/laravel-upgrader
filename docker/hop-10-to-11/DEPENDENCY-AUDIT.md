# Dependency Audit: hop-10-to-11

**Hop:** Laravel 10 → Laravel 11  
**PHP requirement:** ^8.2  
**Audit date:** 2026-03-26

---

## Runtime Dependencies

| Package | Version | Purpose |
|---|---|---|
| `rector/rector` | ^1.2 | AST transformation engine |
| `driftingly/rector-laravel` | ^1.2 | Laravel-specific Rector rules (LaravelSetList::LARAVEL_110) |
| `nikic/php-parser` | ^4.19 | PHP AST parsing for SlimSkeleton analysis |

---

## Key Notes

### 1. SlimSkeleton Module Uses php-parser Directly

Unlike other hops that rely primarily on Rector for transforms, the `SlimSkeleton` module uses `nikic/php-parser` directly for **read-only AST analysis** of `Kernel.php`, `Handler.php`, and `Console/Kernel.php`. This is a scaffold regeneration pattern, not a Rector transform.

### 2. No Network Access at Runtime

The `--network=none` flag is enforced by the host orchestrator (DockerRunner). Do not bake network isolation into the Dockerfile — per project architectural constraints.

### 3. Non-Root Execution

All container processes run as `USER upgrader` (uid 1000). The entrypoint.sh does not require root.

### 4. PHP Parser Version Note

`rector/rector` bundles an internal copy of `nikic/php-parser`. The `composer.hop-10-to-11.json` also declares `nikic/php-parser: ^4.19` explicitly so the SlimSkeleton PHP classes can use it directly (same major version, Composer resolves to the shared copy).

---

## Breaking Changes Coverage

See `docs/breaking-changes.json` for the full list of 15 L10→L11 breaking changes.

**Automated by this hop:** L11-001, L11-002, L11-003, L11-004, L11-005, L11-006, L11-007, L11-008, L11-012, L11-014

**Manual review flagged:** L11-009, L11-010, L11-011, L11-013, L11-015
