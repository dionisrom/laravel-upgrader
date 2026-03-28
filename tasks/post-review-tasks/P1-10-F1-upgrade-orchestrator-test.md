# P1-10-F1: Add UpgradeOrchestratorTest

**Severity:** HIGH  
**Finding:** UpgradeOrchestrator has zero test coverage despite being the most critical class in P1-10.  
**Violated:** Task AC — "UpgradeOrchestrator halts on verification failure", "Original repo unmodified until all hops pass verification"

## Required Tests

1. Successful single-hop run: plan → docker run → verification passes → write-back
2. Verification failure halts immediately (no write-back)
3. Docker failure (non-zero exit) throws OrchestratorException
4. Invalid hop plan throws OrchestratorException
5. Checkpoint skip: already-completed hop is skipped
6. Write-back only when at least one hop ran
7. EventCollector reset between hops

## Implementation Notes

- Mock `HopPlanner`, `DockerRunner`, `WorkspaceManager`, `CheckpointManagerInterface`
- Use real `EventStreamer` + `EventCollector` to verify event flow
- Test file: `tests/Unit/Orchestrator/UpgradeOrchestratorTest.php`
