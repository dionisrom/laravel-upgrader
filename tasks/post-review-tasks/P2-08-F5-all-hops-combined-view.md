# P2-08-F5: Add "All Hops" Combined View

**Severity:** MEDIUM  
**Source:** P2-08 review finding F5  
**Requirement:** PRD DV-03 (Must Have): "view changes per individual hop or all hops combined"

## Problem

Tab navigation only supports one-hop-at-a-time. There is no "All" tab to show all hops combined.

## Acceptance Criteria

- [ ] A "All Hops" tab appears as the first or last tab
- [ ] When active, all hop sections become visible simultaneously
- [ ] File tree sidebar shows files from all hops
- [ ] Filters apply across all visible hops
- [ ] The filter-count reflects the combined total

## Files to Modify

- `templates/report/diff-viewer-v2.html.twig` (add all-hops tab)
- `templates/report/assets/diff-viewer-v2.js` (activateTab logic for "all")
