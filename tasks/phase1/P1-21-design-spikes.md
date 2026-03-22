# P1-21: Design Spikes (L10→L11 Slim Skeleton + Livewire V2→V3)

**Phase:** 1 — MVP (Weeks 20-21)  
**Priority:** Must Have (Gates Phase 2)  
**Estimated Effort:** 10 days (5 days each spike)  
**Dependencies:** P1-07 (Rector Rules — understanding of transformation capabilities), P1-14 (Lumen Migration — scaffold generator pattern)  
**Blocks:** Phase 2 start (both spike documents are Phase 2 entry criteria)  

---

## Agent Persona

**Role:** Laravel Migration Specialist  
**Agent File:** `agents/laravel-migration-specialist.agent.md`  
**Domain Knowledge Required:**
- Deep knowledge of Laravel 10→11 breaking changes (slim skeleton restructure)
- Laravel 11's `bootstrap/app.php` architecture (replacing Kernel.php and Handler.php)
- Livewire V2 and V3 API differences
- Rector's capabilities and limitations for scaffold-level changes
- Understanding of what can be automated vs. what must be flagged for manual review
- Experience with enterprise Laravel codebases and their customization patterns

---

## Objective

Conduct and document two mandatory design spikes that define the scope and approach for Phase 2's most complex modules. Both spike documents must be committed before Phase 2 begins. These push overall plan confidence from 90% to 96%.

---

## Context from PRD

### Why These Spikes Are Mandatory

From PRD Phase 1 §11.1:
> These spikes are mandatory before Phase 2 can begin. They push overall plan confidence from 90% to 96%.

From PRD Phase 2 §2.1 (Entry Criteria):
> - L10→L11 slim skeleton design spike document committed (from Phase 1 week 20)
> - Livewire V2→V3 scope design spike document committed (from Phase 1 week 21)

### Spike 1: L10→L11 Slim Skeleton (1 week)

**Goal:** Understand full scope of `bootstrap/app.php` rewrite; identify what Rector cannot handle.

**Output:** Spike document containing:
- Complete list of Kernel.php patterns to migrate (middleware, middleware groups, route middleware, terminate middleware)
- Complete list of Handler.php patterns to migrate (report, render, shouldReport, renderable/reportable exceptions)
- Coverage of service provider registration migration
- Identification of non-standard patterns that cannot be auto-migrated
- Estimated module list for Phase 2 SlimSkeleton suite
- Decision: AST transform vs. scaffold regeneration (spoiler: scaffold regeneration wins)

**Key Questions to Answer:**
1. What does a standard Kernel.php contain that moves to bootstrap/app.php?
2. What does a standard Handler.php contain that moves to bootstrap/app.php?
3. What custom/non-standard patterns exist in enterprise Kernel/Handler files?
4. Can Rector handle this, or is it a scaffold regeneration (like Lumen)?
5. What is the module list for Phase 2?

### Spike 2: Livewire V2→V3 (1 week)

**Goal:** Scope the Livewire migration sub-module; determine automated vs. manual boundary.

**Output:** Spike document containing:
- Livewire V2→V3 breaking changes catalogue
- Which changes are statically detectable and auto-fixable
- Which changes require runtime context (manual review)
- Boundary definition: what's automated, what's flagged
- Phase 2 Livewire module design and estimated effort

**Automated Scope (from PRD §6.3):**
- Component class syntax updates
- Lifecycle hook method renames
- `wire:model` directive changes (where statically detectable)

**Manual Review Scope:**
- Blade directive changes requiring context
- Nested component architecture changes
- Custom JavaScript interop
- Alpine.js integration changes

---

## Files to Create

| File | Location | Purpose |
|---|---|---|
| `design-spike-L10-L11-slim-skeleton.md` | `docs/spikes/` | L10→L11 migration design |
| `design-spike-livewire-v2-v3.md` | `docs/spikes/` | Livewire migration scope |

---

## Acceptance Criteria

- [ ] L10→L11 spike document committed with complete Kernel.php migration analysis
- [ ] L10→L11 spike document includes Handler.php migration analysis
- [ ] L10→L11 spike document lists estimated Phase 2 modules
- [ ] L10→L11 spike identifies scaffold regeneration as the approach (not AST transform)
- [ ] Livewire spike document catalogues all V2→V3 breaking changes
- [ ] Livewire spike clearly defines automated vs. manual review boundary
- [ ] Livewire spike produces Phase 2 module design
- [ ] Both documents are detailed enough for Phase 2 task breakdown
- [ ] Both documents reference real-world enterprise patterns observed

---

## Implementation Notes

- These are research/analysis tasks, not code implementation
- Use real Laravel 10 and 11 projects as reference material
- Analyse the official Laravel 11 upgrade guide thoroughly
- Analyse the Livewire V3 upgrade guide thoroughly
- Consider enterprise patterns: custom middleware groups, conditional service providers, exception handling with external services
- The L10→L11 spike should look at how Laravel Shift handles this transition
- The Livewire spike should consider Livewire's own upgrade tool (`livewire:upgrade`)
