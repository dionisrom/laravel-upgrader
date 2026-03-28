# P1-21-R2: Correct $wire.emit() Automation Confidence in Livewire Spike

**Source:** P1-21 post-review finding #2  
**Severity:** warning  
**File:** `docs/spikes/design-spike-livewire-v2-v3.md` Executive Summary table  
**Impacted Requirements:** AC6 — automated vs. manual boundary accuracy  

## Problem

The automation confidence table in the Executive Summary claims ~95% coverage for `$wire.emit()` → `$wire.dispatch()`. The body (§D1) correctly states the rename is automated but the argument restructuring (positional → object payload) is NOT automated and is flagged for manual review. The summary table overstates confidence.

## Fix

Update the Executive Summary automation confidence table entry for `$wire.emit()` → `$wire.dispatch()`:

- Current: `~95%` with note "Detectable in Blade JS context"  
- Corrected: `~95% rename / 0% argument format` with note "Method rename auto-fixed; positional→object argument restructuring requires manual review"

## Acceptance

- Executive Summary table entry for `$wire.emit()` → `$wire.dispatch()` accurately reflects that only the rename is automated, not the argument format change.

## Effort

~5 minutes.
