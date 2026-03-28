# PR-04: P1-02 Remote Fetcher Auth and Lock Compliance

**Phase:** Post-Review  
**Priority:** Critical  
**Estimated Effort:** 1-2 days  
**Dependencies:** P1-02 (Repository Fetcher Layer)  
**Blocks:** Re-acceptance of P1-02  

---

## Agent Persona

**Role:** PHP Orchestrator Engineer  
**Agent File:** `agents/php-orchestrator-engineer.agent.md`  
**Domain Knowledge Required:**
- Symfony Process subprocess handling
- Git HTTPS authentication flows
- Advisory file locking in PHP
- Laravel Upgrader repository-fetch lifecycle

---

## Objective

Bring the remote repository fetchers into compliance with the P1-02 task contract and TRD repository requirements, especially around PAT handling and concurrent-run locking semantics.

---

## Context from Review

### Source Findings

- Remote fetchers currently embed the PAT directly into the clone URL instead of using the required GIT_ASKPASS flow from the P1-02 task acceptance criteria.
- Remote fetchers discard the acquired lock handle before clone execution, which weakens the intended advisory lock protection during fetch.
- The concurrent-lock exception message does not match the TRD-required wording.

### Requirement Links

- P1-02 acceptance criteria: GitHub/GitLab fetchers clone via HTTPS with PAT using GIT_ASKPASS
- TRD-REPO-002: token must not leak via process arguments, logs, or exception messages
- TRD-REPO-003: advisory lock must use `LOCK_EX | LOCK_NB` and throw the specified message on failure

---

## Files Likely Touched

| File | Why |
|---|---|
| `src/Repository/RemoteGitFetcherTrait.php` | Shared remote auth, clone, timeout, and lock behavior |
| `src/Repository/GitHubRepositoryFetcher.php` | GitHub-specific auth username and remote flow |
| `src/Repository/GitLabRepositoryFetcher.php` | GitLab-specific auth username and remote flow |
| `src/Repository/LocalRepositoryFetcher.php` | Exact concurrent lock message alignment |

---

## Acceptance Criteria

- [ ] Remote PAT flow uses `GIT_ASKPASS` instead of embedding the token in the clone URL
- [ ] Remote clone command arguments do not contain the PAT
- [ ] Advisory lock handle remains alive through the fetch operation for remote fetchers
- [ ] `ConcurrentUpgradeException` uses the TRD-required message
- [ ] Existing token masking behavior remains intact in exception messages
- [ ] Git clone still uses `--depth=1 --single-branch` with 120-second timeout

---

## Implementation Notes

- Fix the root cause in the shared remote trait rather than patching individual tests
- Keep provider-specific details limited to GitHub/GitLab username or prompt conventions
- Prefer a testable subprocess-construction design so PR-05 can assert the contract directly