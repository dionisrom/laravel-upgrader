# Laravel Enterprise Upgrader — Master Task Index

> **Last Updated:** 2026-03-22 (Phase 1 complete)  
> **Total Tasks:** 39 (Phase 1: 22 · Phase 2: 9 · Phase 3: 8)  
> **Status Legend:** 🔲 Not Started · 🔄 In Progress · ✅ Completed · 🚫 Blocked

---

## Phase 1 — L8→L9 Single Hop + Lumen Migration (22 weeks)

| # | Task | Agent | Effort | Dependencies | Status | Notes |
|---|---|---|---|---|---|---|
| P1-01 | [Project Scaffold & Composer Setup](phase1/P1-01-project-scaffold.md) | PHP Orchestrator Engineer | 3-4d | None | ✅ | Foundation — unblocks everything |
| P1-02 | [Repository Fetcher](phase1/P1-02-repository-fetcher.md) | PHP Orchestrator Engineer | 3-4d | P1-01 | ✅ | All 3 fetchers + token masking + lock tests; GitLabRepositoryFetcherTest added |
| P1-03 | [Workspace Manager](phase1/P1-03-workspace-manager.md) | PHP Orchestrator Engineer | 3-4d | P1-01 | ✅ | Content-addressed dirs + advisory locks |
| P1-04 | [Detection & Inventory Scanner](phase1/P1-04-detection-inventory.md) | Rector/AST Engineer | 5-7d | P1-01 | ✅ | Laravel version + package detection |
| P1-05 | [Breaking Change Registry](phase1/P1-05-breaking-change-registry.md) | Laravel Migration Specialist | 4-5d | P1-01 | ✅ | L8→L9 breaking changes JSON |
| P1-06 | [Rector Runner & Config Builder](phase1/P1-06-rector-runner.md) | Rector/AST Engineer | 5-7d | P1-01 | ✅ | Subprocess Rector invocation |
| P1-07 | [Custom Rector Rules L8→L9](phase1/P1-07-custom-rector-rules.md) | Rector/AST Engineer | 8-10d | P1-06 | ✅ | 4 custom rules + upstream gap analysis; 8 fixture tests; breaking-changes.json updated |
| P1-08 | [Workspace Manager & Diff Generator](phase1/P1-08-workspace-manager.md) | PHP Orchestrator Engineer | 4-5d | P1-03 | ✅ | flock advisory lock + ConcurrentUpgradeException added to WorkspaceManager |
| P1-09 | [Docker Image hop-8-to-9](phase1/P1-09-docker-image.md) | Docker/DevOps Engineer | 5-7d | P1-06, P1-07 | ✅ | Multi-stage PHP 8.1 Alpine; hop-8-to-9 + lumen-migrator images; docker-bake.hcl; JSON-ND entrypoints; diff2html bundled |
| P1-10 | [Orchestrator, HopPlanner & DockerRunner](phase1/P1-10-orchestrator.md) | PHP Orchestrator Engineer | 6-8d | P1-09, P1-04 | ✅ | HopPlanner + DockerRunner + EventStreamer + TerminalRenderer + AuditLogWriter; 22 tests pass; PHPStan level 8 clean |
| P1-11 | [Event Streaming & JSON-ND](phase1/P1-11-event-streaming.md) | PHP Orchestrator Engineer | 4-5d | P1-10 | ✅ | 15-type event catalogue; typed VOs; EventParser; container-side EventEmitter; 35 tests pass; PHPStan level 8 clean |
| P1-12 | [Composer Dependency Upgrader](phase1/P1-12-composer-upgrader.md) | PHP Orchestrator Engineer | 5-7d | P1-04 | ✅ | composer.json rewrite + install; 50-pkg compat matrix |
| P1-13 | [Config & Env Migrator](phase1/P1-13-config-migrator.md) | Laravel Migration Specialist | 6-8d | P1-05 | ✅ | Atomic config migration; 5 L8→L9 migrations |
| P1-14 | [Lumen Migration Suite](phase1/P1-14-lumen-migration.md) | Laravel Migration Specialist | 10-14d | P1-04, P1-13 | ✅ | 10 modules + 11 VOs/exceptions; 5 manual-review categories flagged; PHPStan clean |
| P1-15 | [Verification Pipeline](phase1/P1-15-verification-pipeline.md) | PHP QA Engineer | 5-7d | P1-09 | ✅ | VerificationPipeline + 5 verifiers (Syntax/Composer/ClassResolution/PhpStan/StaticArtisan); 34 tests, 91 assertions; PHPStan level 8 clean |
| P1-16 | [State & Checkpoint System](phase1/P1-16-checkpoint-system.md) | PHP Orchestrator Engineer | 5-7d | P1-10 | ✅ | Atomic checkpoint write (tmp→rename); FileHasher sha256; WorkspaceReconciler; implements CheckpointManagerInterface; 20 tests pass; PHPStan level 8 clean |
| P1-17 | [ReactPHP Dashboard Server](phase1/P1-17-dashboard-server.md) | ReactPHP Engineer | 6-8d | P1-11 | ✅ | ReactPHP SSE server; EventBus implements EventConsumerInterface; self-contained SPA (7 panels, Tailwind CDN, exp backoff); 8 tests pass; PHPStan level 8 clean |
| P1-18 | [Report Generator](phase1/P1-18-report-generator.md) | Report & Documentation Engineer | 5-7d | P1-08, P1-11 | ✅ | ReportData + ConfidenceScorer + ReportBuilder + 3 formatters; 27 tests, 63 assertions; PHPStan level 8 clean |
| P1-19 | [CLI Commands](phase1/P1-19-cli-commands.md) | PHP Orchestrator Engineer | 4-5d | P1-10, P1-17 | ✅ | RunCommand + AnalyseCommand + DashboardCommand + VersionCommand + TokenRedactor + InputValidator; 16 tests, 21 assertions; PHPStan level 8 clean |
| P1-20 | [Test Suite](phase1/P1-20-test-suite.md) | PHP QA Engineer | 6-8d | P1-01 through P1-19 | ✅ | 4 Rector rule fixture tests (8 tests) + FullHopTest + LumenMigrationTest (Docker @group integration) + 3 fixture apps; 249 unit tests pass; PHPStan level 8 clean |
| P1-21 | [Design Spikes L10→L11 + Livewire](phase1/P1-21-design-spikes.md) | Laravel Migration Specialist | 5-7d | P1-14 | ✅ | docs/spikes/ committed; gates Phase 2 |
| P1-22 | [Hardening & E2E Validation](phase1/P1-22-hardening.md) | PHP QA Engineer | 5-7d | P1-20 | ✅ | README + ARCHITECTURE + CONTRIBUTING + known-issues; validate-e2e.sh; HardeningTest + SecurityAuditTest (33 tests, 90 assertions); laravel-8-complex fixture complete; PHPStan level 8 clean |

---

## Phase 2 — L9→L13 + Multi-Hop + Packages (22 weeks)

> **Entry Criteria:** Phase 1 MVP validated on ≥3 enterprise repos, verification ≥95%, design spikes committed

| # | Task | Agent | Effort | Dependencies | Status | Notes |
|---|---|---|---|---|---|---|
| P2-01 | [L9→L10 Hop Container & Rules](phase2/P2-01-hop-9-to-10.md) | Rector/AST Engineer | 6-8d | Phase 1 Docker pattern | 🔲 | PHP 8.1 base |
| P2-02 | [L10→L11 Slim Skeleton Migration](phase2/P2-02-slim-skeleton.md) | Laravel Migration Specialist | 18-22d | P1-21 (design spike) | 🔲 | Most complex P2 task |
| P2-03 | [L11→L12 Hop Container & Rules](phase2/P2-03-hop-11-to-12.md) | Rector/AST Engineer | 6-8d | Phase 1 Docker pattern | 🔲 | Route binding + once() |
| P2-04 | [L12→L13 Hop Container & Rules](phase2/P2-04-hop-12-to-13.md) | Rector/AST Engineer | 6-8d | Phase 1 Docker pattern | 🔲 | PHP 8.3 minimum guard |
| P2-05 | [Multi-Hop Orchestration](phase2/P2-05-multi-hop-orchestration.md) | PHP Orchestrator Engineer | 10-12d | P2-01–P2-04, P1-10, P1-16 | 🔲 | Chain L8→L13 |
| P2-06 | [Package Rule Sets](phase2/P2-06-package-rule-sets.md) | Rector/AST Engineer | 12-15d | P2-01, P1-06, P1-04 | 🔲 | Spatie, Livewire, Sanctum, etc. |
| P2-07 | [CI/CD Integration Templates](phase2/P2-07-ci-cd-templates.md) | Docker/DevOps Engineer | 5-6d | P2-05, P1-19 | 🔲 | GH Actions, GitLab, Bitbucket |
| P2-08 | [HTML Diff Viewer v2](phase2/P2-08-diff-viewer-v2.md) | Report & Documentation Engineer | 8-10d | P1-18, P2-05 | 🔲 | File tree, filters, PDF |
| P2-09 | [Phase 2 Hardening & E2E](phase2/P2-09-phase2-hardening.md) | PHP QA Engineer | 5-7d | P2-01–P2-08 | 🔲 | E2E on 5 fixtures |

---

## Phase 3 — PHP Version Upgrades + 2D Planner (14 weeks)

> **Entry Criteria:** Phase 2 stable across all 5 hops, ≥10 enterprise repos, multi-hop resume proven

| # | Task | Agent | Effort | Dependencies | Status | Notes |
|---|---|---|---|---|---|---|
| P3-01 | [2D HopPlanner](phase3/P3-01-2d-hop-planner.md) | PHP Orchestrator Engineer | 10-12d | P2-05, P1-10 | 🔲 | PHP + Laravel interleaving |
| P3-02 | [PHP 8.0→8.1 & 8.1→8.2 Hops](phase3/P3-02-php-hop-80-82.md) | Docker/DevOps Engineer | 8-10d | P3-01 | 🔲 | First 2 PHP hops |
| P3-03 | [PHP 8.2→8.3 & 8.3→8.4 Hops](phase3/P3-03-php-hop-82-84.md) | Docker/DevOps Engineer | 8-10d | P3-02 | 🔲 | Property hooks (8.4) |
| P3-04 | [PHP 8.4→8.5 Beta Hop](phase3/P3-04-php-hop-84-85-beta.md) | Docker/DevOps Engineer | 5-6d | P3-03 | 🔲 | BETA with ack flow |
| P3-05 | [Extension Compatibility Checker](phase3/P3-05-extension-checker.md) | PHP Orchestrator Engineer | 8-10d | P3-02, P1-04 | 🔲 | PECL blocker detection |
| P3-06 | [Silent Change Scanner](phase3/P3-06-silent-change-scanner.md) | Rector/AST Engineer | 8-10d | P3-02, P1-15 | 🔲 | Runtime behavior changes |
| P3-07 | [Dashboard 2D Timeline](phase3/P3-07-dashboard-2d-timeline.md) | ReactPHP Engineer | 8-10d | P3-01, P1-17, P3-05, P3-06 | 🔲 | Two-row timeline + connectors |
| P3-08 | [Phase 3 Hardening & Combined Testing](phase3/P3-08-phase3-hardening.md) | PHP QA Engineer | 10-12d | P3-01–P3-07 | 🔲 | 8-hop combined chain E2E |

---

## Agent Roster

| Agent | File | Primary Tasks |
|---|---|---|
| PHP Orchestrator Engineer | [agents/php-orchestrator-engineer.agent.md](../agents/php-orchestrator-engineer.agent.md) | P1-01–03, P1-08, P1-10–12, P1-16, P1-19, P2-05, P3-01, P3-05 |
| Rector/AST Transformation Engineer | [agents/rector-ast-engineer.agent.md](../agents/rector-ast-engineer.agent.md) | P1-04, P1-06–07, P2-01, P2-03–04, P2-06, P3-06 |
| Laravel Migration Specialist | [agents/laravel-migration-specialist.agent.md](../agents/laravel-migration-specialist.agent.md) | P1-05, P1-13–14, P1-21, P2-02 |
| Docker/DevOps Engineer | [agents/docker-devops-engineer.agent.md](../agents/docker-devops-engineer.agent.md) | P1-09, P2-07, P3-02–04 |
| PHP Quality Assurance Engineer | [agents/php-qa-engineer.agent.md](../agents/php-qa-engineer.agent.md) | P1-15, P1-20, P1-22, P2-09, P3-08 |
| ReactPHP/Real-time Systems Engineer | [agents/reactphp-dashboard-engineer.agent.md](../agents/reactphp-dashboard-engineer.agent.md) | P1-11, P1-17, P3-07 |
| Report & Documentation Engineer | [agents/report-documentation-engineer.agent.md](../agents/report-documentation-engineer.agent.md) | P1-18, P2-08 |

---

## Dependency Graph — Critical Path

```
P1-01 (Scaffold)
 ├──→ P1-02 (Fetcher)
 ├──→ P1-03 → P1-08 (Workspace/Diff)
 ├──→ P1-04 (Detection) ──→ P1-10 (Orchestrator) ──→ P1-11 → P1-17 (Dashboard)
 │                      ──→ P1-12 (Composer)
 ├──→ P1-05 (Registry) ──→ P1-13 (Config) ──→ P1-14 (Lumen) ──→ P1-21 (Spikes)
 ├──→ P1-06 (Rector) ──→ P1-07 (Rules) ──→ P1-09 (Docker) ──→ P1-10
 │                                                            ──→ P1-15 (Verification)
 └──→ P1-16 (Checkpoint) ← P1-10
      P1-18 (Report) ← P1-08, P1-11
      P1-19 (CLI) ← P1-10, P1-17
      P1-20 (Tests) ← all above
      P1-22 (Hardening) ← P1-20

Phase 2:
 P2-01..04 (Hop Containers) ← Phase 1 Docker pattern
 P2-02 (Slim Skeleton) ← P1-21 (mandatory)
 P2-05 (Multi-Hop) ← P2-01..04, P1-10, P1-16
 P2-06 (Packages) ← P2-01, P1-06, P1-04
 P2-07 (CI/CD) ← P2-05, P1-19
 P2-08 (Viewer v2) ← P1-18, P2-05
 P2-09 (Hardening) ← P2-01..08

Phase 3:
 P3-01 (2D Planner) ← P2-05, P1-10
 P3-02..04 (PHP Hops) ← P3-01
 P3-05 (Extension) ← P3-02, P1-04
 P3-06 (Silent Scanner) ← P3-02, P1-15
 P3-07 (2D Dashboard) ← P3-01, P1-17, P3-05, P3-06
 P3-08 (Hardening) ← P3-01..07
```

---

## Parallelization Opportunities

### Phase 1 — After P1-01 completes, these can run in parallel:
- **Track A:** P1-02, P1-03, P1-04, P1-05, P1-06 (all depend only on P1-01)
- **Track B:** P1-12 (after P1-04), P1-13 (after P1-05) — can overlap with Track A late items
- **Track C:** P1-07 (after P1-06), then P1-09 (after P1-07)

### Phase 2 — Hop containers can be built in parallel:
- **Parallel:** P2-01, P2-03, P2-04 (independent hop containers)
- **Sequential:** P2-02 (needs P1-21 spike), P2-05 (needs all containers)
- **Parallel with P2-05:** P2-06, P2-07, P2-08

### Phase 3 — After P3-01:
- **Parallel:** P3-02/P3-03/P3-04 (PHP hop containers), P3-05, P3-06
- **Sequential:** P3-07 (needs P3-05, P3-06), P3-08 (needs everything)
