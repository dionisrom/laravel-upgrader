# PR-05: P1-02 Fetcher Test Contract Alignment

**Phase:** Post-Review  
**Priority:** High  
**Estimated Effort:** 1-2 days  
**Dependencies:** PR-04 (P1-02 Remote Fetcher Auth and Lock Compliance)  
**Blocks:** Confidence in P1-02 remaining green  

---

## Agent Persona

**Role:** PHP Quality Assurance Engineer  
**Agent File:** `agents/php-qa-engineer.agent.md`  
**Domain Knowledge Required:**
- PHPUnit mocking and deterministic subprocess testing
- Symfony Process test doubles
- Security regression testing for token handling
- Windows-safe unit test design

---

## Objective

Refactor the P1-02 test suite so it verifies the required repository-fetch subprocess contract directly and deterministically, without depending on real network failures or asserting the old insecure token-in-URL design.

---

## Context from Review

### Source Findings

- The current GitHub/GitLab tests assert token embedding in the clone URL, which is the opposite of the required secure design.
- The current remote tests depend on real clone failures against nonexistent remote repositories, which is weak for unit-test coverage.
- The test suite does not directly prove the required clone flags, askpass env usage, or exact concurrent-lock message.

### Requirement Links

- P1-02 acceptance criteria for mocked subprocess testing and token non-leakage
- TRD-REPO-001 for clone flags and timeout
- TRD-REPO-002 for auth transport and secret handling
- TRD-REPO-003 for lock failure behavior

---

## Files Likely Touched

| File | Why |
|---|---|
| `tests/Unit/Repository/GitHubRepositoryFetcherTest.php` | Update contract assertions to secure auth flow |
| `tests/Unit/Repository/GitLabRepositoryFetcherTest.php` | Update contract assertions to secure auth flow |
| `tests/Unit/Repository/LocalRepositoryFetcherTest.php` | Tighten lock-message assertions if needed |
| `src/Repository/*` | Small seams for deterministic subprocess mocks if required |

---

## Acceptance Criteria

- [ ] Remote fetcher unit tests do not depend on real network failures
- [ ] Tests assert PAT is not present in clone command arguments
- [ ] Tests assert `GIT_ASKPASS`-based env wiring when a token is provided
- [ ] Tests assert `--depth=1 --single-branch` for remote clone commands
- [ ] Tests assert timeout and lock-failure behavior in deterministic unit tests
- [ ] Tests no longer encode the insecure token-in-URL design as expected behavior

---

## Implementation Notes

- Prefer small seams for process creation over large architectural rewrites
- Tests should verify observable subprocess construction and error handling, not internal trivia
- Keep the remote tests fast and Windows-compatible