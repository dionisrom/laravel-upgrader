# P1-01: Project Scaffold & Composer Setup

**Phase:** 1 — MVP  
**Priority:** Critical (Foundation)  
**Estimated Effort:** 3-4 days  
**Dependencies:** None (this is the root task)  
**Blocks:** All other Phase 1 tasks  

---

## Agent Persona

**Role:** PHP Orchestrator Engineer  
**Agent File:** `agents/php-orchestrator-engineer.agent.md`

---

## Objective

Set up the complete project structure, Composer configuration, PSR-4 autoloading, and Symfony Console binary entry point for the Laravel Enterprise Upgrader. This is the foundational scaffold on which all other modules will be built.

---

## Context from PRD & TRD

### Repository Layout (TRD §1.3)

```
laravel-upgrader/
├── bin/upgrader                    # Symfony Console binary
├── composer.json                   # host-side dependencies
├── composer.lock
├── src/                            # Host-side PHP (orchestrator)
│   ├── Commands/
│   ├── Orchestrator/
│   │   └── State/
│   ├── Repository/
│   ├── Dashboard/
│   │   └── public/
│   └── Workspace/
├── src-container/                  # PHP deployed inside containers
│   ├── Detector/
│   ├── Documentation/
│   ├── Rector/
│   │   └── Rules/
│   ├── Composer/
│   ├── Config/
│   ├── Lumen/
│   ├── Verification/
│   └── Report/
├── docker/
│   ├── hop-8-to-9/
│   │   ├── Dockerfile
│   │   ├── entrypoint.sh
│   │   └── docs/
│   └── lumen-migrator/
├── rector-configs/
├── vendor-patches/
│   └── rector-laravel-fork/
├── assets/
└── tests/
    ├── Unit/
    ├── Integration/
    └── Fixtures/
```

### Host-Side Dependencies (TRD §28.1)

```json
{
  "require": {
    "php": "^8.2",
    "symfony/console": "^6.4 || ^7.0",
    "symfony/process": "^6.4 || ^7.0",
    "react/http": "^1.9",
    "react/socket": "^1.14",
    "react/event-loop": "^1.3",
    "ramsey/uuid": "^4.0"
  },
  "require-dev": {
    "phpunit/phpunit": "^11.0",
    "phpstan/phpstan": "^1.10",
    "squizlabs/php_codesniffer": "^3.0"
  }
}
```

### Container-Side Dependencies (TRD §28.2)

```json
{
  "require": {
    "php": "^8.0",
    "nikic/php-parser": "^4.18",
    "symfony/process": "^6.0"
  },
  "require-dev": {
    "rector/rector": "^1.0",
    "driftingly/rector-laravel": "^1.0",
    "phpstan/phpstan": "^1.10",
    "phpunit/phpunit": "^10.0"
  }
}
```

### Prohibited Dependencies (TRD §28.3)

- Any Rector fork (only official `rector/rector` permitted)
- `laravel/framework` (upgrader must not depend on what it transforms)
- Any package requiring PHP < 8.0 for host code

### Runtime Requirements (TRD §1.2)

| Component | Language | Min Version |
|---|---|---|
| Orchestrator (host) | PHP | 8.2 |
| Hop containers | PHP | Per-hop base |
| Dashboard frontend | Vanilla JS | ES2020 |
| Entrypoint scripts | bash | 5.x |

---

## Acceptance Criteria

- [ ] `composer.json` created with all host-side dependencies
- [ ] `composer install` succeeds without errors
- [ ] PSR-4 autoloading configured for `App\` → `src/` and `AppContainer\` → `src-container/`
- [ ] `bin/upgrader` is executable and shows Symfony Console help
- [ ] All directory stubs created (empty `.gitkeep` files in each)
- [ ] `phpunit.xml.dist` configured with test suite paths
- [ ] `phpstan.neon` configured at level 6 for the upgrader's own codebase
- [ ] `.php-cs-fixer.dist.php` or `phpcs.xml.dist` configured for PSR-12
- [ ] `vendor-patches/rector-laravel-fork/README.md` created (fork-ready mirror — TRD F-06)
- [ ] Composer scripts defined: `test`, `test:integration`, `phpstan`, `cs-check`

---

## Implementation Notes

- The `bin/upgrader` file should bootstrap Symfony Console Application with version info
- Use `readonly` classes where appropriate (PHP 8.2+)
- All value objects should be `final readonly class`
- Ensure `.gitignore` excludes `vendor/`, `composer.lock` for containers, IDE files
- The host `composer.lock` MUST be committed (TRD-BUILD-001)
