<?php

declare(strict_types=1);

namespace Tests\E2E\Fixtures;

use PHPUnit\Framework\Attributes\Group;
use Tests\E2E\E2ETestCase;

#[Group('e2e')]
#[Group('integration')]
final class FixtureMonolithTest extends E2ETestCase
{
    public function testMonolithFixtureKeepsEnterprisePackagesAligned(): void
    {
        $this->requireDocker();

        $result = $this->runFixtureChain('fixture-monolith');

        $this->assertChainCompleted($result);
        $report = $this->assertUnifiedReportGenerated($result);
        $composer = $this->readComposerManifest($result->workspacePath);
        $providersBootstrap = (string) file_get_contents($result->workspacePath . '/bootstrap/providers.php');
        $this->assertFinalOutputPhpStanLevel6Passes($result->workspacePath);

        $this->assertComposerConstraintContains($composer, 'laravel/horizon', '5');
        $this->assertComposerConstraintContains($composer, 'spatie/laravel-permission', '6.0');
        self::assertArrayHasKey('spatie/laravel-activitylog', $composer['require']);
        self::assertStringContainsString('Laravel\\Horizon\\HorizonServiceProvider::class', $providersBootstrap);
        self::assertStringContainsString('Spatie\\Permission\\PermissionServiceProvider::class', $providersBootstrap);
        self::assertCount(5, $report['hops']);
        self::assertGreaterThan(0, $report['total_files_changed']);
    }
}