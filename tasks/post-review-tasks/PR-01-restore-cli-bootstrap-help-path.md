# PR-01: Restore CLI Bootstrap Help Path

**Phase:** Post-Review  
**Priority:** Critical  
**Estimated Effort:** 0.5-1 day  
**Dependencies:** P1-01 (Project Scaffold), P1-19 (CLI Commands)  
**Blocks:** Re-acceptance of P1-01  

---

## Agent Persona

**Role:** PHP Orchestrator Engineer  
**Agent File:** `agents/php-orchestrator-engineer.agent.md`  
**Domain Knowledge Required:**
- Symfony Console command registration semantics
- PHP CLI bootstrap patterns
- Compatibility nuances across supported Symfony Console versions
- Laravel Upgrader command wiring in `bin/upgrader`

---

## Objective

Fix the CLI bootstrap so `bin/upgrader` can load all registered commands and display Symfony Console help successfully on a clean checkout after Composer install.

---

## Context from Review

### Source Finding

Senior Staff review of P1-01 found that the scaffold acceptance criterion for the binary is not currently met.

### Evidence

- `php bin/upgrader --help` currently exits non-zero with a Symfony `LogicException`
- The failure is triggered while registering `DashboardCommand`
- The binary currently registers commands in `bin/upgrader`
- The command set involved includes `RunCommand`, `AnalyseCommand`, `DashboardCommand`, `VersionCommand`, and `CiTemplateGenerator`

### Requirement Links

- P1-01 acceptance criterion: `bin/upgrader` is executable and shows Symfony Console help
- P1-19 objective: CLI commands are the user-facing entry points that wire together all orchestrator components

---

## Files Likely Touched

| File | Why |
|---|---|
| `bin/upgrader` | Bootstrap and command registration |
| `src/Commands/DashboardCommand.php` | Current failing command during registration |
| `src/Commands/RunCommand.php` | Shared command registration semantics |
| `src/Commands/AnalyseCommand.php` | Shared command registration semantics |
| `src/Commands/VersionCommand.php` | Shared command registration semantics |
| `src/Ci/CiTemplateGenerator.php` | Included in CLI bootstrap |

---

## Acceptance Criteria

- [ ] `php bin/upgrader --help` exits with code `0`
- [ ] Help output renders without throwing `LogicException` or any other bootstrap-time exception
- [ ] Help output lists the expected commands: `run`, `analyse`, `dashboard`, `version`
- [ ] The CLI bootstrap works without relying on command-specific test harnesses
- [ ] The fix preserves compatibility with the repository's supported Symfony Console version(s)

---

## Implementation Notes

- Fix the root cause in bootstrap or command metadata rather than suppressing the failing command
- Do not remove `DashboardCommand` from the binary to make help pass
- Validate with the actual binary, not only with direct command invocation
- Coordinate with `PR-02` so the restored path is covered by a regression test