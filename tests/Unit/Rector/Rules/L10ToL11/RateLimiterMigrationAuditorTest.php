<?php

declare(strict_types=1);

namespace Tests\Unit\Rector\Rules\L10ToL11;

use AppContainer\Rector\Rules\L10ToL11\RateLimiterMigrationAuditor;
use Iterator;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

/**
 * @see RateLimiterMigrationAuditor
 *
 * RateLimiterMigrationAuditor is a detect-only rule (returns null from refactor()).
 * These tests verify that no code transformation occurs — the rule only audits.
 */
final class RateLimiterMigrationAuditorTest extends AbstractRectorTestCase
{
    /**
     * @dataProvider provideData
     */
    public function test(string $filePath): void
    {
        $this->doTestFile($filePath);
    }

    /**
     * @return Iterator<array{string}>
     */
    public static function provideData(): Iterator
    {
        return self::yieldFilesFromDirectory(__DIR__ . '/Fixture/RateLimiterMigration');
    }

    public function provideConfigFilePath(): string
    {
        return __DIR__ . '/config/rate_limiter_migration_auditor_rector.php';
    }
}
