# P1-22: Hardening & E2E Validation

**Phase:** 1 — MVP (Week 22)  
**Priority:** Critical (Release Gate)  
**Estimated Effort:** 5-7 days  
**Dependencies:** ALL previous P1 tasks (this is the final validation)  
**Blocks:** Phase 2 start  

---

## Agent Persona

**Role:** PHP Quality Assurance Engineer  
**Agent File:** `agents/php-qa-engineer.agent.md`  
**Domain Knowledge Required:**
- Enterprise Laravel 8 codebase patterns and edge cases
- End-to-end testing across Docker containers
- WSL2 testing and path normalisation validation
- Performance profiling for PHP CLI applications
- Documentation writing for developer tools
- Security audit mindset (token redaction, file permissions, network isolation)

---

## Objective

Run the complete Phase 1 tool against 3+ real-world enterprise Laravel 8 repositories. Fix edge cases, validate all acceptance criteria from the PRD, ensure WSL2 compatibility, optimize performance, and write final documentation.

---

## Context from PRD

### Phase 1 Acceptance Criteria (PRD §13)

All must be verified on at least 3 distinct real-world Laravel 8 repositories:

1. ReactPHP dashboard serves SSE to multiple simultaneous browser connections without deadlock
2. Rector subprocess produces correct JSON diff output on real L8 codebase
3. Interrupted upgrade resumes correctly from checkpoint without re-applying completed rules
4. Static verification passes on enterprise repo with no .env and no database connection
5. All breaking changes from official L8→L9 upgrade guide auto-fixed or flagged
6. Lumen 8 app (including facades, Eloquent opt-in, inline config) migrates to L9 scaffold
7. HTML diff report renders fully offline (Diff2Html inline, no CDN)
8. Full test suite passes in CI with 100% custom Rector rules covered by fixture tests
9. Original repository unmodified if any verification step fails
10. L10→L11 and Livewire V2→V3 design spike documents committed

### Performance Requirements (TRD §26)

- 500-file L8 repo: complete in < 15 minutes (TRD-PERF-001)
- Dashboard reachable within 5 seconds (TRD-PERF-002)

### WSL2 Validation (F-09)

Path normalisation validated under WSL2. Docker bind mounts work correctly.

---

## E2E Test Plan

### Test Repository Matrix

| Repo Type | Key Patterns | Validates |
|---|---|---|
| Enterprise L8 (large, 300+ files) | Custom middleware, many models, facades, service providers | Full pipeline, performance |
| Enterprise L8 (no unit tests) | No PHPUnit, custom configs, .env variations | Static verification, confidence scoring |
| Lumen 8 application | withFacades, withEloquent, inline config, custom handler | Full Lumen migration path |

### Validation Checklist

- [ ] Full pipeline completes on each test repo
- [ ] Dashboard SSE works with 2+ browser tabs simultaneously
- [ ] Checkpoint resume works after simulated interruption
- [ ] Static verification passes without .env or database
- [ ] HTML report renders offline
- [ ] No modifications to original repos on verification failure
- [ ] Performance within specified bounds
- [ ] WSL2 path handling correct (if applicable)
- [ ] Token redaction verified in all logs
- [ ] Docker containers run with `--network=none`
- [ ] File permissions correct (workspace 0700, output 0755)

---

## Documentation to Finalize

| Document | Purpose |
|---|---|
| `README.md` | Install, configure, and run guide |
| `DEPENDENCY-AUDIT.md` | Pinned versions with review dates (TRD-BUILD-003) |
| `ARCHITECTURE.md` | System overview for developers |
| `CONTRIBUTING.md` | Development setup and contribution guide |

---

## Acceptance Criteria

- [ ] 3+ real-world L8 repos processed successfully end-to-end
- [ ] All 10 PRD acceptance criteria verified
- [ ] Performance targets met on a 500-file repository
- [ ] WSL2 compatibility validated
- [ ] Security requirements verified (token redaction, permissions, network isolation)
- [ ] Test suite passes in CI with 100% rule coverage
- [ ] Documentation complete and accurate
- [ ] Edge cases identified during E2E testing are fixed or documented
- [ ] Phase 1 declared pilot-ready

---

## Implementation Notes

- Use real open-source Laravel 8 applications for testing if enterprise repos unavailable
- Document any edge cases that emerge during E2E testing
- Performance profiling: identify bottlenecks (PHPStan is likely the slowest step)
- Consider creating a `known-issues.md` for documented limitations
- WSL2 testing requires actual Windows+WSL2 environment
- This is the final gate — be thorough
