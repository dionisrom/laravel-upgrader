# Known Issues

## KI-001 — PHP Version Pinned in Hop Containers

**Severity:** Medium  
**Affects:** `hop-8-to-9`

The `hop-8-to-9` container uses PHP 8.1 Alpine as its base image. If the target application uses PHP 8.2+ syntax features (readonly classes, `enum` in match, `DNF` types, etc.) in files that Rector needs to parse, PHPStan or Rector may emit parse errors for those files.

**Workaround:** If your codebase already uses PHP 8.2+ syntax, add the affected files to the Rector `skip` array in `rector-configs/rector.l8-to-l9.php` before running the upgrader. The files will be excluded from transformation but will still be copied to the workspace.

---

## KI-002 — Artisan Verification is Opt-In

**Severity:** Low (by design)  
**Affects:** All hop containers

The `--with-artisan-verify` flag runs `php artisan route:list` inside the container after transformation. This requires:
- A valid `.env` file with database credentials
- A reachable database server
- No queue/broadcast drivers that fail to connect on boot

Most enterprise CI environments run containers with `--network=none` and no database, so artisan verification would always fail. Static verification (PHPStan + `php -l`) is sufficient for the vast majority of codebases.

**Workaround:** Only pass `--with-artisan-verify` in environments where a real database and `.env` are available. For CI pipelines, rely on static verification only.

---

## KI-003 — PHPStan Runs at Level 3 Inside Containers

**Severity:** Low (by design)  
**Affects:** VerificationPipeline inside all hop containers

The verification PHPStan pass inside containers runs at level 3 (not the project default of 6 or 8). Pre-upgrade Laravel 8 codebases often have legitimate level 4+ errors that pre-date the upgrade. Running at level 8 would produce hundreds of false-positive regressions.

The baseline delta check (not level) is what matters: only PHPStan errors introduced by the Rector transforms are treated as failures.

**Workaround:** After the upgrade completes, run PHPStan at your target level against the upgraded codebase as a separate step in your own CI pipeline. The upgrader produces a clean diff — it does not guarantee a PHPStan-level-8-clean codebase for pre-upgrade issues.

---

## KI-004 — Large Binary Assets Slow Workspace Creation

**Severity:** Medium  
**Affects:** WorkspaceManager

Workspace creation performs a full recursive directory copy of the repository into a temp directory. Repositories containing large binary files (compiled assets, fixture databases, video files, vendor directories checked in, etc.) can make this step very slow.

**Workaround:**
- Add large binary directories to a `.upgrader-ignore` file (feature planned for Phase 2)
- For now, temporarily remove large binary assets before running the upgrader, or run on a lightweight clone with `--depth=1` and sparse checkout
- Consider `.gitignore`-ing your `public/build/` and `storage/` directories before cloning for upgrade purposes

---

## KI-005 — WSL2 Path Normalisation for Docker Bind Mounts

**Severity:** Medium  
**Affects:** Users running the upgrader on Windows with Docker Desktop + WSL2

When the upgrader is invoked from Windows PowerShell with a Windows path (e.g. `C:\Projects\myapp`), `WorkspaceManager::normalizePath()` normalises the path. However, Docker Desktop with WSL2 requires bind mount paths to be in WSL2 format (`/mnt/c/Projects/myapp`).

**Workaround:** Run the upgrader from inside a WSL2 terminal using a Linux-style path:

```bash
# In WSL2 terminal:
bin/upgrader run --repo /mnt/c/Projects/myapp --to 9
```

Alternatively, run the upgrader from the WSL2 file system (`~/projects/myapp`) rather than mounting a Windows path. Windows paths with spaces are especially problematic for Docker bind mounts.

---

## KI-006 — Single Version Hop per Run (Phase 1)

**Severity:** Low (by design, Phase 1 scope)  
**Affects:** `HopPlanner`

Phase 1 only supports `8 → 9`. Attempting `--from=8 --to=10` will throw `InvalidHopException` because no multi-hop path is registered. Multi-hop support (8→9→10→11) is planned for Phase 2.

**Workaround:** Run the upgrader once per version increment:
```bash
bin/upgrader run --repo /path/to/app --from=8 --to=9
# Then, when Phase 2 is available:
bin/upgrader run --repo /path/to/app --from=9 --to=10
```

---

## KI-007 — Dashboard Port Not Configurable

**Severity:** Low  
**Affects:** ReactDashboardServer, DashboardCommand

The dashboard port is hardcoded at `8765`. If another service is bound to port 8765, the dashboard server will fail to start (logged as a warning; the upgrade run continues).

**Workaround:** Stop any service using port 8765 before running, or pass `--no-dashboard` to disable the dashboard entirely. A `--dashboard-port` option is planned for Phase 2.
