<?php

declare(strict_types=1);

namespace Tests\E2E;

use PHPUnit\Framework\Attributes\Group;

#[Group('e2e')]
#[Group('integration')]
final class PerformanceBenchmark extends E2ETestCase
{
    public function testMonolithSingleHopStaysWithinConfiguredBudget(): void
    {
        $this->requireDocker();

        $workspace = $this->copyFixtureToTemp('fixture-monolith');
        $runner    = $this->makeRealChainRunner(timeoutSeconds: 1800);
        $startedAt = microtime(true);

        $result = $runner->run($workspace, '8', '9');
        $elapsedSeconds = microtime(true) - $startedAt;

        $this->assertUnifiedReportGenerated($result);
        $telemetry = $this->assertPerHopContainerMemoryWithinBudget($result);
        self::assertLessThanOrEqual($this->singleHopBudgetSeconds(), $elapsedSeconds);
        self::assertCount(1, $telemetry);
    }

    public function testMonolithFullChainStaysWithinConfiguredBudget(): void
    {
        $this->requireDocker();

        $workspace = $this->copyFixtureToTemp('fixture-monolith');
        $runner    = $this->makeRealChainRunner(timeoutSeconds: 2400);
        $startedAt = microtime(true);

        $result = $runner->run($workspace, '8', '13');
        $elapsedSeconds = microtime(true) - $startedAt;

        $this->assertChainCompleted($result);
        $this->assertUnifiedReportGenerated($result);
        $telemetry = $this->assertPerHopContainerMemoryWithinBudget($result);
        self::assertLessThanOrEqual($this->fullChainBudgetSeconds(), $elapsedSeconds);
        self::assertCount(5, $telemetry);
    }

    private function singleHopBudgetSeconds(): float
    {
        return $this->floatFromEnv('E2E_MAX_SINGLE_HOP_SECONDS', 300.0);
    }

    private function fullChainBudgetSeconds(): float
    {
        return $this->floatFromEnv('E2E_MAX_FULL_CHAIN_SECONDS', 1500.0);
    }

    private function floatFromEnv(string $key, float $default): float
    {
        $value = getenv($key);

        return is_string($value) && is_numeric($value) ? (float) $value : $default;
    }
}