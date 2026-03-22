# P3-08: Phase 3 Hardening & Combined Mode Testing

**Phase:** 3  
**Priority:** Must Have  
**Estimated Effort:** 10-12 days  
**Dependencies:** P3-01 through P3-07 (all Phase 3 tasks)  
**Blocks:** Enterprise pilot  

---

## Agent Persona

**Role:** PHP Quality Assurance Engineer  
**Agent File:** `agents/php-qa-engineer.agent.md`  
**Domain Knowledge Required:**
- End-to-end testing of combined Laravel+PHP upgrade chains (8+ hops)
- PHP version-specific runtime behavior validation
- Extension compatibility edge cases
- Performance profiling for long-running chains
- Real enterprise repository testing patterns

---

## Objective

Run full end-to-end validation of the combined L8+PHP8.0→L13+PHP8.3 upgrade path (8 hops) across 3 real enterprise-style repositories. Validate PHP-only mode, extension checker, silent change scanner, 2D dashboard, and resume across mixed hop types.

---

## Context from PRD & TRD

### Combined Mode E2E (PRD §16, W12-13)

Test the full combined upgrade on 3 enterprise repos:

| Fixture | Laravel Start | PHP Start | Target | Hops | Special |
|---|---|---|---|---|---|
| `fixture-enterprise-full` | L8 | PHP 8.0 | L13+PHP 8.3 | 8 | Full chain, PECL extensions |
| `fixture-api-modern` | L10 | PHP 8.1 | L13+PHP 8.4 | 5 | Mid-chain start, Sanctum |
| `fixture-php-only` | — | PHP 8.0 | PHP 8.4 | 4 | PHP-only (no Laravel hops) |

### Phase 3 Acceptance Criteria (PRD §17.2)

1. PHP-only upgrade (8.1→8.4) completes and verifies correctly on non-Laravel codebase
2. Combined L8+PHP8.0→L13+PHP8.3 produces the correct 8-hop interleaved sequence
3. `HopPlanner` rejects invalid combinations with clear error
4. `ExtensionCompatibilityChecker` detects incompatible PECL extensions
5. `SilentChangeScanner` detects null-to-non-nullable in ≥ 90% of test cases
6. Dashboard 2D timeline shows both dimensions simultaneously
7. PHP 8.4→8.5 beta hop marked with warning; requires acknowledgement

### Performance Targets

- Full 8-hop combined chain: < 45 minutes on enterprise fixture
- PHP-only 4-hop chain: < 15 minutes
- Memory: < 512MB per hop container (same as Phase 2)

---

## Files to Create

| File | Location | Purpose |
|---|---|---|
| `CombinedModeE2ETest.php` | `tests/E2E/` | Full combined mode test |
| `PhpOnlyE2ETest.php` | `tests/E2E/` | PHP-only mode test |
| `FixtureEnterpriseFullTest.php` | `tests/E2E/Fixtures/` | Enterprise full chain |
| `FixtureApiModernTest.php` | `tests/E2E/Fixtures/` | Mid-chain API fixture |
| `FixturePhpOnlyTest.php` | `tests/E2E/Fixtures/` | PHP-only fixture |
| `ExtensionCheckerE2ETest.php` | `tests/E2E/` | Extension blocker E2E |
| `SilentChangeScannerE2ETest.php` | `tests/E2E/` | Silent change detection E2E |
| `TwoDimensionalDashboardE2ETest.php` | `tests/E2E/` | 2D dashboard E2E |
| `CombinedResumeE2ETest.php` | `tests/E2E/` | Resume at mixed hop boundaries |
| Fixture repos | `tests/fixtures/` | 3 enterprise fixture apps |
| `KNOWN_ISSUES.md` | `docs/` | Edge cases and known limitations |

---

## Acceptance Criteria

- [ ] Full 8-hop combined chain completes on `fixture-enterprise-full`
- [ ] Mid-chain start (L10+PHP8.1→L13+PHP8.4) works on `fixture-api-modern`
- [ ] PHP-only mode works on `fixture-php-only` (non-Laravel codebase)
- [ ] Extension checker correctly blocks on incompatible PECL extensions
- [ ] Silent change scanner detects null-to-non-nullable in ≥ 90% of test cases
- [ ] 2D dashboard timeline accurate during combined run
- [ ] Resume works at any hop boundary in mixed chain
- [ ] PHP 8.4→8.5 beta hop requires explicit acknowledgement
- [ ] Performance targets met (< 45min combined, < 15min PHP-only)
- [ ] All edge cases documented in `KNOWN_ISSUES.md`
- [ ] Phase 3 enterprise pilot ready
