# P1-18: Report Generator

**Phase:** 1 — MVP  
**Priority:** Must Have  
**Estimated Effort:** 5-6 days  
**Dependencies:** P1-05 (Breaking Change Registry), P1-08 (Workspace Manager — DiffGenerator), P1-11 (Event Streaming — audit log)  
**Blocks:** P1-20 (Test Suite — report verification in integration tests)  

---

## Agent Persona

**Role:** Report & Documentation Engineer  
**Agent File:** `agents/report-documentation-engineer.agent.md`  
**Domain Knowledge Required:**
- HTML generation with inline CSS/JS (no external dependencies)
- Diff2Html library (JavaScript) for rendering unified diffs
- Unified diff format and generation
- JSON-ND (Newline-Delimited JSON) for audit logs
- Markdown generation for developer-facing reports
- Confidence scoring algorithms
- Air-gapped environment considerations (no CDN, no network)

---

## Objective

Implement `ReportBuilder.php`, `ConfidenceScorer.php`, and all three formatters (`HtmlFormatter`, `JsonFormatter`, `MarkdownFormatter`) in `src-container/Report/`. Reports must render fully offline — all assets inline.

---

## Context from PRD & TRD

### HTML Report (TRD §14.1 — TRD-REPORT-001, TRD-REPORT-002, F-11)

**CRITICAL:** `diff2html.min.css` and `diff2html.min.js` MUST be inline `<style>` and `<script>` tags. NO CDN links. Report MUST render fully without internet.

HTML sections:
1. **Header:** repo name, upgrade path, overall confidence, timestamp
2. **Summary panel:** total files changed, auto-fixed, manual review, skipped
3. **Breaking changes index:** table linking each change ID to its diff section
4. **Per-file diffs:** side-by-side unified diff via Diff2Html, annotated with rule ID
5. **Manual review section:** grouped list with specific guidance per issue

### Confidence Scoring (TRD-REPORT-003)

```
base_score = 100
per_manual_review_file: -2 points (max deduction: 30)
per_unresolved_blocker:  -10 points
per_phpstan_regression:  -15 points
syntax_error_anywhere:   report as 0% (always)
floor: 0, ceiling: 100
```

### Manual Review Report (TRD-REPORT-004)

`manual-review.md` — Markdown sorted by severity descending (blockers first):
- File path (relative)
- Issue ID (from registry or `MANUAL-{n}`)
- Human-readable description
- Code snippet showing problematic pattern

### Audit Log (TRD-REPORT-005)

`audit.log.json` — JSON-ND, each line enriched with:
- `run_id`: UUID v4
- `host_version`: upgrader semver
- `repo_sha`: commit SHA

MUST NOT contain: source code, file contents, tokens.

### Output Directory Contract (TRD §27.2)

```
upgrader-output/
├── report.html           # HTML diff report (offline-capable)
├── report.json           # Machine-readable summary
├── manual-review.md      # Developer action items
├── audit.log.json        # JSON-ND event log
├── phpstan-baseline.json # PHPStan baseline (cached for resume)
└── workspace/            # The upgraded codebase
```

### PRD Requirements

| ID | Requirement |
|---|---|
| RP-01 | HTML with Diff2Html inline (no CDN) |
| RP-02 | Diffs annotated with breaking change rule IDs |
| RP-03 | Per-file confidence (High/Medium/Low) |
| RP-04 | Overall confidence score |
| RP-05 | `manual-review.md` with prioritised review items |
| RP-06 | `audit.log.json` — JSON-ND, machine-readable |
| RP-07 | `report.json` — structured summary for CI/CD |

---

## Files to Create

| File | Location | Purpose |
|---|---|---|
| `ReportBuilder.php` | `src-container/Report/` | Report orchestrator |
| `ConfidenceScorer.php` | `src-container/Report/` | Score calculation algorithm |
| `HtmlFormatter.php` | `src-container/Report/Formatters/` | HTML+Diff2Html inline report |
| `JsonFormatter.php` | `src-container/Report/Formatters/` | Machine-readable JSON summary |
| `MarkdownFormatter.php` | `src-container/Report/Formatters/` | manual-review.md generator |
| `ReportData.php` | `src-container/Report/` | Report data value object |

---

## Acceptance Criteria

- [ ] HTML report includes `diff2html.min.css` and `diff2html.min.js` as inline tags
- [ ] NO CDN links in HTML report — fully offline
- [ ] Side-by-side unified diffs rendered via Diff2Html
- [ ] Each diff annotated with the breaking change rule that caused it
- [ ] Per-file confidence scores: High (≥80), Medium (50-79), Low (<50)
- [ ] Overall confidence score using specified algorithm
- [ ] Syntax error anywhere → 0% confidence (always)
- [ ] `manual-review.md` sorted by severity descending
- [ ] Each manual review entry has: file path, issue ID, description, code snippet
- [ ] `audit.log.json` is JSON-ND with enrichment fields
- [ ] Audit log NEVER contains source code, tokens, or absolute paths
- [ ] `report.json` contains structured summary for CI/CD consumption
- [ ] All report files written to `/output/` directory
- [ ] Report renders correctly in Chrome, Firefox, Safari, Edge

---

## Implementation Notes

- `diff2html.min.css` and `diff2html.min.js` are pre-downloaded and stored in `assets/`
- The HTML report is generated as a single self-contained file
- `ConfidenceScorer` should be deterministic — same input always produces same score
- `ReportBuilder` collects data from all pipeline stages via events
- The `report.json` is designed for CI/CD — include exit-code-worthy summary fields
- In Phase 2, the HTML report gains file tree navigation and hop-by-hop filtering
