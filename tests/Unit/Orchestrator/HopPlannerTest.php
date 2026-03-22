<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Orchestrator\HopPlanner;
use App\Orchestrator\InvalidHopException;
use PHPUnit\Framework\TestCase;

final class HopPlannerTest extends TestCase
{
    private HopPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new HopPlanner();
    }

    public function testPlanReturnsHopSequenceForL8ToL9(): void
    {
        $sequence = $this->planner->plan('8', '9');

        self::assertCount(1, $sequence->hops);

        $hop = $sequence->hops[0];
        self::assertSame('8', $hop->fromVersion);
        self::assertSame('9', $hop->toVersion);
        self::assertSame('laravel', $hop->type);
        self::assertNull($hop->phpBase);
        self::assertStringContainsString('hop-8-to-9', $hop->dockerImage);
    }

    public function testPlanThrowsForSameVersion(): void
    {
        $this->expectException(InvalidHopException::class);

        $this->planner->plan('8', '8');
    }

    public function testPlanThrowsForDowngrade(): void
    {
        $this->expectException(InvalidHopException::class);

        $this->planner->plan('9', '8');
    }

    public function testPlanThrowsForUnsupportedVersionPair(): void
    {
        $this->expectException(InvalidHopException::class);

        $this->planner->plan('9', '10');
    }

    public function testPlanThrowsForEmptyFromVersion(): void
    {
        $this->expectException(InvalidHopException::class);

        $this->planner->plan('', '9');
    }

    public function testPlanThrowsForEmptyToVersion(): void
    {
        $this->expectException(InvalidHopException::class);

        $this->planner->plan('8', '');
    }

    public function testPlanThrowsForNonNumericFromVersion(): void
    {
        $this->expectException(InvalidHopException::class);

        $this->planner->plan('eight', '9');
    }

    public function testPlanThrowsForNonNumericToVersion(): void
    {
        $this->expectException(InvalidHopException::class);

        $this->planner->plan('8', 'nine');
    }

    public function testDockerImageIsConfigurableViaConstructor(): void
    {
        $planner = new HopPlanner(['8:9' => 'myregistry.io/hop-8-to-9:2.0']);

        $sequence = $planner->plan('8', '9');

        self::assertSame('myregistry.io/hop-8-to-9:2.0', $sequence->hops[0]->dockerImage);
    }
}
