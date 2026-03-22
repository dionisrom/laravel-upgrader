# P2-09: Phase 2 Hardening & E2E Validation

**Phase:** 2  
**Priority:** Must Have  
**Estimated Effort:** 5-7 days  
**Dependencies:** P2-01 through P2-08 (all Phase 2 tasks)  
**Blocks:** Phase 3  

---

## Agent Persona

**Role:** PHP Quality Assurance Engineer  
**Agent File:** `agents/php-qa-engineer.agent.md`  
**Domain Knowledge Required:**
- End-to-end testing of Docker-based upgrade pipelines
- Multi-hop chain testing strategies
- Real-world Laravel application patterns (monolith, modular, API-only)
- PHPStan level 6+ analysis
- Performance profiling and memory analysis for long-running chains

---

## Objective

Run full end-to-end validation of the complete L8→L13 upgrade chain across 5 diverse real-world-style fixture repositories. Fix edge cases, improve error messages, validate checkpoint/resume across hops, and ensure the unified report is accurate.

---

## Context from PRD & TRD

### E2E Validation Matrix (PRD §9)

Test the full chain against 5 fixture repositories:

| Fixture | Type | Packages | Complexity |
|---|---|---|---|
| `fixture-monolith` | Traditional monolith | Spatie permissions, Horizon | High (100+ models) |
| `fixture-api` | API-only (no views) | Sanctum, Passport | Medium |
| `fixture-livewire` | Livewire SPA | Livewire, Alpine.js | High (V2→V3 migration) |
| `fixture-modular` | Domain-driven modules | Custom service providers | High (non-standard structure) |
| `fixture-minimal` | Minimal Laravel app | None | Low (baseline) |

### Validation Criteria

For each fixture:
1. Full chain L8→L13 completes without fatal errors
2. PHPStan level 6 passes on final output
3. All automated tests in the fixture still pass
4. Checkpoint/resume works at each hop boundary
5. HTML diff report accurately reflects all changes
6. Package rules activated correctly where applicable

### Performance Targets (PRD §9)

- Single hop: < 5 minutes for fixture-monolith
- Full chain (5 hops): < 25 minutes for fixture-monolith
- Memory usage: < 512MB per hop container

---

## Files to Create

| File | Location | Purpose |
|---|---|---|
| `E2EChainTest.php` | `tests/E2E/` | Full chain E2E test orchestrator |
| `FixtureMonolithTest.php` | `tests/E2E/Fixtures/` | Monolith fixture validation |
| `FixtureApiTest.php` | `tests/E2E/Fixtures/` | API fixture validation |
| `FixtureLivewireTest.php` | `tests/E2E/Fixtures/` | Livewire fixture validation |
| `FixtureModularTest.php` | `tests/E2E/Fixtures/` | Modular fixture validation |
| `FixtureMinimalTest.php` | `tests/E2E/Fixtures/` | Minimal fixture validation |
| `ChainResumeE2ETest.php` | `tests/E2E/` | Resume at hop boundaries |
| `PerformanceBenchmark.php` | `tests/E2E/` | Timing and memory benchmarks |
| Fixture repos | `tests/fixtures/` | 5 fixture Laravel 8 apps |

---

## Acceptance Criteria

- [ ] All 5 fixture repos complete L8→L13 chain successfully
- [ ] PHPStan level 6 passes on all final outputs
- [ ] Checkpoint/resume tested at each hop boundary for each fixture
- [ ] Package rules fire correctly (e.g., Livewire rules only for fixture-livewire)
- [ ] HTML diff report generated for each fixture with accurate content
- [ ] Performance targets met (< 5min single hop, < 25min full chain)
- [ ] Memory stays under 512MB per hop container
- [ ] All edge cases found are fixed or documented with workarounds
- [ ] CI pipeline runs full E2E suite (may be a separate "slow" CI job)

---

## Implementation Notes

- Fixture repos should be minimal but representative (don't need 100 real models — use generated stubs)
- The `fixture-livewire` repo is critical for validating Livewire V2→V3 package rules
- Consider: parallel fixture testing (each fixture is independent)
- Document all edge cases found in a `KNOWN_ISSUES.md`
- This task is the quality gate for Phase 2 — block Phase 3 start until green
