# Contributing

## Development Setup

### Prerequisites

- PHP 8.2+
- Composer 2.x
- Docker 24+ with BuildKit (for integration tests only)
- Git 2.x

### Install Dependencies

```bash
git clone https://github.com/your-org/laravel-upgrader.git
cd laravel-upgrader
composer install
```

### Build Docker Images (optional — integration tests only)

```bash
docker buildx bake
```

---

## Running Tests

### Unit Tests (no Docker required)

```bash
composer test
# or directly:
php vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite unit --no-coverage
```

### Integration Tests (requires Docker)

```bash
composer test:integration
# or directly:
php vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite integration --no-coverage
```

### Specific test directory

```bash
php vendor/bin/phpunit tests/Unit/Hardening --no-coverage
php vendor/bin/phpunit tests/Unit/Orchestrator --no-coverage
```

### E2E Validation (requires Docker + a test Laravel 8 repo)

```bash
bin/validate-e2e.sh --repo /path/to/laravel-8-app
```

---

## Static Analysis

### PHPStan

```bash
composer phpstan
# or with a specific level:
php vendor/bin/phpstan analyse src src-container --level=8 --no-progress
```

PHPStan runs at level 6 in CI for `src/` and `src-container/`. New code in `tests/Unit/Hardening/` targets level 8 (stricter, since these are the QA gate tests themselves).

### Code Style

```bash
composer cs-check
# or:
php vendor/bin/phpcs --standard=PSR12 src/ src-container/
```

Auto-fix:

```bash
php vendor/bin/phpcbf --standard=PSR12 src/ src-container/
```

---

## Project Structure

See [ARCHITECTURE.md](ARCHITECTURE.md) for a full component diagram and data flow.

Key conventions:

- All production classes use `declare(strict_types=1)` and `final` where appropriate
- All Docker containers emit **JSON-ND only** to stdout (one JSON object per newline)
- Container code in `src-container/` MUST NOT `require` Rector — invoke it as a subprocess
- No hardcoded paths anywhere; use `sys_get_temp_dir()` or injected options

---

## Adding a New Hop Container

See the [hop-container skill](.github/skills/hop-container/SKILL.md) for the scaffolding workflow.

Short version:
1. Create `docker/hop-N-to-M/Dockerfile` and `entrypoint.sh`
2. Add the image to `docker-bake.hcl`
3. Register the hop in `HopPlanner::__construct()` defaults
4. Add Rector config to `rector-configs/rector.lN-to-lM.php`
5. Add integration test fixture in `tests/Fixtures/laravel-N-complex/`

---

## Adding a Custom Rector Rule

See the [rector-rule skill](.github/skills/rector-rule/SKILL.md).

Short version:
1. Check `driftingly/rector-laravel` upstream first — don't duplicate existing rules
2. Create rule class in `src-container/Rector/Rules/`
3. Create fixture file `tests/Fixtures/Rector/YourRule/Fixture/some_change.php.inc`
4. Create test case extending `AbstractRectorTestCase`
5. Register in the relevant `rector-configs/rector.lN-to-lM.php`

---

## Commit Convention

Use [Conventional Commits](https://www.conventionalcommits.org/):

```
feat(hop): add PHP 8.1→8.2 hop container
fix(checkpoint): handle empty filesHashed on resume
test(hardening): add SecurityAuditTest for token redaction
docs: update ARCHITECTURE.md with dashboard SSE flow
```

Types: `feat`, `fix`, `test`, `docs`, `chore`, `refactor`, `perf`, `ci`

---

## Pull Request Process

1. Open a draft PR early so CI runs (PHPStan + unit tests)
2. All unit tests must pass (`composer test`)
3. PHPStan must be clean at the configured level (`composer phpstan`)
4. Code style must pass (`composer cs-check`)
5. If adding or modifying a hop container, include a fixture diff showing before/after output
6. Integration + E2E tests are run separately in the Docker CI job — they are not blocking for host-only changes

---

## Reporting Security Issues

Do not open a public issue for security vulnerabilities. Email `security@your-org.example` with details. See [known-issues.md](known-issues.md) for documented limitations that are not security issues.
