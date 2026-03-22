# P3-07: Dashboard 2D Timeline

**Phase:** 3  
**Priority:** Must Have  
**Estimated Effort:** 8-10 days  
**Dependencies:** P3-01 (2D HopPlanner), P1-17 (ReactPHP Dashboard), P3-05 (Extension Checker), P3-06 (Silent Change Scanner)  
**Blocks:** P3-08 (Combined Mode Testing)  

---

## Agent Persona

**Role:** ReactPHP/Real-time Systems Engineer  
**Agent File:** `agents/reactphp-dashboard-engineer.agent.md`  
**Domain Knowledge Required:**
- ReactPHP SSE streaming (extend Phase 1 dashboard)
- 2D timeline visualization (two-row layout: Laravel + PHP)
- Visual connector rendering (CSS/SVG for gating relationships)
- PHP-specific issue panels (extensions, silent changes)

---

## Objective

Extend the Phase 1 ReactPHP dashboard to display the 2D timeline view showing both Laravel and PHP upgrade dimensions, visual connectors where PHP hops gate Laravel hops, and PHP-specific issue panels.

---

## Context from PRD & TRD

### Functional Requirements (PRD §15.1)

| ID | Requirement | Priority |
|---|---|---|
| DT-01 | Two-row timeline: Laravel hops on top, PHP hops on bottom | Must Have |
| DT-02 | Visual connectors showing PHP hop gates Laravel hop | Must Have |
| DT-03 | Per-hop confidence score and file change count on timeline | Must Have |
| DT-04 | PHP-specific issues panel (extensions, silent changes, deprecated functions) | Must Have |
| DT-05 | Combined totals: files changed, rules applied, manual review items (both dimensions) | Must Have |
| DT-06 | PHP-only mode: single-row timeline (no Laravel row) | Must Have |

### Dashboard Layout — Combined Mode (PRD §15.2)

```
┌──────────────────────────────────────────────────────────────────┐
│  🔧 Laravel Upgrader  |  org/app  |  L8+PHP8.0 → L13+PHP8.3     │
│  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━  │
│                                                                   │
│  UPGRADE PLAN — 8 hops total                                     │
│                                                                   │
│  Laravel  ──[L8]────────────[L9]──────────[L10]──── ...         │
│                    ↑               ↑                             │
│  PHP       ──[8.0]────[8.1]────────────[8.2]──── ...            │
│            (gates L10↑)        (gates L11↑)                     │
│                                                                   │
│  ✅ L8→L9 (Laravel)     ✅ PHP 8.0→8.1   🔄 L9→L10 (Laravel)  │
│  ⏳ PHP 8.1→8.2          ⏳ L10→L11       ⏳ L11→L12            │
│  ⏳ PHP 8.2→8.3          ⏳ L12→L13                              │
│                                                                   │
│  CURRENT HOP: Laravel 9 → 10  (requires PHP 8.1 ✅)             │
│                                                                   │
│  PHP UPGRADE FINDINGS                                            │
│  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━  │
│  ✅ Dynamic properties → #[AllowDynamicProperties]  AUTO  12    │
│  ✅ Implicit nullable params fixed                  AUTO  34    │
│  ✅ utf8_encode() → mb_convert_encoding()           AUTO   7    │
│  🟡 Null passed to strlen() — verify intent        REVIEW  3   │
│  🔴 ext-imagick not confirmed for PHP 8.4          BLOCK   1   │
└──────────────────────────────────────────────────────────────────┘
```

---

## Files to Create

| File | Location | Purpose |
|---|---|---|
| `TwoDimensionalTimelineRenderer.php` | `src/Dashboard/Renderer/` | 2D timeline HTML/SSE |
| `PhpIssuesPanelRenderer.php` | `src/Dashboard/Renderer/` | Extension + silent change panel |
| `GatingConnectorRenderer.php` | `src/Dashboard/Renderer/` | Visual connectors between rows |
| `CombinedStatsAggregator.php` | `src/Dashboard/` | Merged stats across both dimensions |
| `timeline-2d.html` | `templates/dashboard/` | 2D timeline template |
| `timeline-2d.css` | `templates/dashboard/assets/` | 2D timeline styles |
| `timeline-2d.js` | `templates/dashboard/assets/` | Timeline interactivity + SSE |
| `TwoDimensionalTimelineTest.php` | `tests/Unit/Dashboard/` | Renderer tests |
| `CombinedStatsAggregatorTest.php` | `tests/Unit/Dashboard/` | Stats aggregation tests |

---

## Acceptance Criteria

- [ ] Two-row timeline displays Laravel and PHP hops correctly
- [ ] Visual connectors show PHP→Laravel gating relationships
- [ ] Per-hop confidence score and file change count visible
- [ ] PHP issues panel shows extension blockers and silent change findings
- [ ] Combined totals aggregate both dimensions
- [ ] PHP-only mode shows single-row timeline
- [ ] SSE real-time updates work for 2D view (extending Phase 1 stream)
- [ ] Dashboard remains stable under long-running combined upgrades (40+ minutes)
