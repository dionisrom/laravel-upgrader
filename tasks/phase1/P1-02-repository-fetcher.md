# P1-02: Repository Fetcher Layer

**Phase:** 1 — MVP  
**Priority:** Must Have  
**Estimated Effort:** 4-5 days  
**Dependencies:** P1-01 (Project Scaffold)  
**Blocks:** P1-19 (CLI Commands)  

---

## Agent Persona

**Role:** PHP Orchestrator Engineer  
**Agent File:** `agents/php-orchestrator-engineer.agent.md`

---

## Objective

Implement the repository fetching layer that accepts local paths, GitHub URLs, and GitLab URLs. Includes token authentication, shallow cloning, timeout handling, and the advisory file lock mechanism for concurrent run safety.

---

## Context from PRD & TRD

### Interface (TRD §2.1)

```php
namespace App\Repository;

interface RepositoryFetcherInterface
{
    /**
     * Clones or copies the repository into $targetPath.
     * MUST acquire an advisory lock before copying.
     * MUST throw ConcurrentUpgradeException if lock unavailable.
     * MUST NOT log $token in any output or exception message.
     *
     * @throws RepositoryNotFoundException
     * @throws AuthenticationException
     * @throws ConcurrentUpgradeException
     */
    public function fetch(string $source, string $targetPath, ?string $token = null): FetchResult;
}
```

### Concrete Fetchers (TRD §2.2)

| Class | Source Pattern | Auth |
|---|---|---|
| `LocalRepositoryFetcher` | Absolute filesystem path | None |
| `GitHubRepositoryFetcher` | `github:org/repo` or `https://github.com/...` | PAT via `Authorization: token {PAT}` header |
| `GitLabRepositoryFetcher` | `gitlab:org/repo` or `https://gitlab.com/...` | PAT via `PRIVATE-TOKEN` header |

### FetchResult Value Object (TRD §2.3)

```php
final readonly class FetchResult
{
    public function __construct(
        public string $workspacePath,      // absolute path to cloned workspace
        public string $lockFilePath,       // path to held advisory lock file
        public string $defaultBranch,      // detected default branch
        public string $resolvedCommitSha,  // full SHA of cloned commit
    ) {}
}
```

### Key Technical Requirements

**TRD-REPO-001** — All git-based fetchers MUST use `git clone --depth=1 --single-branch`. Fetch MUST complete within 120 seconds or throw `FetchTimeoutException`.

**TRD-REPO-002** — The token MUST be passed to the git subprocess via the URL (masked) or `GIT_ASKPASS` helper. It MUST NOT appear in:
- Process arguments visible to `ps aux`
- Log output
- Exception messages
- The `audit.log.json`

**TRD-REPO-003** — Workspace ID MUST be computed as:
```php
$workspaceId = hash('sha256', $repoPath . $targetVersion . microtime(true));
$lockFile = sys_get_temp_dir() . '/upgrader/locks/' . hash('sha256', $repoPath) . '.lock';
```
Advisory lock MUST use `LOCK_EX | LOCK_NB`. On failure, throw `ConcurrentUpgradeException`.

### PRD Requirements Covered

| ID | Requirement |
|---|---|
| RF-01 | Accept local filesystem path as input |
| RF-02 | Clone from GitHub via HTTPS with PAT token |
| RF-03 | Clone from GitLab via HTTPS with PAT token |
| RF-04 | Shallow clone (`--depth=1`) for speed |
| RF-05 | Token never appears in logs, output, or Docker images |
| RF-06 | Advisory `flock()` lock prevents concurrent runs on same repo |
| RF-07 | Workspace ID = SHA-256(repoPath + targetVersion + microtime()) |
| RF-08 | Validate repo is accessible before starting; fail fast with clear error |

---

## Acceptance Criteria

- [ ] `RepositoryFetcherInterface` implemented
- [ ] `LocalRepositoryFetcher` copies from local path
- [ ] `GitHubRepositoryFetcher` clones via HTTPS with PAT (GIT_ASKPASS method)
- [ ] `GitLabRepositoryFetcher` clones via HTTPS with PAT
- [ ] `RepositoryFetcherFactory` resolves correct fetcher from source string pattern
- [ ] Shallow clone (`--depth=1 --single-branch`) used for all git operations
- [ ] 120-second timeout enforced; `FetchTimeoutException` thrown on timeout
- [ ] Advisory file lock (`LOCK_EX | LOCK_NB`) prevents concurrent runs
- [ ] `ConcurrentUpgradeException` thrown with helpful message when lock fails
- [ ] Token NEVER appears in any log, exception message, or process argument
- [ ] `FetchResult` value object returned with workspace path, lock file path, branch, SHA
- [ ] Unit tests for all three fetchers (mock git subprocess)
- [ ] Unit test confirms token is not leaked in any output

---

## Security Notes

- Use `GIT_ASKPASS` helper approach for token passing (TRD-REPO-002, TRD-SEC-001)
- Token must be redacted from all subprocess command logging
- `FetchResult` should not store the token
