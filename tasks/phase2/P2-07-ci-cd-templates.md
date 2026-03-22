# P2-07: CI/CD Integration Templates

**Phase:** 2  
**Priority:** Nice to Have  
**Estimated Effort:** 5-6 days  
**Dependencies:** P2-05 (Multi-Hop Orchestration), P1-19 (CLI Commands)  
**Blocks:** None  

---

## Agent Persona

**Role:** Docker/DevOps Engineer  
**Agent File:** `agents/docker-devops-engineer.agent.md`  
**Domain Knowledge Required:**
- GitHub Actions workflow YAML syntax
- GitLab CI `.gitlab-ci.yml` syntax (stages, services, artifacts)
- Bitbucket Pipelines `bitbucket-pipelines.yml` syntax
- Docker-in-Docker (DinD) patterns for CI environments
- TRD §19: CI/CD Integration Architecture

---

## Objective

Create ready-to-use CI/CD pipeline templates that teams can drop into their repositories to run the Laravel Upgrader as part of their CI pipeline. Templates for GitHub Actions, GitLab CI, and Bitbucket Pipelines.

---

## Context from PRD & TRD

### CI/CD Templates (PRD §7)

Templates should support two modes:
1. **Dry-run mode**: Run upgrader, generate diff report, post as PR comment — no commits
2. **Auto-upgrade mode**: Run upgrader, commit changes to a new branch, open PR

### Template Requirements (TRD §19)

```yaml
# GitHub Actions example structure
name: Laravel Upgrade
on:
  workflow_dispatch:
    inputs:
      from_version: { description: 'Current Laravel version', required: true }
      to_version: { description: 'Target Laravel version', required: true }
      mode: { description: 'dry-run or auto-upgrade', default: 'dry-run' }

jobs:
  upgrade:
    runs-on: ubuntu-latest
    services:
      docker:
        image: docker:dind
    steps:
      - uses: actions/checkout@v4
      - name: Run Laravel Upgrader
        run: |
          docker run --rm -v ${{ github.workspace }}:/app \
            upgrader:latest upgrade --from=${{ inputs.from_version }} --to=${{ inputs.to_version }}
      - name: Upload Report
        uses: actions/upload-artifact@v4
        with:
          name: upgrade-report
          path: .upgrader/reports/
```

### Key Features

- **Workspace mounting**: Mount repo as volume into upgrader container
- **Artifact preservation**: Upload HTML reports as CI artifacts
- **PR comment**: In dry-run mode, post diff summary as PR comment
- **Branch creation**: In auto-upgrade mode, create branch and commit changes

---

## Files to Create

| File | Location | Purpose |
|---|---|---|
| `github-actions.yml` | `templates/ci/github/` | GitHub Actions workflow |
| `gitlab-ci.yml` | `templates/ci/gitlab/` | GitLab CI pipeline |
| `bitbucket-pipelines.yml` | `templates/ci/bitbucket/` | Bitbucket pipeline |
| `CiTemplateGenerator.php` | `src/Ci/` | CLI command to generate configured template |
| `README.md` | `templates/ci/` | Usage documentation |
| `CiTemplateGeneratorTest.php` | `tests/Unit/Ci/` | Generator tests |

---

## Acceptance Criteria

- [ ] GitHub Actions template works with `workflow_dispatch`
- [ ] GitLab CI template uses stages and services correctly
- [ ] Bitbucket Pipelines template handles Docker-in-Docker
- [ ] All templates support dry-run and auto-upgrade modes
- [ ] Report artifacts uploaded in all three platforms
- [ ] `CiTemplateGenerator` CLI command outputs configured template for selected platform
- [ ] Templates include proper caching (composer cache, Docker layer cache)
- [ ] README documents setup for each platform

---

## Implementation Notes

- Templates are static YAML with variable placeholders — keep them simple
- The generator command interpolates project-specific values (repo URL, versions)
- Consider: template validation via schema (GitHub Actions has a schema)
- DinD considerations: GitLab needs `services: [docker:dind]`, GitHub uses the runner's Docker
