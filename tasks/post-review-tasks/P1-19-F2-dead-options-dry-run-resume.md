# P1-19-F2: --dry-run and --resume Flags Are Dead Options

**Severity:** High  
**Source:** P1-19 review  
**Requirement:** TRD §16.2 (TRD-CLI-003), Acceptance Criteria "--resume flag integrated with checkpoint system"

## Problem

`RunCommand::configure()` defines `--dry-run` and `--resume` options, but `execute()` never reads them. They have no effect.

## Fix

1. `--dry-run`: Read the option and if set, delegate to the same dry-run path as AnalyseCommand (F1 fix).
2. `--resume`: Read the option and pass it to the orchestrator to trigger checkpoint resume (P1-16 integration).
3. Add tests for both flags.
