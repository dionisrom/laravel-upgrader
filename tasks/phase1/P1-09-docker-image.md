# P1-09: Docker Image — hop-8-to-9

**Phase:** 1 — MVP  
**Priority:** Critical  
**Estimated Effort:** 4-5 days  
**Dependencies:** P1-01 (Project Scaffold), P1-05 (Breaking Change Registry JSON), P1-06 (Rector Runner), P1-07 (Custom Rector Rules)  
**Blocks:** P1-10 (Orchestrator — needs image to run), P1-20 (Integration Tests)  

---

## Agent Persona

**Role:** Docker/DevOps Engineer  
**Agent File:** `agents/docker-devops-engineer.agent.md`  
**Domain Knowledge Required:**
- Docker multi-stage builds and Alpine Linux base images
- Docker buildx multi-platform builds (linux/amd64, linux/arm64)
- PHP CLI Docker images and Composer integration
- Container security best practices (no credentials in layers, minimal surface)
- `--network=none` runtime isolation
- Entrypoint script patterns with `set -euo pipefail`
- JSON-ND stdout streaming from containers

---

## Objective

Build the `upgrader:hop-8-to-9` Docker image and entrypoint script that runs the complete L8→L9 transformation pipeline inside an isolated container. Also build the `upgrader:lumen-migrator` image.

---

## Context from PRD & TRD

### Dockerfile Pattern (TRD §5.1)

```dockerfile
FROM php:8.0-cli-alpine

RUN apk add --no-cache git unzip bash
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /upgrader
COPY composer.hop-8-to-9.json composer.json
RUN composer install --no-interaction --prefer-dist --no-dev

COPY composer.hop-8-to-9.dev.json composer.dev.json
RUN composer install --no-interaction --working-dir=/upgrader --prefer-dist

# Bundled knowledge base (no network at runtime)
COPY docker/hop-8-to-9/docs/ /upgrader/docs/

# Container-side source code
COPY src-container/ /upgrader/src/

# Rector config
COPY rector-configs/rector.l8-to-l9.php /upgrader/rector.php

# Inline Diff2Html assets (no CDN — F-11)
COPY assets/diff2html.min.css /upgrader/assets/
COPY assets/diff2html.min.js  /upgrader/assets/

ENTRYPOINT ["/upgrader/entrypoint.sh"]
```

### Entrypoint Contract (TRD §5.3)

1. Set `set -euo pipefail`
2. Run pipeline stages in sequence
3. Emit JSON-ND event to stdout for each stage start, complete, and error
4. Exit code: `0` success, `1` pipeline failure, `2` configuration error

### Pipeline Stages (in order)

```
1. InventoryScanner         (map all files)
2. BreakingChangeRegistry   (read bundled JSON)
3. RectorRunner             (subprocess → JSON diff)
4. WorkspaceManager         (apply diffs)
5. TransformCheckpoint      (write state)
6. DependencyUpgrader       (composer.json)
7. ConfigMigrator           (atomic snapshot)
8. VerificationPipeline     (static, no app boot)
9. ReportBuilder            (HTML/JSON/MD inline)
```

### Security Requirements (TRD §5, TRD-SEC-002, TRD-DOCKER-002)

- `--network=none` at RUNTIME (not baked into image) — TRD-DOCKER-003
- NO credentials, API keys, or tokens in image layers
- Multi-platform: `linux/amd64` and `linux/arm64` — TRD-DOCKER-001

### Phase 1 Images

| Image | PHP Base | Purpose |
|---|---|---|
| `upgrader:hop-8-to-9` | `php:8.0-cli-alpine` | L8→L9 transforms + verification |
| `upgrader:lumen-migrator` | `php:8.0-cli-alpine` | Lumen → Laravel 9 scaffold migration |

---

## Files to Create

| File | Location | Purpose |
|---|---|---|
| `Dockerfile` | `docker/hop-8-to-9/` | Main L8→L9 hop image |
| `entrypoint.sh` | `docker/hop-8-to-9/` | Pipeline orchestration script |
| `composer.hop-8-to-9.json` | `docker/hop-8-to-9/` | Container-side dependencies |
| `Dockerfile` | `docker/lumen-migrator/` | Lumen migration image |
| `entrypoint.sh` | `docker/lumen-migrator/` | Lumen pipeline script |
| `docker-bake.hcl` | project root | Multi-platform build config |

---

## Acceptance Criteria

- [ ] `docker build` succeeds for both images
- [ ] Images built for both `linux/amd64` and `linux/arm64`
- [ ] No credentials or tokens present in any image layer
- [ ] Entrypoint uses `set -euo pipefail`
- [ ] Entrypoint emits JSON-ND events to stdout for each pipeline stage
- [ ] Exit codes: 0 (success), 1 (pipeline failure), 2 (config error)
- [ ] Container runs with `--network=none` at runtime
- [ ] `breaking-changes.json` and `package-compatibility.json` bundled in image
- [ ] `diff2html.min.css` and `diff2html.min.js` bundled inline (no CDN)
- [ ] `vendor-patches/rector-laravel-fork/` included for fallback
- [ ] Container-side composer.lock committed (TRD-BUILD-001)
- [ ] Image tags follow `{image}:{semver}` pattern (TRD-BUILD-002)

---

## Implementation Notes

- The entrypoint.sh is the pipeline orchestrator inside the container
- Each stage is a PHP script invoked by the entrypoint
- JSON-ND events are simple `echo` statements to stdout in the entrypoint
- The lumen-migrator image shares the same base but runs a different pipeline
- Consider a shared base image to reduce build time and layer size
- `DEPENDENCY-AUDIT.md` should document pinned rector-laravel version (TRD-BUILD-003)
