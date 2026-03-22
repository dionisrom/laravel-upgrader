---
name: hop-container
description: 'Scaffold a complete new hop Docker container for the Laravel Enterprise Upgrader. Use when: creating a new Laravel version hop container (hop-N-to-M), PHP version hop container (php-X.x-to-X.y), or adding a Docker image for a new upgrade hop. Produces: Dockerfile, entrypoint.sh (JSON-ND stdout), Rector config, breaking-changes.json. All containers run network-isolated (--network=none enforced by host).'
argument-hint: 'Hop name and target PHP version, e.g. "hop-9-to-10 PHP 8.1" or "php-8.2-to-8.3"'
---

# Hop Container Scaffolding Workflow

## When to Use

- Creating a new Laravel hop container (e.g., `hop-9-to-10`, `hop-11-to-12`)
- Creating a new PHP version hop container (e.g., `php-8.1-to-8.2`, `php-8.3-to-8.4`)
- Updating an existing container's PHP base version or adding new rules

---

## Container Type Reference

| Hop Type | Base Image | Rector approach | Verification |
|---|---|---|---|
| Laravel hop (L→L) | `php:{target}-cli-alpine` | `rector-laravel` + custom rules | PHPStan + Artisan static + class resolution |
| PHP version hop | `php:{target}-cli-alpine` | `LevelSetList::UP_TO_PHP_{XX}` only | `php -l` + PHPStan baseline delta |

### PHP Version Required per Laravel Hop

| Laravel Hop | Target PHP Base |
|---|---|
| hop-8-to-9   | php:8.1 |
| hop-9-to-10  | php:8.1 |
| hop-10-to-11 | php:8.2 |
| hop-11-to-12 | php:8.2 |
| hop-12-to-13 | php:8.3 |

---

## Step 1: Create Directory Structure

```bash
# For a Laravel hop:
mkdir -p docker/hop-{N}-to-{M}/docs

# For a PHP hop:
mkdir -p docker/php-{X.x}-to-{X.y}/docs
```

---

## Step 2: Create the Dockerfile

Copy [assets/Dockerfile.template](./assets/Dockerfile.template) to `docker/{hop-name}/Dockerfile`.

Replace all `{PLACEHOLDERS}`:
- `{PHP_VERSION}` → target PHP version (e.g., `8.1`, `8.2`, `8.3`)
- `{HOP_NAME}` → hop directory name (e.g., `hop-9-to-10`)

> **Security note:** `--network=none` is NOT set in the Dockerfile. It is enforced by the **host orchestrator** at `docker run` time. Never bake network isolation into the image.

---

## Step 3: Create the Entrypoint Script

Copy [assets/entrypoint.sh.template](./assets/entrypoint.sh.template) to `docker/{hop-name}/entrypoint.sh`.

Replace:
- `{HOP_NAME}` → human-readable hop name (e.g., `hop-9-to-10`)
- `{hop-slug}` → config filename slug (e.g., `l9-to-l10`)

Make it executable: `chmod +x docker/{hop-name}/entrypoint.sh`

**Critical constraints:**
- Stdout MUST be JSON-ND only — never write prose to stdout
- All non-JSON output (debug, progress) goes to stderr
- Exit non-zero on any failure
- Each stage emits a `{stage}.started` and `{stage}.completed` event

---

## Step 4: Create the Rector Config

Copy [assets/rector-config.php.template](./assets/rector-config.php.template) to `rector-configs/rector.{hop-slug}.php`.

**For Laravel hops:**
- Add `LaravelSetList::LARAVEL_{VER}` set
- Add custom rule classes from `src-container/Rector/Rules/{HopNamespace}/`
- NO `LevelSetList` for Laravel hops

**For PHP hops:**
- Use ONLY `LevelSetList::UP_TO_PHP_{XX}` — no custom rules needed
- Do NOT add Laravel sets

---

## Step 5: Create Breaking Changes JSON

Copy [assets/breaking-changes-stub.json](./assets/breaking-changes-stub.json) to `docker/{hop-name}/docs/breaking-changes.json`.

Populate using the `breaking-change-audit` skill (run it first for research).

---

## Step 6: Build and Test Locally

```bash
# Build for local architecture
docker build -t upgrader:{hop-name}:local ./docker/{hop-name}/

# Multi-platform build (CI/release)
docker buildx build \
  --platform linux/amd64,linux/arm64 \
  -t upgrader:{hop-name}:latest \
  ./docker/{hop-name}/

# Smoke test: run against the minimal fixture
docker run --rm --network=none \
  -v "$(pwd)/tests/fixtures/fixture-minimal:/workspace" \
  upgrader:{hop-name}:local
```

---

## Step 7: Verify JSON-ND Output

Every line of stdout must be valid JSON. Verify with:

```bash
docker run --rm --network=none \
  -v /tmp/test-workspace:/workspace \
  upgrader:{hop-name}:local \
  | while IFS= read -r line; do
      echo "$line" | jq . > /dev/null \
        && echo "✓ valid" \
        || echo "✗ INVALID JSON: $line"
    done
```

---

## Step 8: Register the Image

Add the new hop to:
- `src/Orchestrator/HopRegistry.php` — maps hop name to Docker image tag
- `README.md` — update supported hops table

---

## Quality Checklist

- [ ] Dockerfile uses **target** PHP version as base (not source)
- [ ] Multi-stage build: `deps` stage → `runtime` stage
- [ ] `--network=none` NOT in Dockerfile (enforced by host)
- [ ] Image < 200MB compressed
- [ ] Entrypoint emits JSON-ND **only** on stdout
- [ ] Non-zero exit code on failure at any stage
- [ ] Builds for both `linux/amd64` and `linux/arm64`
- [ ] `breaking-changes.json` populated and valid
- [ ] Rector config registered and tested
- [ ] Non-root user in Dockerfile (`USER upgrader`)
- [ ] Hop registered in `HopRegistry.php`

## PHP 8.4→8.5 Beta Hop: Additional Steps

The PHP 8.4→8.5 hop requires special handling:
1. Entrypoint must emit a `hop.beta_warning` event before starting
2. In non-interactive mode (`--no-interaction`), abort unless `ALLOW_BETA_HOP=1` env var is set
3. Report marks all findings with `"confidence": "beta"`
