<?php

declare(strict_types=1);

namespace Tests\E2E\Fixtures;

use PHPUnit\Framework\Attributes\Group;
use Tests\E2E\E2ETestCase;

#[Group('e2e')]
#[Group('integration')]
final class FixtureModularTest extends E2ETestCase
{
    public function testModularFixturePreservesModuleStructureAcrossChain(): void
    {
        $this->requireDocker();

        $result = $this->runFixtureChain('fixture-modular');

        $this->assertChainCompleted($result);
        $report = $this->assertUnifiedReportGenerated($result);
        $composer = $this->readComposerManifest($result->workspacePath);
        $providersBootstrap = (string) file_get_contents($result->workspacePath . '/bootstrap/providers.php');
        $this->assertFinalOutputPhpStanLevel6Passes($result->workspacePath);

        self::assertSame('modules/', $composer['autoload']['psr-4']['Modules\\'] ?? null);
        self::assertDirectoryExists($result->workspacePath . '/modules');
        self::assertFileExists($result->workspacePath . '/modules/User/Providers/UserServiceProvider.php');
        self::assertFileExists($result->workspacePath . '/modules/Blog/Providers/BlogServiceProvider.php');
        self::assertFileExists($result->workspacePath . '/modules/Reporting/Providers/ReportingServiceProvider.php');
        self::assertStringContainsString('Modules\\User\\Providers\\UserServiceProvider::class', $providersBootstrap);
        self::assertStringContainsString('Modules\\Blog\\Providers\\BlogServiceProvider::class', $providersBootstrap);
        self::assertStringContainsString('Modules\\Reporting\\Providers\\ReportingServiceProvider::class', $providersBootstrap);
        self::assertContains('bootstrap/providers.php', $this->changedFilesFromReport($report));
    }
}