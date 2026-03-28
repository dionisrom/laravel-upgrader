<?php

declare(strict_types=1);

namespace Tests\E2E\Fixtures;

use PHPUnit\Framework\Attributes\Group;
use Tests\E2E\E2ETestCase;

#[Group('e2e')]
#[Group('integration')]
final class FixtureMinimalTest extends E2ETestCase
{
    public function testFinalOutputPhpStanHelperResolvesPortableRootBinary(): void
    {
        $repoRoot = $this->makeTempDir('-phpstan-root');
        $vendorBin = $repoRoot . '/vendor/bin';

        mkdir($vendorBin, 0700, true);

        if (PHP_OS_FAMILY === 'Windows') {
            $expectedPath = $vendorBin . '/phpstan.phar.bat';
            touch($vendorBin . '/phpstan.phar');
            touch($expectedPath);

            self::assertSame([$expectedPath], $this->resolveRootPhpStanCommand($repoRoot));

            return;
        }

        $expectedPath = $vendorBin . '/phpstan.phar';
        touch($expectedPath);

        self::assertSame([PHP_BINARY, $expectedPath], $this->resolveRootPhpStanCommand($repoRoot));
    }

    public function testMinimalFixtureCompletesFullChainAndGeneratesUnifiedReport(): void
    {
        $this->requireDocker();

        $result = $this->runFixtureChain('fixture-minimal');

        $this->assertChainCompleted($result);
        $report = $this->assertUnifiedReportGenerated($result);
        $composer = $this->readComposerManifest($result->workspacePath);
        $this->assertFinalOutputPhpStanLevel6Passes($result->workspacePath);

        $internalEmitter = (string) file_get_contents($result->workspacePath . '/app/Support/InternalEmitter.php');
        $changedFiles = $this->changedFilesFromReport($report);
        $expectedEmitCall = '$' . "this->emit('minimal.fixture')";

        $this->assertComposerConstraintContains($composer, 'laravel/framework', '13');
        self::assertCount(5, $report['hops']);
        self::assertGreaterThan(0, $report['total_files_changed']);
        self::assertStringContainsString($expectedEmitCall, $internalEmitter);
        self::assertStringNotContainsString('$this->dispatch(', $internalEmitter);
        self::assertNotContains('app/Support/InternalEmitter.php', $changedFiles);
        self::assertStringContainsString('12-&gt;13', (string) file_get_contents($result->reportHtmlPath));
    }
}