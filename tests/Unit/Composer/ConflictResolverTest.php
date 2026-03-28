<?php

declare(strict_types=1);

namespace Tests\Unit\Composer;

use AppContainer\Composer\ConflictResolver;
use AppContainer\Composer\DependencyBlocker;
use AppContainer\Composer\Exception\DependencyBlockerException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AppContainer\Composer\ConflictResolver
 * @covers \AppContainer\Composer\ResolutionResult
 */
final class ConflictResolverTest extends TestCase
{
    public function testCriticalBlockersThrowWithoutIgnoreFlag(): void
    {
        $resolver = new ConflictResolver();
        $blockers = [
            new DependencyBlocker('vendor/bad', 'critical', 'No L9 support', null),
        ];

        $this->expectException(DependencyBlockerException::class);
        $resolver->resolve($blockers, ignoreBlockers: false);
    }

    public function testCriticalBlockersBypassedWhenIgnored(): void
    {
        $resolver = new ConflictResolver();
        $blockers = [
            new DependencyBlocker('vendor/bad', 'critical', 'No L9 support', null),
        ];

        $result = $resolver->resolve($blockers, ignoreBlockers: true);

        self::assertCount(1, $result->bypassed);
        self::assertCount(0, $result->applied);
        self::assertSame('vendor/bad', $result->bypassed[0]->package);
    }

    public function testWarningsOnlyDoNotThrow(): void
    {
        $resolver = new ConflictResolver();
        $blockers = [
            new DependencyBlocker('vendor/maybe', 'warning', 'Unknown support', null),
        ];

        $result = $resolver->resolve($blockers, ignoreBlockers: false);

        self::assertCount(1, $result->applied);
        self::assertCount(0, $result->bypassed);
    }

    public function testEmptyBlockersReturnsEmptyResult(): void
    {
        $resolver = new ConflictResolver();
        $result = $resolver->resolve([], ignoreBlockers: false);

        self::assertCount(0, $result->applied);
        self::assertCount(0, $result->bypassed);
    }

    public function testMixedBlockersThrowsOnCritical(): void
    {
        $resolver = new ConflictResolver();
        $blockers = [
            new DependencyBlocker('vendor/ok-ish', 'warning', 'Unknown', null),
            new DependencyBlocker('vendor/bad', 'critical', 'Blocked', null),
        ];

        $this->expectException(DependencyBlockerException::class);
        $resolver->resolve($blockers, ignoreBlockers: false);
    }

    public function testMixedBlockersBypassedWhenIgnored(): void
    {
        $resolver = new ConflictResolver();
        $blockers = [
            new DependencyBlocker('vendor/ok-ish', 'warning', 'Unknown', null),
            new DependencyBlocker('vendor/bad', 'critical', 'Blocked', null),
        ];

        $result = $resolver->resolve($blockers, ignoreBlockers: true);

        self::assertCount(2, $result->bypassed);
        self::assertCount(0, $result->applied);
    }
}
