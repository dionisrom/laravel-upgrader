# PR-13: Align Lumen Migrator PHP Runtime With Target Repository Constraints

**Phase:** Post-Review  
**Priority:** High  
**Estimated Effort:** 0.5-1 day  
**Dependencies:** None

---

## Objective

Make the dedicated Lumen migration image PHP-aware so it can run against repositories whose Composer platform constraint is newer than PHP 8.1, including the marketplace-gateway Lumen 8 repo that requires `php:^8.3`.

## Source Finding

Senior Staff Lumen-path audit found that [docker/lumen-migrator/Dockerfile](c:/dev/laravel-upgrader/docker/lumen-migrator/Dockerfile) is hard-coded to `php:8.1-cli-alpine`, while the target repository at `C:/dev/marketplace/marketplace-gateway` requires `php:^8.3` in Composer.

## Evidence

- [docker/lumen-migrator/Dockerfile](c:/dev/laravel-upgrader/docker/lumen-migrator/Dockerfile) uses `FROM php:8.1-cli-alpine AS base`
- `C:/dev/marketplace/marketplace-gateway/composer.json` requires `"php": "^8.3"`
- Existing Lumen integration coverage only exercises the sample fixture on PHP `^8.0`, so this mismatch is not currently caught by tests

## Acceptance Criteria

- [ ] The Lumen migrator Dockerfile accepts a configurable PHP base image
- [ ] [docker-bake.hcl](c:/dev/laravel-upgrader/docker-bake.hcl) builds PHP-tagged Lumen images, including `upgrader/lumen-migrator:php8.3`
- [ ] Host-side Lumen routing can resolve a PHP-compatible Lumen image using the same platform detection approach already used for Laravel hops
- [ ] Regression coverage proves the marketplace-gateway PHP `^8.3` constraint no longer routes to a PHP 8.1-only image