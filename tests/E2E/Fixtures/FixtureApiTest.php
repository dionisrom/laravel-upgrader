<?php

declare(strict_types=1);

namespace Tests\E2E\Fixtures;

use PHPUnit\Framework\Attributes\Group;
use Tests\E2E\E2ETestCase;

#[Group('e2e')]
#[Group('integration')]
final class FixtureApiTest extends E2ETestCase
{
    public function testApiFixtureRetainsApiPackagesAndWritesReport(): void
    {
        $this->requireDocker();

        $result = $this->runFixtureChain('fixture-api');

        $this->assertChainCompleted($result);
        $report = $this->assertUnifiedReportGenerated($result);
        $composer = $this->readComposerManifest($result->workspacePath);
        $this->assertFinalOutputPhpStanLevel6Passes($result->workspacePath);

        $this->assertComposerConstraintContains($composer, 'laravel/sanctum', '4.0');
        $this->assertComposerConstraintContains($composer, 'laravel/passport', '12.0');
        self::assertGreaterThanOrEqual(1, $report['hops'][0]['event_count']);
        self::assertFileExists($result->workspacePath . '/routes/api.php');
    }
}