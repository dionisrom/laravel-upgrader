# P1-20: Test Suite (Unit + Integration + Fixtures)

**Phase:** 1 — MVP  
**Priority:** Critical (Completion Gate)  
**Estimated Effort:** 6-8 days  
**Dependencies:** P1-07 (Custom Rector Rules), P1-09 (Docker Image), P1-14 (Lumen Migration)  
**Blocks:** P1-22 (Hardening — tests must pass before E2E)  

---

## Agent Persona

**Role:** PHP Quality Assurance Engineer  
**Agent File:** `agents/php-qa-engineer.agent.md`  
**Domain Knowledge Required:**
- PHPUnit 10/11 configuration and test patterns
- Rector's `AbstractRectorTestCase` and `.php.inc` fixture format
- Docker-based integration testing (launching containers from tests)
- Laravel 8 and Lumen 8 project structures (for creating realistic fixtures)
- PHPStan configuration for self-analysis
- PSR-12 code style standards and PHP_CodeSniffer

---

## Objective

Build the complete test infrastructure: unit tests for all custom Rector rules (using fixture files), integration tests that run actual Docker containers against test repositories, test fixtures, and CI configuration. This is a Phase 1 completion gate — no release without passing tests.

---

## Context from PRD & TRD

### Unit Tests (TRD §15.1 — TRD-TEST-001, TRD-TEST-002, TRD-TEST-003)

Every custom rule in `Rector\Rules\L8ToL9\` MUST have a test class using `AbstractRectorTestCase`:

```php
class ModelDatesRectorTest extends AbstractRectorTestCase {
    public function provideData(): Iterator {
        return $this->yieldFilesFromDirectory(__DIR__ . '/Fixture');
    }
    public function provideConfigFilePath(): string {
        return __DIR__ . '/config/rector_test.php';
    }
}
```

Fixture format (`.php.inc` with `-----` separator):
```php
<?php
class User extends Model {
    protected $dates = ['created_at', 'deleted_at'];
}
?>
-----
<?php
class User extends Model {
    protected $casts = ['created_at' => 'datetime', 'deleted_at' => 'datetime'];
}
```

**TRD-TEST-003:** NO mocks for Rector testing. Use real AST transformation.

### Integration Tests (TRD §15.2 — TRD-TEST-004, TRD-TEST-005)

`FullHopTest` spins up Docker container against fixtures:
- Exit code 0
- `report.json` with confidence > 80
- `audit.log.json` with `hop_complete` event
- Original fixture files unmodified

Integration tests tagged `@group integration`, excluded from default run.

### Test Fixtures

```
tests/Fixtures/
├── laravel-8-minimal/     # Bare minimum L8 app
├── laravel-8-complex/     # Enterprise-like L8 app with many patterns
├── laravel-8-no-tests/    # L8 app with no unit tests
└── lumen-8-sample/        # Lumen 8 application
```

### CI Requirements (TRD §15.3 — TRD-TEST-006)

On every push to `main` and every PR:
- `composer test` → unit tests (< 60 seconds)
- `composer test:integration` → integration tests (< 15 minutes, Docker required)
- `composer phpstan` → PHPStan level 6 on upgrader codebase
- `composer cs-check` → PSR-12 code style

### PRD Requirements

| ID | Requirement |
|---|---|
| TS-01 | Every custom Rector rule has a fixture test |
| TS-02 | .php.inc fixture format with separator |
| TS-03 | Integration test: full L8→L9 on laravel-8-minimal |
| TS-04 | Integration test: full L8→L9 on laravel-8-complex |
| TS-05 | Integration test: Lumen migration on lumen-8-sample |
| TS-06 | Full test suite runs in CI on every main/PR |
| TS-07 | Test suite is completion gate |

---

## Files to Create

| File | Location | Purpose |
|---|---|---|
| Test classes | `tests/Unit/Rector/Rules/L8ToL9/` | One per custom rule |
| Fixtures | `tests/Unit/Rector/Rules/L8ToL9/Fixture/` | `.php.inc` files |
| Config | `tests/Unit/Rector/Rules/L8ToL9/config/` | Test rector configs |
| `FullHopTest.php` | `tests/Integration/` | Docker-based L8→L9 test |
| `LumenMigrationTest.php` | `tests/Integration/` | Docker-based Lumen test |
| `laravel-8-minimal/` | `tests/Fixtures/` | Minimal L8 fixture app |
| `laravel-8-complex/` | `tests/Fixtures/` | Complex L8 fixture app |
| `laravel-8-no-tests/` | `tests/Fixtures/` | L8 without tests fixture |
| `lumen-8-sample/` | `tests/Fixtures/` | Lumen 8 fixture app |

---

## Acceptance Criteria

- [ ] Every custom Rector rule has at least one `.php.inc` fixture test
- [ ] All fixture tests pass via `AbstractRectorTestCase`
- [ ] No mocks used in Rector rule tests
- [ ] `FullHopTest` launches Docker container on `laravel-8-minimal` → exit 0
- [ ] `FullHopTest` launches Docker container on `laravel-8-complex` → exit 0
- [ ] `LumenMigrationTest` launches Docker container on `lumen-8-sample` → exit 0
- [ ] Integration tests verify `report.json` confidence > 80
- [ ] Integration tests verify `audit.log.json` contains `hop_complete`
- [ ] Original fixture files are NOT modified by integration tests
- [ ] Integration tests tagged `@group integration`
- [ ] `composer test` runs unit tests in < 60 seconds
- [ ] `composer test:integration` runs in < 15 minutes
- [ ] `composer phpstan` passes at level 6
- [ ] `composer cs-check` passes PSR-12
- [ ] Test fixtures are realistic Laravel 8 / Lumen 8 project structures

---

## Implementation Notes

- Fixtures should include realistic file counts and patterns
- `laravel-8-complex` should have: custom middleware, service providers, config overrides, Eloquent models with `$dates`, facades, etc.
- `lumen-8-sample` should have: `withFacades()`, `withEloquent()`, inline config, custom handler
- Integration tests should clean up Docker containers on failure
- Consider using `--rm` flag on Docker runs in integration tests
- The CI pipeline YAML is part of the scaffold (P1-01) but test verification is this task
