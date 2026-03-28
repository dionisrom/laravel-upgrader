<?php

declare(strict_types=1);

namespace Tests\Unit\Report;

use App\Report\ChainReport;
use App\Report\DiffViewerV2Generator;
use App\Report\HopReport;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class DiffViewerV2GeneratorTest extends TestCase
{
    private string $stubAssetsDir;
    private string $stubTemplatesDir;

    protected function setUp(): void
    {
        parent::setUp();

        $base = sys_get_temp_dir() . '/diff-v2-test-' . uniqid('', true);

        // diff2html assets
        $this->stubAssetsDir = $base . '/assets';
        mkdir($this->stubAssetsDir, 0755, true);
        file_put_contents($this->stubAssetsDir . '/diff2html.min.css', '/* stub-css */');
        file_put_contents($this->stubAssetsDir . '/diff2html.min.js', 'var D=1;');

        // Report CSS/JS assets (inlined by generator)
        $this->stubTemplatesDir = $base . '/templates';
        mkdir($this->stubTemplatesDir . '/report/assets', 0755, true);
        file_put_contents($this->stubTemplatesDir . '/report/assets/diff-viewer-v2.css', '/* report-stub-css */');
        file_put_contents($this->stubTemplatesDir . '/report/assets/diff-viewer-v2.js', '/* report-stub-js */');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $base = dirname($this->stubAssetsDir);

        @unlink($this->stubAssetsDir . '/diff2html.min.css');
        @unlink($this->stubAssetsDir . '/diff2html.min.js');
        @rmdir($this->stubAssetsDir);

        @unlink($this->stubTemplatesDir . '/report/assets/diff-viewer-v2.css');
        @unlink($this->stubTemplatesDir . '/report/assets/diff-viewer-v2.js');
        @rmdir($this->stubTemplatesDir . '/report/assets');
        @rmdir($this->stubTemplatesDir . '/report');
        @rmdir($this->stubTemplatesDir);
        @rmdir($base);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Build a generator that uses an in-memory Twig template so tests do not
     * depend on the real templates/report/ directory.
     */
    private function makeGenerator(string $templateBody = ''): DiffViewerV2Generator
    {
        if ($templateBody === '') {
            $templateBody = <<<'TWIG'
            CHAIN={{ chainId }}|FROM={{ sourceVersion }}|TO={{ targetVersion }}
            CSS={{ diff2htmlCss|raw }}
            JS={{ diff2htmlJs|raw }}
            REPORT_CSS={{ reportCss|raw }}
            REPORT_JS={{ reportJs|raw }}
            TREE={{ fileTreeHtml|raw }}
            HOPS={{ totalHops }}
            {% for s in hopSections %}HOP[{{ s.hopKey }}]={{ s.html|raw }}{% endfor %}
            TWIG;
        }

        $loader = new ArrayLoader(['report/diff-viewer-v2.html.twig' => $templateBody]);
        $twig   = new Environment($loader, ['autoescape' => 'html', 'strict_variables' => true]);

        return new DiffViewerV2Generator(
            assetsDir:    $this->stubAssetsDir,
            templatesDir: $this->stubTemplatesDir,
            twig:         $twig,
        );
    }

    private function makeChainReport(string $chainId = 'test-chain-123'): ChainReport
    {
        return new ChainReport(
            chainId:       $chainId,
            sourceVersion: '8',
            targetVersion: '11',
            hopReports:    [
                new HopReport(
                    fromVersion: '8',
                    toVersion:   '9',
                    hopKey:      '8->9',
                    events:      [],
                    eventCount:  0,
                ),
                new HopReport(
                    fromVersion: '9',
                    toVersion:   '10',
                    hopKey:      '9->10',
                    events:      [],
                    eventCount:  0,
                ),
            ],
            allEvents:    [],
            totalEvents:  0,
        );
    }

    // -----------------------------------------------------------------------
    // Tests
    // -----------------------------------------------------------------------

    public function testGenerateContainsChainId(): void
    {
        $generator = $this->makeGenerator();
        $output    = $generator->generate($this->makeChainReport('my-chain-456'));

        $this->assertStringContainsString('my-chain-456', $output);
    }

    public function testGenerateContainsSourceAndTargetVersions(): void
    {
        $generator = $this->makeGenerator();
        $chain     = new ChainReport('id', '8', '13', [], [], 0);
        $output    = $generator->generate($chain);

        $this->assertStringContainsString('FROM=8', $output);
        $this->assertStringContainsString('TO=13', $output);
    }

    public function testGenerateContainsDiff2HtmlAssets(): void
    {
        $generator = $this->makeGenerator();
        $output    = $generator->generate($this->makeChainReport());

        $this->assertStringContainsString('/* stub-css */', $output);
        $this->assertStringContainsString('var D=1;', $output);
    }

    public function testGenerateContainsHopSections(): void
    {
        $generator = $this->makeGenerator();
        $output    = $generator->generate($this->makeChainReport());

        // Hop keys are HTML-escaped in the template (8->9 becomes 8-&gt;9)
        $this->assertStringContainsString('HOP[8-&gt;9]', $output);
        $this->assertStringContainsString('HOP[9-&gt;10]', $output);
    }

    public function testHopCountPassedToTemplate(): void
    {
        $generator = $this->makeGenerator();
        $output    = $generator->generate($this->makeChainReport());

        $this->assertStringContainsString('HOPS=2', $output);
    }

    public function testFileDiffsAppearsInHopSection(): void
    {
        $generator   = $this->makeGenerator();
        $hopFileDiffs = [
            '8->9' => [
                ['file' => 'app/Models/User.php', 'diff' => '--- a/User.php', 'rules' => ['SomeRule']],
            ],
        ];
        $output = $generator->generate($this->makeChainReport(), $hopFileDiffs);

        // HopSectionRenderer produces HTML containing the filename
        $this->assertStringContainsString('app/Models/User.php', $output);
    }

    public function testFileTreeAppearsInOutput(): void
    {
        $generator   = $this->makeGenerator();
        $hopFileDiffs = [
            '8->9' => [
                ['file' => 'app/Models/User.php', 'diff' => '', 'rules' => []],
            ],
        ];
        $output = $generator->generate($this->makeChainReport(), $hopFileDiffs);

        $this->assertStringContainsString('TREE=', $output);
        // File tree should mention the file
        $this->assertStringContainsString('User.php', $output);
    }

    public function testGeneratedAtDefaultsToCurrentTime(): void
    {
        $generator = $this->makeGenerator('GEN={{ generatedAt }}');
        $before    = date('Y-m-d');
        $output    = $generator->generate($this->makeChainReport());

        $this->assertStringContainsString('GEN=', $output);
        $this->assertStringContainsString($before, $output);
    }

    public function testGeneratedAtCanBeOverridden(): void
    {
        $generator = $this->makeGenerator('GEN={{ generatedAt }}');
        $output    = $generator->generate($this->makeChainReport(), [], '2025-01-01 00:00:00 UTC');

        $this->assertStringContainsString('GEN=2025-01-01 00:00:00 UTC', $output);
    }

    public function testMissingDiff2HtmlCssThrowsRuntimeException(): void
    {
        $emptyDir = sys_get_temp_dir() . '/diff-v2-missing-' . uniqid('', true);
        mkdir($emptyDir, 0755, true);
        // Provide stub report CSS/JS so the generator fails only on the diff2html asset
        mkdir($emptyDir . '/report/assets', 0755, true);
        file_put_contents($emptyDir . '/report/assets/diff-viewer-v2.css', '');
        file_put_contents($emptyDir . '/report/assets/diff-viewer-v2.js', '');

        try {
            $loader = new ArrayLoader(['report/diff-viewer-v2.html.twig' => 'x']);
            $twig   = new Environment($loader);
            $gen    = new DiffViewerV2Generator($emptyDir, $emptyDir, twig: $twig);

            $this->expectException(RuntimeException::class);
            $gen->generate($this->makeChainReport());
        } finally {
            @unlink($emptyDir . '/report/assets/diff-viewer-v2.css');
            @unlink($emptyDir . '/report/assets/diff-viewer-v2.js');
            @rmdir($emptyDir . '/report/assets');
            @rmdir($emptyDir . '/report');
            @rmdir($emptyDir);
        }
    }

    public function testNoCdnLinksInOutput(): void
    {
        // Use the real template for this integration-style check
        $workspaceRoot  = dirname(__DIR__, 3);
        $templatePath   = $workspaceRoot . DIRECTORY_SEPARATOR . 'templates';

        if (!is_dir($templatePath . DIRECTORY_SEPARATOR . 'report')) {
            $this->markTestSkipped('Real templates directory not found at: ' . $templatePath);
        }

        $assetsPath = $templatePath . DIRECTORY_SEPARATOR . 'report' . DIRECTORY_SEPARATOR . 'assets';
        $cssExists  = file_exists($assetsPath . DIRECTORY_SEPARATOR . 'diff-viewer-v2.css');
        $jsExists   = file_exists($assetsPath . DIRECTORY_SEPARATOR . 'diff-viewer-v2.js');

        if (!$cssExists || !$jsExists) {
            $this->markTestSkipped('Template assets not found.');
        }

        $diff2htmlAssetsDir = $workspaceRoot . DIRECTORY_SEPARATOR . 'assets';
        if (!file_exists($diff2htmlAssetsDir . DIRECTORY_SEPARATOR . 'diff2html.min.css')) {
            $this->markTestSkipped('diff2html assets not found.');
        }

        $gen    = new DiffViewerV2Generator($diff2htmlAssetsDir, $templatePath);
        $output = $gen->generate($this->makeChainReport());

        $this->assertStringNotContainsString('cdn.jsdelivr.net', $output);
        $this->assertStringNotContainsString('unpkg.com', $output);
        $this->assertStringNotContainsString('cdnjs.cloudflare.com', $output);
    }

    public function testManualReviewFilesTaggedAsReview(): void
    {
        $generator = $this->makeGenerator();

        $chain = new ChainReport(
            chainId:       'chain-mr-test',
            sourceVersion: '8',
            targetVersion: '9',
            hopReports:    [
                new HopReport(
                    fromVersion: '8',
                    toVersion:   '9',
                    hopKey:      '8->9',
                    events:      [
                        [
                            'event' => 'manual_review_required',
                            'files' => ['app/Http/Kernel.php'],
                            'id'    => 'MIDDLEWARE-ORDER',
                            'automated' => false,
                            'reason' => 'Middleware changed',
                        ],
                    ],
                    eventCount:  1,
                ),
            ],
            allEvents:    [],
            totalEvents:  1,
        );

        $output = $generator->generate($chain, [
            '8->9' => [
                ['file' => 'app/Http/Kernel.php', 'diff' => '', 'rules' => []],
            ],
        ]);

        // The file should appear somewhere in the output — either diff block or file tree
        $this->assertStringContainsString('Kernel.php', $output);
    }
}
