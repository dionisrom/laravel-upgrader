# P2-08-F7: Fix Potential Checkbox ID Collisions in HopSectionRenderer

**Severity:** LOW  
**Source:** P2-08 review finding F7  
**Requirement:** DV-05 — Sign-off workflow correctness

## Problem

`HopSectionRenderer` generates checkbox IDs via `'signoff-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $file)`. Two files differing only in special characters (e.g., `a/b.php` and `a_b.php`) produce the same ID `signoff-a-b-php`, causing duplicate HTML IDs and broken sign-off state.

## Fix

Include the hop key in the ID prefix and use a hash suffix for uniqueness: `signoff-{hopKey}-{md5($file)}` or use the full `htmlspecialchars`-escaped file path as the ID value.

## Acceptance Criteria

- [ ] Checkbox IDs are unique across all file diff blocks in the entire report
- [ ] Sign-off localStorage keys remain unique per file per hop
- [ ] Unit test asserts no duplicate IDs when files have similar paths

## Files to Modify

- `src/Report/Renderer/HopSectionRenderer.php`
