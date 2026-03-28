# PR-15: Add Lumen Composer Manifest Migration And Offline Private Dependency Support

**Phase:** Post-Review  
**Priority:** High  
**Estimated Effort:** 1-2 days  
**Dependencies:** PR-13, PR-14

---

## Objective

Make the dedicated Lumen path preserve the source repository's Composer dependencies and private VCS repositories while remaining compatible with runtime `--network=none` execution.

## Source Finding

Senior Staff Lumen-path audit found that the current Lumen migration path has no Composer-manifest migration step. It creates or assumes a Laravel scaffold, but it does not merge the source repo's Composer requirements, repositories, or private package constraints into the target Laravel app before verification.

## Evidence

- `C:/dev/marketplace/marketplace-gateway/composer.json` contains private `git` and `vcs` repositories plus application dependencies that are not present in a stock Laravel scaffold
- The current Lumen entrypoint does not invoke `DependencyUpgrader`, a Composer merger, or any Lumen-specific Composer migration step
- Runtime containers execute under `--network=none`, so private dependencies require the same cache-prestage strategy already introduced for Laravel hops

## Acceptance Criteria

- [ ] The Lumen pipeline migrates Composer metadata from the source repo into the scaffolded Laravel target, removing `laravel/lumen-framework` and keeping non-framework dependencies and repository definitions
- [ ] The transformed target can use `UPGRADER_EXTRA_COMPOSER_CACHE_DIR` for offline dependency resolution inside the isolated runtime container
- [ ] Host orchestration primes the Composer cache for Lumen repos before the isolated Lumen migration run when private repositories are present
- [ ] Regression coverage proves private repository definitions and non-framework package requirements survive the Lumen migration path