<?php

declare(strict_types=1);

namespace Tests\Unit\Report;

use App\Report\ChainReportArtifactWriter;
use App\Report\DiffViewerV2Generator;
use App\State\HopResult;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class ChainReportArtifactWriterTest extends TestCase
{
    private string $baseDir;
    private string $assetsDir;
    private string $templatesDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseDir = sys_get_temp_dir() . '/chain-report-writer-' . uniqid('', true);
        $this->assetsDir = $this->baseDir . '/assets';
        $this->templatesDir = $this->baseDir . '/templates';

        mkdir($this->assetsDir, 0755, true);
        mkdir($this->templatesDir . '/report/assets', 0755, true);

        file_put_contents($this->assetsDir . '/diff2html.min.css', '/* stub */');
        file_put_contents($this->assetsDir . '/diff2html.min.js', 'var D=1;');
        file_put_contents($this->templatesDir . '/report/assets/diff-viewer-v2.css', '/* stub */');
        file_put_contents($this->templatesDir . '/report/assets/diff-viewer-v2.js', '/* stub */');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->removeDir($this->baseDir);
    }

    public function testWriterPersistsChangedFilesManualReviewItemsAndResourceTelemetry(): void
    {
        $loader = new ArrayLoader([
            'report/diff-viewer-v2.html.twig' => 'CHAIN={{ chainId }}|{% for s in hopSections %}{{ s.hopKey }} {% endfor %}',
        ]);
        $twig = new Environment($loader, ['autoescape' => 'html', 'strict_variables' => true]);
        $diffViewer = new DiffViewerV2Generator(
            assetsDir: $this->assetsDir,
            templatesDir: $this->templatesDir,
            twig: $twig,
        );

        $beforeDir = $this->baseDir . '/before';
        $afterDir = $this->baseDir . '/after';
        $outputDir = $this->baseDir . '/output';

        mkdir($beforeDir . '/app/Http', 0755, true);
        mkdir($beforeDir . '/app/Support', 0755, true);
        mkdir($afterDir . '/app/Http', 0755, true);
        mkdir($afterDir . '/app/Support', 0755, true);

        file_put_contents($beforeDir . '/app/Http/Kernel.php', "<?php\nreturn 'before';\n");
        file_put_contents($afterDir . '/app/Http/Kernel.php', "<?php\nreturn 'after';\n");
        file_put_contents($beforeDir . '/composer.json', "{\"name\":\"before\"}\n");
        file_put_contents($afterDir . '/composer.json', "{\"name\":\"after\"}\n");
        file_put_contents($beforeDir . '/app/Support/InternalEmitter.php', "<?php\nfinal class InternalEmitter {}\n");
        file_put_contents($afterDir . '/app/Support/InternalEmitter.php', "<?php\nfinal class InternalEmitter {}\n");

        $writer = new ChainReportArtifactWriter(diffViewer: $diffViewer);
        $artifacts = $writer->write(
            chainId: 'chain-123',
            sourceVersion: '8',
            targetVersion: '9',
            hopResults: [
                new HopResult(
                    fromVersion: '8',
                    toVersion: '9',
                    dockerImage: 'upgrader/hop-8-to-9',
                    outputPath: $afterDir,
                    completedAt: new \DateTimeImmutable(),
                    events: [
                        [
                            'event' => 'manual_review_required',
                            'id' => 'MR-001',
                            'reason' => 'Check middleware order',
                            'files' => ['app/Http/Kernel.php'],
                        ],
                        [
                            'event' => 'container_resource_usage',
                            'memory_peak_bytes' => 123456,
                            'memory_limit_bytes' => 536870912,
                            'source' => 'cgroup',
                        ],
                    ],
                    inputPath: $beforeDir,
                ),
            ],
            outputDir: $outputDir,
        );

        self::assertFileExists($artifacts['html']);
        self::assertFileExists($artifacts['json']);

        $report = json_decode((string) file_get_contents($artifacts['json']), true);
        self::assertIsArray($report);
        self::assertSame(2, $report['total_files_changed']);
        self::assertSame(1, $report['total_manual_review_items']);
        self::assertSame(['app/Http/Kernel.php', 'composer.json'], $report['hops'][0]['changed_files']);
        self::assertSame(1, $report['hops'][0]['manual_review']);
        self::assertSame('MR-001', $report['hops'][0]['manual_review_items'][0]['id']);
        self::assertSame(['app/Http/Kernel.php'], $report['hops'][0]['manual_review_items'][0]['files']);
        self::assertSame(123456, $report['hops'][0]['resource_usage']['memory_peak_bytes']);
        self::assertSame('cgroup', $report['hops'][0]['resource_usage']['source']);
        self::assertNotContains('app/Support/InternalEmitter.php', $report['hops'][0]['changed_files']);
        self::assertStringContainsString('chain-123', (string) file_get_contents($artifacts['html']));
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}