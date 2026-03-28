# CI/CD Integration Templates

Ready-to-use pipeline templates that drop into any repository to run the Laravel Enterprise Upgrader as part of CI.

Supported platforms:
- **GitHub Actions** — `workflow_dispatch` with artifact upload
- **GitLab CI** — stages, Docker-in-Docker, manual jobs
- **Bitbucket Pipelines** — custom pipelines, Docker-in-Docker

---

## Modes

All templates support two modes, selected at run time:

| Mode | Behaviour |
|---|---|
| `dry-run` | Run the upgrader with `--dry-run`. Generates a diff report uploaded as an artifact. No commits made. |
| `auto-upgrade` | Run the upgrader, commit changes to a new branch `upgrade/l{from}-to-l{to}`, and open a PR/MR. |

---

## Quick start — copy + configure

### GitHub Actions

1. Copy `github/github-actions.yml` to `.github/workflows/laravel-upgrade.yml` in your repository.
2. Add `UPGRADER_TOKEN` to **Settings → Secrets and variables → Actions** (required for private repos; omit for public repos). The default `GITHUB_TOKEN` is used automatically for the PR step.
3. Trigger: **Actions → Laravel Upgrade → Run workflow**.

> **Note:** The workflow requires `contents: write` and `pull-requests: write` permissions on the `GITHUB_TOKEN`. These are granted by the template automatically for `auto-upgrade` mode.

---

### GitLab CI

1. Copy `gitlab/gitlab-ci.yml` to your repository root as `.gitlab-ci.yml` (or [`include`](https://docs.gitlab.com/ee/ci/yaml/#include) it from a shared location).
2. Add CI/CD variables (**Settings → CI/CD → Variables**):
   - `UPGRADER_TOKEN` — masked, for private repo access.
   - `CI_PUSH_TOKEN` — a **project access token** with `write_repository` scope (required for `auto-upgrade` mode to push a branch and open an MR).
3. Trigger: **CI/CD → Pipelines → Run pipeline**, then run the `upgrade:dry-run` or `upgrade:auto` job manually.

Override the default `FROM_VERSION`, `TO_VERSION`, or `UPGRADER_IMAGE` variables either in the CI/CD variable settings or when triggering the pipeline.

---

### Bitbucket Pipelines

1. Copy `bitbucket/bitbucket-pipelines.yml` to your repository root as `bitbucket-pipelines.yml`.
2. Add repository variables (**Repository settings → Pipelines → Repository variables**):
   - `UPGRADER_TOKEN` — secured.
   - `BB_AUTH_TOKEN` — an **app password** with `repository:write` scope (for `auto-upgrade` mode).
   - `BITBUCKET_USERNAME` — your Bitbucket username (for the API call).
3. Trigger: **Pipelines → Run pipeline → Branch → Custom → `laravel-upgrade-dry-run`** or **`laravel-upgrade-auto`**.

---

## Generator — produce a pre-configured template

Instead of editing the template YAML manually, use the bundled CLI command:

```bash
# Print a GitHub Actions template pre-set for Laravel 10 → 11, dry-run mode
bin/upgrader ci:generate --platform=github --from=10 --to=11 --mode=dry-run

# Pipe directly to the workflow file
bin/upgrader ci:generate \
  --platform=github \
  --from=10 \
  --to=11 \
  --mode=auto-upgrade \
  > .github/workflows/laravel-upgrade.yml

# GitLab, auto-upgrade
bin/upgrader ci:generate --platform=gitlab --from=9 --to=10 --mode=auto-upgrade

# Bitbucket, with a custom registry image
bin/upgrader ci:generate \
  --platform=bitbucket \
  --from=8 \
  --to=11 \
  --image=registry.example.com/laravel-upgrader:stable
```

### Generator options

| Option | Default | Description |
|---|---|---|
| `--platform` | *(required)* | `github`, `gitlab`, or `bitbucket` |
| `--from` | `8` | Source Laravel version |
| `--to` | `9` | Target Laravel version |
| `--mode` | `dry-run` | `dry-run` or `auto-upgrade` |
| `--image` | `ghcr.io/your-org/laravel-upgrader:latest` | Upgrader Docker image |

---

## Security notes

- **No secrets in templates.** All tokens are referenced as CI-platform secrets/variables — never hardcoded.
- **`--network=none`** is passed to every upgrader container run, preventing network access during code transformation.
- **Non-root execution** — the upgrader image runs as a non-root user inside the container.
- For `auto-upgrade` mode, use a scoped token (project-level, minimum write permissions). Avoid using a personal admin token.

---

## Multi-hop upgrades

To upgrade across multiple versions (e.g. Laravel 8 → 13) in a single pipeline run, set `--from=8 --to=13`. The orchestrator will chain the intermediate hops automatically.

```bash
bin/upgrader ci:generate --platform=github --from=8 --to=13 --mode=dry-run
```

The generated template passes `--from` and `--to` directly to the upgrader container; multi-hop orchestration is handled inside the container.
