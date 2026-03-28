# P2-07-F2: Eliminate template duplication between static files and generator

**Severity:** Medium  
**Source:** P2-07 post-review  
**Requirement:** Task implementation notes — "Templates are static YAML with variable placeholders"

## Problem

`CiTemplateGenerator` embeds full template copies as heredoc strings instead of loading from `templates/ci/{platform}/`. The static files and embedded copies already differ (comments, formatting, PR body text). Any future change to one will not propagate to the other.

## Required Changes

1. Add `{{PLACEHOLDER}}` markers to the static template files so they serve as the single source of truth.
2. Modify `CiTemplateGenerator::githubTemplate()`, `gitlabTemplate()`, `bitbucketTemplate()` to load and return `file_get_contents()` of the corresponding static template file.
3. Remove the embedded heredoc template strings.
4. The static files should use the same placeholder tokens (`{{FROM_VERSION}}`, `{{TO_VERSION}}`, `{{DEFAULT_MODE}}`, `{{IMAGE}}`) that the generator substitutes.

## Acceptance Criteria

- [ ] Generator reads templates from `templates/ci/` filesystem path
- [ ] No heredoc template strings remain in `CiTemplateGenerator.php`
- [ ] All existing tests continue to pass
- [ ] Static files use `{{PLACEHOLDER}}` tokens
