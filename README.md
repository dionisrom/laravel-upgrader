# Laravel Enterprise Upgrader

Automated upgrade tool for Laravel 8→9 (and beyond) enterprise applications. Runs Rector transforms and verification inside isolated Docker containers, produces diff reports, and never modifies the original repository unless all verification passes.

---

## Prerequisites

| Requirement | Minimum version |
|---|---|
| PHP | 8.2 |
| Composer | 2.x |
| Docker | 24+ with BuildKit enabled |
| Git | 2.x |

---

## Installation

```bash
# Clone and install dependencies
git clone https://github.com/your-org/laravel-upgrader.git
cd laravel-upgrader
composer install

# Build hop container images (requires Docker with BuildKit)
docker buildx bake
```

This builds two images:
- `upgrader/hop-8-to-9` — Laravel 8→9 Rector transforms + verification
- `upgrader/lumen-migrator` — Lumen 8 → Laravel 9 migration

---

## Quick Start

```bash
# Upgrade a local Laravel 8 application to Laravel 9
bin/upgrader run --repo /path/to/laravel-8-app --to 9
bin/upgrader run --repo C:/dev/marketplace/marketplace-gateway --to 9

# Dry-run (analyse only, no changes written back)
bin/upgrader run --repo /path/to/laravel-8-app --to 9 --dry-run

# Resume an interrupted run from its last checkpoint
bin/upgrader run --repo /path/to/laravel-8-app --to 9 --resume
```

---

## Commands

### `upgrader run`

Executes the full upgrade pipeline: fetch → workspace → hops → verify → report → write-back.

```
Options:
  --repo                  Repository source (required). One of:
                            /absolute/local/path
                            github:org/repo
                            gitlab:org/repo
                            https://github.com/org/repo
  --to                    Target Laravel version (default: 9)
  --from                  Source Laravel version (auto-detected if omitted)
  --token                 Auth token for GitHub/GitLab (or UPGRADER_TOKEN env var)
  --dry-run               Analyse only — Rector transforms are computed but NOT
                          written back to the original repository
  --resume                Resume from the last saved checkpoint after interruption
  --no-dashboard          Disable the real-time React dashboard (port 8765)
  --output                Output directory for reports (default: ./upgrader-output)
  --format                Comma-separated report formats: html,json,md
                          (default: html,json,md)
  --with-artisan-verify   Run `php artisan route:list` verification after upgrade
                          (opt-in; requires DB and .env — see Known Issues)
  --skip-phpstan          Skip PHPStan verification step (requires typed confirmation)
```

### `upgrader analyse`

Alias for `upgrader run --dry-run`. Produces reports without writing back changes.

```bash
bin/upgrader analyse --repo /path/to/laravel-8-app
```

### `upgrader dashboard`

Starts the standalone real-time dashboard server on port 8765. Normally launched automatically by `run`; use this to reconnect a browser to a running pipeline.

```bash
bin/upgrader dashboard --log ./upgrader-output/audit.jsonnd
# Then open: http://localhost:8765
```

### `upgrader version`

Displays tool version, bundled Rector rule set versions, and current PHP version.

```bash
bin/upgrader version
# Laravel Enterprise Upgrader v1.0.0
# Bundled Rector rules: L8→L9 (4 custom + upstream driftingly/rector-laravel:1.2.6)
# PHP: 8.2.x
```

---

## Exit Codes

| Code | Meaning |
|---|---|
| `0` | Success — all hops completed and verification passed |
| `1` | Pipeline failure — a hop container exited non-zero or verification failed |
| `2` | Configuration error / invalid input — bad options, missing `--repo`, etc. |

---

## Authentication

For private repositories, provide an authentication token either as a CLI option or an environment variable:

```bash
# Via flag (token is redacted from all log output)
bin/upgrader run --repo github:myorg/myapp --token ghp_xxxx --to 9

# Via environment variable (recommended for CI)
export UPGRADER_TOKEN=ghp_xxxx
bin/upgrader run --repo github:myorg/myapp --to 9
```

The `UPGRADER_TOKEN` environment variable is always preferred over embedding tokens in shell history.

---

## Resume / Checkpoint

If an upgrade run is interrupted (container crash, Ctrl-C, power loss), resume from the last successful hop checkpoint:

```bash
bin/upgrader run --repo /path/to/laravel-8-app --to 9 --resume
```

Checkpoints are stored at `{workspace}/.upgrader-state/checkpoint.json`. They are written atomically (temp file → rename) and are idempotent — re-running a completed hop is safe.

---

## Output

After a successful run, `./upgrader-output/` (or the path given by `--output`) contains:

| File | Description |
|---|---|
| `report.html` | Diff2Html report — fully offline, no external CDN dependencies |
| `report.json` | Machine-readable upgrade results with per-file change metadata |
| `manual-review.md` | Items flagged for human review (cannot be auto-fixed by Rector) |
| `audit.log.json` | Full JSON-ND event log (one event per line) for debugging / audit |

---

## Performance

| Repository size | Typical runtime |
|---|---|
| Small (< 100 PHP files) | 3–5 minutes |
| Typical (500 PHP files) | 8–15 minutes |
| Large (2000+ PHP files) | 20–40 minutes |

Memory usage inside containers is bounded; the host process peak is < 64 MB.

---

## Real-Time Dashboard

During a run, a React-based dashboard is served at `http://localhost:8765`. It displays:

- Current hop progress
- Files changed in real-time (diff view)
- Breaking changes applied
- Manual review items
- Verification results

Suppress with `--no-dashboard` for non-interactive / CI environments.

---

## Security

- **Token redaction** — Auth tokens are never written to logs or error output (`TokenRedactor` wraps all `OutputInterface` writes)
- **Isolated workspaces** — Temporary workspaces are created with `0700` permissions; the original repository is never modified until all verification passes
- **Network isolation** — Docker containers run with `--network=none`; no outbound network calls from inside containers
- **Non-root containers** — All containers run as `USER upgrader` (UID 1000)
- **No hardcoded paths** — All paths derived from CLI options or system temp dir

---

## Known Issues

See [known-issues.md](known-issues.md) for documented limitations and workarounds.
