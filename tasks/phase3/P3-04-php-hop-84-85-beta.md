# P3-04: PHP 8.4→8.5 Beta Hop Container

**Phase:** 3  
**Priority:** Must Have  
**Estimated Effort:** 5-6 days  
**Dependencies:** P3-03 (PHP 8.2→8.4 hops, shared patterns)  
**Blocks:** P3-08 (Combined Mode Testing)  

---

## Agent Persona

**Role:** Docker/DevOps Engineer  
**Agent File:** `agents/docker-devops-engineer.agent.md`  
**Domain Knowledge Required:**
- PHP 8.5 RFC tracking (rules added as RFCs land)
- Beta/experimental hop handling — explicit user warnings and acknowledgement flow
- Rector `LevelSetList::UP_TO_PHP_85` (may be incomplete at build time)

---

## Objective

Build the PHP 8.4→8.5 hop container with explicit **BETA** designation. PHP 8.5 is not yet stable, so Rector rules may be incomplete. The hop must require explicit user acknowledgement before proceeding.

---

## Context from PRD & TRD

### Docker Image (PRD §11.1)

| Image | PHP Base | Key Breaking Changes | Rector Set |
|---|---|---|---|
| `upgrader:php-8.4-to-8.5` | PHP 8.5 | TBD — rules added as RFCs land | `LevelSetList::UP_TO_PHP_85` *(beta)* |

### BETA Warning Flow (PRD §11.2, PH-07)

```
$ upgrader upgrade --from-php=8.4 --to-php=8.5

⚠️  PHP 8.4→8.5 hop is marked as BETA
    - PHP 8.5 is not yet a stable release
    - Rector rules may be incomplete
    - Some breaking changes may not be detected
    
    Do you want to proceed? [y/N]: _
```

The user MUST explicitly confirm. In CI mode (`--no-interaction`), the hop is skipped unless `--allow-beta-hops` is explicitly passed.

---

## Files to Create

| File | Location | Purpose |
|---|---|---|
| `Dockerfile` | `docker/php-8.4-to-8.5/` | PHP 8.5 base image |
| `entrypoint.sh` | `docker/php-8.4-to-8.5/` | Pipeline entrypoint with beta guard |
| `php-breaking-changes.json` | `docker/php-8.4-to-8.5/docs/` | PHP 8.4→8.5 changes (updated as RFCs land) |
| `rector.php84-to-php85.php` | `rector-configs/` | Rector config (beta) |
| `BetaHopGuard.php` | `src/Orchestrator/` | Beta hop confirmation logic |
| `BetaHopGuardTest.php` | `tests/Unit/Orchestrator/` | Guard tests |
| Test fixtures | `tests/Unit/PhpHop/` | PHP 8.4→8.5 hop tests |

---

## Acceptance Criteria

- [ ] Docker image builds with PHP 8.5 base
- [ ] BETA warning displayed before execution in interactive mode
- [ ] `--no-interaction` skips beta hop unless `--allow-beta-hops` passed
- [ ] `php-breaking-changes.json` bundled (partial, documented as incomplete)
- [ ] Rector `LevelSetList::UP_TO_PHP_85` applied
- [ ] Report clearly marks all 8.4→8.5 changes as beta-confidence
- [ ] Image can be updated independently as PHP 8.5 rules mature
