<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Orchestrator\Hop;
use App\Orchestrator\HopSequence;
use App\Orchestrator\InvalidHopException;
use App\Orchestrator\MultiHopPlanner;
use PHPUnit\Framework\TestCase;

final class MultiHopPlannerTest extends TestCase
{
    private MultiHopPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new MultiHopPlanner();
    }

    // -------------------------------------------------------------------------
    // Single-hop paths
    // -------------------------------------------------------------------------

    public function testPlanL8ToL9ReturnsSingleHop(): void
    {
        $sequence = $this->planner->plan('8', '9');

        self::assertCount(1, $sequence->hops);
        $hop = $sequence->hops[0];
        self::assertSame('8', $hop->fromVersion);
        self::assertSame('9', $hop->toVersion);
        self::assertSame('upgrader/hop-8-to-9', $hop->dockerImage);
        self::assertSame('laravel', $hop->type);
        self::assertNull($hop->phpBase);
    }

    public function testPlanL9ToL10ReturnsSingleHop(): void
    {
        $sequence = $this->planner->plan('9', '10');

        self::assertCount(1, $sequence->hops);
        self::assertSame('9', $sequence->hops[0]->fromVersion);
        self::assertSame('10', $sequence->hops[0]->toVersion);
    }

    public function testPlanL10ToL11ReturnsSingleHop(): void
    {
        $sequence = $this->planner->plan('10', '11');

        self::assertCount(1, $sequence->hops);
        self::assertSame('10', $sequence->hops[0]->fromVersion);
        self::assertSame('11', $sequence->hops[0]->toVersion);
    }

    public function testPlanL11ToL12ReturnsSingleHop(): void
    {
        $sequence = $this->planner->plan('11', '12');

        self::assertCount(1, $sequence->hops);
        self::assertSame('11', $sequence->hops[0]->fromVersion);
        self::assertSame('12', $sequence->hops[0]->toVersion);
    }

    public function testPlanL12ToL13ReturnsSingleHop(): void
    {
        $sequence = $this->planner->plan('12', '13');

        self::assertCount(1, $sequence->hops);
        self::assertSame('12', $sequence->hops[0]->fromVersion);
        self::assertSame('13', $sequence->hops[0]->toVersion);
    }

    // -------------------------------------------------------------------------
    // Multi-hop chains
    // -------------------------------------------------------------------------

    public function testPlanL8ToL13ReturnsFiveHops(): void
    {
        $sequence = $this->planner->plan('8', '13');

        self::assertCount(5, $sequence->hops);

        $expectedPairs = [
            ['8', '9'],
            ['9', '10'],
            ['10', '11'],
            ['11', '12'],
            ['12', '13'],
        ];

        foreach ($expectedPairs as $i => [$from, $to]) {
            self::assertSame($from, $sequence->hops[$i]->fromVersion, "hop {$i} fromVersion");
            self::assertSame($to, $sequence->hops[$i]->toVersion, "hop {$i} toVersion");
        }
    }

    public function testPlanL9ToL11ReturnsTwoHops(): void
    {
        $sequence = $this->planner->plan('9', '11');

        self::assertCount(2, $sequence->hops);
        self::assertSame('9', $sequence->hops[0]->fromVersion);
        self::assertSame('10', $sequence->hops[0]->toVersion);
        self::assertSame('10', $sequence->hops[1]->fromVersion);
        self::assertSame('11', $sequence->hops[1]->toVersion);
    }

    public function testPlanL10ToL13ReturnsThreeHops(): void
    {
        $sequence = $this->planner->plan('10', '13');

        self::assertCount(3, $sequence->hops);
        self::assertSame('10', $sequence->hops[0]->fromVersion);
        self::assertSame('11', $sequence->hops[0]->toVersion);
        self::assertSame('11', $sequence->hops[1]->fromVersion);
        self::assertSame('12', $sequence->hops[1]->toVersion);
        self::assertSame('12', $sequence->hops[2]->fromVersion);
        self::assertSame('13', $sequence->hops[2]->toVersion);
    }

    public function testPlanL8ToL11ReturnsThreeHops(): void
    {
        $sequence = $this->planner->plan('8', '11');

        self::assertCount(3, $sequence->hops);
    }

    public function testPlanL8ToL12ReturnsFourHops(): void
    {
        $sequence = $this->planner->plan('8', '12');

        self::assertCount(4, $sequence->hops);
    }

    // -------------------------------------------------------------------------
    // Hop types
    // -------------------------------------------------------------------------

    public function testAllHopsHaveLaravelTypeAndNullPhpBase(): void
    {
        $sequence = $this->planner->plan('8', '13');

        foreach ($sequence->hops as $hop) {
            self::assertSame('laravel', $hop->type);
            self::assertNull($hop->phpBase);
        }
    }

    public function testHopsUseCorrectDockerImages(): void
    {
        $sequence = $this->planner->plan('8', '13');

        $expectedImages = [
            'upgrader/hop-8-to-9',
            'upgrader/hop-9-to-10',
            'upgrader/hop-10-to-11',
            'upgrader/hop-11-to-12',
            'upgrader/hop-12-to-13',
        ];

        foreach ($expectedImages as $i => $image) {
            self::assertSame($image, $sequence->hops[$i]->dockerImage);
        }
    }

    // -------------------------------------------------------------------------
    // Returns HopSequence instances
    // -------------------------------------------------------------------------

    public function testPlanReturnsHopSequenceInstance(): void
    {
        $result = $this->planner->plan('8', '9');

        self::assertInstanceOf(HopSequence::class, $result);
    }

    // -------------------------------------------------------------------------
    // Invalid inputs throw InvalidHopException
    // -------------------------------------------------------------------------

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

        $this->planner->plan('L8', '9');
    }

    public function testPlanThrowsForNonNumericToVersion(): void
    {
        $this->expectException(InvalidHopException::class);

        $this->planner->plan('8', 'L9');
    }

    public function testPlanThrowsForSameVersion(): void
    {
        $this->expectException(InvalidHopException::class);

        $this->planner->plan('9', '9');
    }

    public function testPlanThrowsForDowngrade(): void
    {
        $this->expectException(InvalidHopException::class);

        $this->planner->plan('11', '9');
    }

    public function testPlanThrowsForUnsupportedStartVersion(): void
    {
        $this->expectException(InvalidHopException::class);

        // Version 7 is not in the default image map.
        $this->planner->plan('7', '9');
    }

    public function testPlanThrowsForGapInSupportedHops(): void
    {
        $limited = new MultiHopPlanner(['8:9' => 'upgrader/hop-8-to-9']);

        $this->expectException(InvalidHopException::class);
        $this->expectExceptionMessageMatches('/9.*10/');

        $limited->plan('8', '10');
    }

    public function testCustomImageMapIsRespected(): void
    {
        $custom = new MultiHopPlanner([
            '1:2' => 'my/hop-1-to-2',
            '2:3' => 'my/hop-2-to-3',
        ]);

        $sequence = $custom->plan('1', '3');

        self::assertCount(2, $sequence->hops);
        self::assertSame('my/hop-1-to-2', $sequence->hops[0]->dockerImage);
        self::assertSame('my/hop-2-to-3', $sequence->hops[1]->dockerImage);
    }
}
