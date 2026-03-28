# P2-07-F3: Add test validating static template files

**Severity:** Low  
**Source:** P2-07 post-review  
**Requirement:** Acceptance criteria — templates should be tested

## Problem

The static YAML files under `templates/ci/` are never tested or validated. They could break without detection.

## Required Changes

1. After F2 is resolved (generator reads static files), add a test that confirms each static template file exists and contains expected platform-specific markers (e.g. `workflow_dispatch` for GitHub, `stages:` for GitLab, `pipelines:` for Bitbucket).
2. Add a test that renders each template and asserts no `{{...}}` placeholders remain.

## Acceptance Criteria

- [ ] Test asserts each static template file exists
- [ ] Test asserts rendered output has no unsubstituted placeholders
