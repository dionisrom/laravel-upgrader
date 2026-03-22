<?php

declare(strict_types=1);

namespace Tests\Unit\Report;

use AppContainer\Report\ReportBuilder;
use AppContainer\Report\ReportData;
use PHPUnit\Framework\TestCase;

final class ReportBuilderTest extends TestCase
{
    private string $tmpOutputDir;
    private string $stubAssetsDir;

    protected function setUp(): void
    {
        parent::setUp();

        $base = sys_get_temp_dir();

        $this->tmpOutputDir  = $base . '/report-builder-output-' . uniqid('', true);
        $this->stubAssetsDir = $base . '/report-builder-assets-' . uniqid('', true);

        mkdir($this->stubAssetsDir, 0755, true);
        file_put_contents($this->stubAssetsDir . '/diff2html.min.css', '/* stub css */');
        file_put_contents($this->stubAssetsDir . '/diff2html.min.js',  '/* stub js */');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach (['report.html', 'report.json', 'manual-review.md'] as $file) {
            @unlink($this->tmpOutputDir . DIRECTORY_SEPARATOR . $file);
        }
        @rmdir($this->tmpOutputDir);

        @unlink($this->stubAssetsDir . '/diff2html.min.css');
        @unlink($this->stubAssetsDir . '/diff2html.min.js');
        @rmdir($this->stubAssetsDir);
    }

    public function testBuildCreatesAllThreeFiles(): void
    {
        $builder = new ReportBuilder($this->tmpOutputDir, $this->stubAssetsDir);
        $builder->build($this->makeData());

        $this->assertFileExists($this->tmpOutputDir . DIRECTORY_SEPARATOR . 'report.html');
        $this->assertFileExists($this->tmpOutputDir . DIRECTORY_SEPARATOR . 'report.json');
        $this->assertFileExists($this->tmpOutputDir . DIRECTORY_SEPARATOR . 'manual-review.md');
    }

    public function testBuildReturnsListOfPaths(): void
    {
        $builder = new ReportBuilder($this->tmpOutputDir, $this->stubAssetsDir);
        $paths   = $builder->build($this->makeData());

        $this->assertIsArray($paths);
        $this->assertCount(3, $paths);

        foreach ($paths as $path) {
            $this->assertFileExists($path, "Returned path must exist: {$path}");
        }
    }

    public function testBuildCreatesOutputDirIfMissing(): void
    {
        // Ensure the directory does NOT exist before build.
        $this->assertDirectoryDoesNotExist($this->tmpOutputDir);

        $builder = new ReportBuilder($this->tmpOutputDir, $this->stubAssetsDir);
        $builder->build($this->makeData());

        $this->assertDirectoryExists($this->tmpOutputDir);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeData(): ReportData
    {
        return new ReportData(
            repoName:            'acme/app',
            fromVersion:         '8',
            toVersion:           '9',
            runId:               'test-run-001',
            repoSha:             'abc123',
            hostVersion:         '1.0.0',
            timestamp:           '2024-01-01T00:00:00Z',
            fileDiffs:           [],
            manualReviewItems:   [],
            dependencyBlockers:  [],
            phpstanRegressions:  [],
            verificationResults: [],
            auditEvents:         [],
            hasSyntaxError:      false,
            totalFilesScanned:   10,
            totalFilesChanged:   0,
        );
    }
}
