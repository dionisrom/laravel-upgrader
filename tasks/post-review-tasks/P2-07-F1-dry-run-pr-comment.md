# P2-07-F1: Add PR comment step to dry-run mode templates

**Severity:** Medium  
**Source:** P2-07 post-review  
**Requirement:** PRD §7 — "Dry-run mode: Run upgrader, generate diff report, post as PR comment — no commits"

## Problem

All three CI templates (GitHub Actions, GitLab CI, Bitbucket Pipelines) only upload the upgrade report as an artifact in dry-run mode. None post a diff summary as a PR/MR comment.

## Required Changes

1. **GitHub Actions**: Add a step after artifact upload that uses `gh pr comment` to post a summary when the workflow was triggered from a PR context, or when `github.event.pull_request` is available.
2. **GitLab CI**: Add a `curl` call to the GitLab MR notes API in the dry-run job's script.
3. **Bitbucket Pipelines**: Add a `curl` call to the Bitbucket PR comments API in the dry-run pipeline.
4. Update the embedded templates in `CiTemplateGenerator.php` to match.
5. Add tests asserting that dry-run templates contain a PR comment step/API call.

## Acceptance Criteria

- [ ] GitHub template contains a `gh pr comment` or API call step in dry-run mode
- [ ] GitLab template contains a MR note API call in dry-run mode
- [ ] Bitbucket template contains a PR comment API call in dry-run mode
- [ ] Tests assert presence of PR comment logic per platform
