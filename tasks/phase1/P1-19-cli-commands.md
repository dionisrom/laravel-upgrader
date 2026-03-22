# P1-19: CLI Commands (RunCommand, AnalyseCommand, DashboardCommand)

**Phase:** 1 — MVP  
**Priority:** Must Have  
**Estimated Effort:** 3-4 days  
**Dependencies:** P1-01 (Project Scaffold — Symfony Console), P1-10 (Orchestrator), P1-17 (Dashboard Server)  
**Blocks:** P1-22 (Hardening — E2E testing)  

---

## Agent Persona

**Role:** PHP Orchestrator Engineer  
**Agent File:** `agents/php-orchestrator-engineer.agent.md`  
**Domain Knowledge Required:**
- Symfony Console framework (commands, arguments, options, input/output)
- CLI UX patterns (pre-flight summaries, confirmation prompts, progress display)
- Environment variable handling in PHP
- Exit code conventions (0 success, 1 failure, 2 config error)
- CI/CD non-interactive mode patterns

---

## Objective

Implement the three CLI commands (`RunCommand`, `AnalyseCommand`, `DashboardCommand`) and the `VersionCommand` in `src/Commands/`. These are the user-facing entry points that wire together all orchestrator components.

---

## Context from PRD & TRD

### Commands (PRD §10.1)

| Command | Description |
|---|---|
| `upgrader run` | Execute the full upgrade pipeline |
| `upgrader analyse` | Dry-run inventory — no code changes |
| `upgrader dashboard` | Launch dashboard server only |
| `upgrader version` | Show tool version and rule set versions |

### Pre-Flight Summary (TRD §16.1 — TRD-CLI-002)

```
Laravel Enterprise Upgrader v{version}
══════════════════════════════════════
  Repository:  github:org/my-app
  From:        Laravel 8
  To:          Laravel 9
  Dashboard:   http://localhost:8765
  Output:      ./upgrader-output/
  Workspace:   /tmp/upgrader/a1b2c3d4.../

Estimated time: 8–15 minutes for a repo this size.
Press ENTER to confirm, or Ctrl+C to cancel.
```

Skipped in `--no-interaction` / CI mode.

### Full Flag Specification (TRD §16.2 — TRD-CLI-003)

| Flag | Type | Default | Validation |
|---|---|---|---|
| `--repo` | string | required | Valid path or github:/gitlab:/https:// |
| `--token` | string | `$UPGRADER_TOKEN` env | Required for remote repos |
| `--to` | int | `9` | Phase 1: must be 9 |
| `--from` | int | auto-detect | Must be ≤ `--to` |
| `--dry-run` | bool | `false` | No transforms applied |
| `--resume` | bool | `false` | Requires checkpoint |
| `--no-dashboard` | bool | `false` | No dashboard server |
| `--output` | path | `./upgrader-output` | Created if absent |
| `--format` | string | `html,json,md` | Comma-separated |
| `--with-artisan-verify` | bool | `false` | Opt-in artisan |
| `--skip-phpstan` | bool | `false` | Requires confirmation |
| `--no-interaction` | bool | `false` | CI mode |

### PHPStan Skip Confirmation (TRD-CLI-003)

`--skip-phpstan` MUST require typing `"I understand PHPStan will not run"` unless `--no-interaction` is set.

### Input Validation (TRD-CLI-001)

All inputs validated before any Docker operation. Validation failures → clear error + exit code 2.

### Token Security (TRD-SEC-001)

Token from env var or `--token` flag. MUST be redacted in all output via `TokenRedactor`.

---

## Files to Create

| File | Location | Purpose |
|---|---|---|
| `RunCommand.php` | `src/Commands/` | Full upgrade pipeline |
| `AnalyseCommand.php` | `src/Commands/` | Dry-run analysis only |
| `DashboardCommand.php` | `src/Commands/` | Standalone dashboard |
| `VersionCommand.php` | `src/Commands/` | Version/rule info |
| `TokenRedactor.php` | `src/Commands/` | Redact tokens from output |
| `InputValidator.php` | `src/Commands/` | Validate all CLI inputs |

---

## Acceptance Criteria

- [ ] `upgrader run` executes full pipeline with all options
- [ ] `upgrader analyse` runs dry-run mode (no transforms)
- [ ] `upgrader dashboard` launches standalone dashboard server
- [ ] `upgrader version` shows tool version and bundled rule versions
- [ ] Pre-flight summary displayed before execution
- [ ] Pre-flight skipped in `--no-interaction` mode
- [ ] All flags validated before Docker operations begin
- [ ] Invalid input → clear error + exit code 2
- [ ] `--skip-phpstan` requires typing confirmation (unless `--no-interaction`)
- [ ] Token never appears in any output (redacted via `TokenRedactor`)
- [ ] Token sourced from `--token` flag or `UPGRADER_TOKEN` env var
- [ ] Exit codes: 0 success, 1 pipeline failure, 2 config error
- [ ] Output directory created if it doesn't exist
- [ ] `--resume` flag integrated with checkpoint system (P1-16)

---

## Implementation Notes

- Use Symfony Console's `InputOption` and `InputArgument` for flag definitions
- `RunCommand` wires together: fetcher → planner → runner → orchestrator
- `AnalyseCommand` reuses the fetcher and detection but skips transformation
- `DashboardCommand` starts only the ReactPHP server (useful for debugging)
- `TokenRedactor` should be a simple string replacement utility
- Consider a `CommandTrait` for shared validation logic
- The `--format` flag accepts comma-separated values and enables specific formatters
