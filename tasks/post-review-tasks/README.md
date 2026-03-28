# Post-Review Tasks

These follow-up tasks were created from the Senior Staff review workflow for P1-01 through P1-03.

## Tasks

- `PR-01` — Restore CLI bootstrap and Symfony Console help path
- `PR-02` — Add direct scaffold contract tests for P1-01
- `PR-03` — Consolidate PHPStan level configuration to `phpstan.neon` at level 6
- `PR-04` — Bring P1-02 remote fetchers into auth and lock compliance
- `PR-05` — Refactor P1-02 fetcher tests to assert the required subprocess contract
- `PR-06` — Bring P1-03 workspace path normalization into WSL2 compliance
- `PR-07` — Bring P1-03 diff application into checkpoint and new-file compliance
- `PR-08` — Strengthen P1-03 workspace tests around the real requirements
- `PR-09` — Bring P1-04 Lumen package detection into TRD compliance
- `PR-10` — Add P1-04 detector regression coverage for the required fixture matrix

## Notes

- These are remediation tasks against findings discovered after P1-01 was already marked complete.
- `PR-01` addresses the broken runtime behavior.
- `PR-02` addresses the missing regression coverage that allowed the breakage through.
- `PR-03` records the requested PHPStan configuration policy: level 6, with `phpstan.neon` as the only source of truth.
- `PR-04` addresses the secure remote clone flow and concurrent lock compliance gaps found in P1-02.
- `PR-05` addresses the fact that the P1-02 tests were validating the wrong auth design and were not proving the required subprocess contract.
- `PR-06` addresses the incomplete Windows-to-WSL2 path normalization behavior required by F-09.
- `PR-07` addresses the missing checkpoint update flow and the new-file diff application bug in `WorkspaceManager::applyDiffs()`.
- `PR-08` addresses the missing regression coverage that allowed the P1-03 requirement gaps to pass unnoticed.
- `PR-09` addresses the P1-04 Lumen detection bug where `require-dev` package declarations do not satisfy the dual-check contract from TRD-LUMEN-001.
- `PR-10` addresses the missing P1-04 regression coverage for Lumen `require-dev` detection and the required Laravel/Lumen 8/9 fixture matrix.