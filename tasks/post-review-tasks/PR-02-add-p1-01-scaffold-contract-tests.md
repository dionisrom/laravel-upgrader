# PR-02: Add P1-01 Scaffold Contract Tests

**Phase:** Post-Review  
**Priority:** High  
**Estimated Effort:** 1-2 days  
**Dependencies:** PR-01 (Restore CLI Bootstrap Help Path)  
**Blocks:** Confidence in P1-01 remaining green  

---

## Agent Persona

**Role:** PHP Quality Assurance Engineer  
**Agent File:** `agents/php-qa-engineer.agent.md`  
**Domain Knowledge Required:**
- PHPUnit subprocess and CLI smoke-test patterns
- Testing configuration files as contract surfaces
- Regression-test design for bootstrap failures
- Fast, deterministic test design for repository metadata and config files

---

## Objective

Add direct tests for the P1-01 scaffold contract so the project bootstrap, core config files, and required Composer metadata are validated by automated tests rather than assumed indirectly by later task coverage.

---

## Context from Review

### Source Finding

Senior Staff review of P1-01 found that existing tests did not exercise the actual CLI binary and therefore did not catch the broken help path.

### Evidence

- `RunCommandTest` validates `RunCommand` in isolation rather than through `bin/upgrader`
- `HardeningTest` explicitly bypasses the CLI binary when checking `VersionCommand`
- No existing test was found that executes `php bin/upgrader --help`
- No existing test was found that verifies the scaffold config contract for `composer.json`, `phpunit.xml.dist`, or the PHPStan source-of-truth policy

### Requirement Links

- P1-01 acceptance criteria for the binary, PSR-4 autoloading, PHPUnit config, PHPStan config, coding-standard config, and Composer scripts
- P1-20 principle that tests should guard the tool itself against silent regressions

---

## Files Likely Touched

| File | Why |
|---|---|
| `tests/Unit/` or `tests/Integration/` | New scaffold contract tests |
| `bin/upgrader` | Executed by the new smoke test |
| `composer.json` | Verified for required scripts and autoload mappings |
| `phpunit.xml.dist` | Verified for required suites |
| `phpstan.neon` | Verified as the source of truth for PHPStan level |
| `.php-cs-fixer.dist.php` or `phpcs.xml.dist` | Verified as the coding-standard config |

---

## Acceptance Criteria

- [ ] At least one automated test executes `php bin/upgrader --help` in a subprocess and asserts exit code `0`
- [ ] The new CLI smoke test would fail on the currently reviewed broken state
- [ ] Automated tests verify the required Composer scripts exist: `test`, `test:integration`, `phpstan`, `cs-check`
- [ ] Automated tests verify PSR-4 autoload mappings for `App\\` and `AppContainer\\`
- [ ] Automated tests verify `phpunit.xml.dist` declares both `unit` and `integration` suites
- [ ] Automated tests verify that one supported coding-standard config file exists
- [ ] The new tests are deterministic and suitable for the default repository test workflow

---

## Implementation Notes

- Prefer small contract tests over broad filesystem snapshots
- Use subprocess execution for the CLI bootstrap test so the real entrypoint is exercised
- Avoid assertions on incidental formatting when checking help output; assert observable contract only
- Ensure the tests fail for the exact bug discovered in review, not just for unrelated command output changes