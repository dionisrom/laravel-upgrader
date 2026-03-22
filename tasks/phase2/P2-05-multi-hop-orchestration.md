# P2-05: Multi-Hop Orchestration

**Phase:** 2  
**Priority:** Must Have  
**Estimated Effort:** 10-12 days  
**Dependencies:** P2-01 through P2-04 (all hop containers), P1-10 (Orchestrator/HopPlanner), P1-16 (Checkpoint System)  
**Blocks:** P2-09 (Phase 2 Hardening)  

---

## Agent Persona

**Role:** PHP Orchestrator Engineer  
**Agent File:** `agents/php-orchestrator-engineer.agent.md`  
**Domain Knowledge Required:**
- Phase 1 `HopPlanner` single-hop logic (extend to multi-hop chain L8→L13)
- `TransformCheckpoint` chain-resume — restart mid-chain, skip completed hops
- Docker orchestration — sequential container invocation with workspace handoff
- Event aggregation across hops for unified reporting
- TRD §20: Multi-Hop Chain Extension architecture

---

## Objective

Extend the Phase 1 `HopPlanner` to plan and execute sequential multi-hop chains (e.g., L8→L9→L10→L11→L12→L13). Implement chain-level checkpointing so a failed hop can be retried without re-running completed hops. Aggregate events across all hops into a unified report.

---

## Context from PRD & TRD

### Multi-Hop Chain (PRD §5)

The `upgrade` command accepts `--from` and `--to` flags. The planner determines the hop sequence:

```
upgrade --from=8 --to=13  →  [hop-8-to-9, hop-9-to-10, hop-10-to-11, hop-11-to-12, hop-12-to-13]
```

Each hop's output workspace becomes the next hop's input. The chain must support:
- **Resume**: `--resume` flag restarts from the last incomplete hop
- **Selective**: ability to run a single hop within a chain
- **Verification**: each hop runs verification before the next begins

### Chain Checkpoint (TRD §20)

```php
final class ChainCheckpoint implements \JsonSerializable
{
    public string $chainId;           // UUID for entire chain run
    public string $sourceVersion;     // e.g., "8"
    public string $targetVersion;     // e.g., "13"
    /** @var HopResult[] */
    public array $completedHops;      // hops finished successfully
    public ?string $currentHop;       // hop in progress (null if idle)
    public string $workspacePath;     // current workspace snapshot path
    public \DateTimeImmutable $startedAt;
    public ?\DateTimeImmutable $updatedAt;
}
```

### Unified Report (PRD §5)

After all hops complete, generate a single HTML report that:
- Shows per-hop summaries (files changed, rules applied, issues)
- Provides a combined diff (original → final)
- Includes a hop timeline visualization

### Event Aggregation (TRD §20)

```php
final class ChainEventAggregator
{
    /** @param HopEventStream[] $hopStreams */
    public function aggregate(array $hopStreams): ChainReport;
}
```

Each hop emits JSON-ND events. The aggregator collects all streams and merges them into a unified `ChainReport` with per-hop sections.

---

## Files to Create

| File | Location | Purpose |
|---|---|---|
| `MultiHopPlanner.php` | `src/Orchestrator/` | Plan hop sequences from→to |
| `ChainCheckpoint.php` | `src/State/` | Chain-level checkpoint VO |
| `ChainRunner.php` | `src/Orchestrator/` | Execute hop chain sequentially |
| `ChainEventAggregator.php` | `src/Report/` | Merge per-hop event streams |
| `ChainResumeHandler.php` | `src/Orchestrator/` | Resume from last incomplete hop |
| `MultiHopPlannerTest.php` | `tests/Unit/Orchestrator/` | Planner unit tests |
| `ChainRunnerTest.php` | `tests/Unit/Orchestrator/` | Chain execution tests |
| `ChainResumeTest.php` | `tests/Unit/Orchestrator/` | Resume logic tests |
| `ChainEventAggregatorTest.php` | `tests/Unit/Report/` | Aggregation tests |

---

## Acceptance Criteria

- [ ] `MultiHopPlanner` returns correct hop sequence for any from→to combination
- [ ] `ChainRunner` executes hops sequentially, passing workspace between containers
- [ ] `ChainCheckpoint` persisted after each hop completion
- [ ] `--resume` restarts from last incomplete hop (skips completed)
- [ ] Each hop's verification must pass before next hop begins
- [ ] `ChainEventAggregator` produces unified report data across all hops
- [ ] Full L8→L13 chain tested against fixture app
- [ ] Chain abort on hop failure with clear error context

---

## Implementation Notes

- Re-use Phase 1 `HopPlanner` and `DockerRunner` — `MultiHopPlanner` wraps them
- Workspace handoff: each hop writes to a new workspace dir; the next hop reads from it
- The `ChainCheckpoint` should be stored alongside the per-hop `TransformCheckpoint` files
- Dashboard integration: the ReactPHP dashboard should show chain progress (hop N of M)
