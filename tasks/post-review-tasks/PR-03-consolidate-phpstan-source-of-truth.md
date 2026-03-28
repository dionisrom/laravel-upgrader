# PR-03: Consolidate PHPStan Source of Truth

**Phase:** Post-Review  
**Priority:** Medium  
**Estimated Effort:** 0.5 day  
**Dependencies:** P1-01 (Project Scaffold)  
**Blocks:** Clean re-acceptance of the P1-01 scaffold contract  

---

## Agent Persona

**Role:** PHP Orchestrator Engineer  
**Agent File:** `agents/php-orchestrator-engineer.agent.md`  
**Domain Knowledge Required:**
- PHPStan configuration precedence
- Composer script ergonomics
- Keeping build-tool configuration single-sourced and predictable

---

## Objective

Keep the repository's PHPStan analysis level at `6` and make `phpstan.neon` the only source of truth for that level.

---

## Context from Review

### Source Finding

Senior Staff review of P1-01 found conflicting PHPStan level declarations.

### Evidence

- `phpstan.neon` currently sets level `8`
- `composer.json` currently forces `--level=6` in the `phpstan` Composer script

### Required Policy Direction

The requested resolution is:

- keep PHPStan at level `6`
- define that level only in `phpstan.neon`
- remove duplicate level declarations from Composer scripts or other host-side entry points

---

## Files Likely Touched

| File | Why |
|---|---|
| `phpstan.neon` | Canonical PHPStan level definition |
| `composer.json` | Remove duplicated CLI-level override |
| `README.md` and task notes | Update documentation only if they mention the wrong level |
| `tests/` | Add or update config-contract coverage if needed |

---

## Acceptance Criteria

- [ ] `phpstan.neon` defines the host-side PHPStan level as `6`
- [ ] The Composer `phpstan` script does not pass `--level` explicitly
- [ ] Running the repository's PHPStan entrypoint uses the level from `phpstan.neon`
- [ ] No second host-side PHPStan level declaration remains in Composer scripts for the same workflow
- [ ] Any tests or docs that assert the level are updated to reflect the single-source-of-truth policy

---

## Implementation Notes

- Do not split the effective level between config and CLI flags
- Prefer the config file for analysis policy and the Composer script for invocation only
- If tests are added, verify the configured level via file parsing or command construction rather than brittle text snapshots