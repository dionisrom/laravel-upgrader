<?php

declare(strict_types=1);

namespace Tests\Unit\Report\Renderer;

use App\Report\HopReport;
use App\Report\Renderer\AnnotationRenderer;
use App\Report\Renderer\HopSectionRenderer;
use PHPUnit\Framework\TestCase;

final class HopSectionRendererTest extends TestCase
{
    private HopSectionRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new HopSectionRenderer(new AnnotationRenderer());
    }

    private function makeHopReport(string $hopKey = '8->9', array $events = []): HopReport
    {
        return new HopReport(
            fromVersion: '8',
            toVersion: '9',
            hopKey: $hopKey,
            events: $events,
            eventCount: count($events),
        );
    }

    // -----------------------------------------------------------------------
    // Empty diffs
    // -----------------------------------------------------------------------

    public function testEmptyDiffsRendersEmptyMessage(): void
    {
        $html = $this->renderer->render($this->makeHopReport(), []);

        $this->assertStringContainsString('hop-empty', $html);
        $this->assertStringContainsString('No file diffs recorded', $html);
    }

    public function testEmptyDiffsEscapesHopKey(): void
    {
        $html = $this->renderer->render($this->makeHopReport('8->9'), []);

        // "8->9" should be HTML-escaped: "8-&gt;9"
        $this->assertStringContainsString('8-&gt;9', $html);
        $this->assertStringNotContainsString('8->9', $html);
    }

    // -----------------------------------------------------------------------
    // Single file diff
    // -----------------------------------------------------------------------

    public function testSingleFileDiffRendersBlock(): void
    {
        $diffs = [
            ['file' => 'app/User.php', 'diff' => '--- a/User.php', 'rules' => []],
        ];
        $html = $this->renderer->render($this->makeHopReport(), $diffs);

        $this->assertStringContainsString('file-diff-block', $html);
        $this->assertStringContainsString('app/User.php', $html);
        $this->assertStringContainsString('data-change-type="auto"', $html);
    }

    // -----------------------------------------------------------------------
    // Manual review badge
    // -----------------------------------------------------------------------

    public function testManualReviewFilesGetReviewBadge(): void
    {
        $events = [
            [
                'event' => 'manual_review_required',
                'files' => ['app/Http/Kernel.php'],
            ],
        ];
        $diffs = [
            ['file' => 'app/Http/Kernel.php', 'diff' => '--- a/Kernel.php', 'rules' => []],
        ];
        $html = $this->renderer->render($this->makeHopReport('8->9', $events), $diffs);

        $this->assertStringContainsString('data-change-type="review"', $html);
        $this->assertStringContainsString('badge-review', $html);
    }

    public function testNonManualReviewFileGetsAutoBadge(): void
    {
        $events = [
            [
                'event' => 'manual_review_required',
                'files' => ['app/Http/Kernel.php'],
            ],
        ];
        $diffs = [
            ['file' => 'app/Models/User.php', 'diff' => '--- a/User.php', 'rules' => []],
        ];
        $html = $this->renderer->render($this->makeHopReport('8->9', $events), $diffs);

        $this->assertStringContainsString('data-change-type="auto"', $html);
    }

    // -----------------------------------------------------------------------
    // Sign-off checkbox
    // -----------------------------------------------------------------------

    public function testSignOffCheckboxPresent(): void
    {
        $diffs = [
            ['file' => 'app/User.php', 'diff' => '--- a/User.php', 'rules' => []],
        ];
        $html = $this->renderer->render($this->makeHopReport(), $diffs);

        $this->assertStringContainsString('signoff-checkbox', $html);
        $this->assertStringContainsString('Signed off', $html);
        $this->assertStringContainsString('type="checkbox"', $html);
    }

    public function testSignOffCheckboxDataFileAttribute(): void
    {
        $diffs = [
            ['file' => 'app/User.php', 'diff' => '', 'rules' => []],
        ];
        $html = $this->renderer->render($this->makeHopReport(), $diffs);

        $this->assertStringContainsString('data-file="app/User.php"', $html);
    }

    // -----------------------------------------------------------------------
    // Review note container
    // -----------------------------------------------------------------------

    public function testReviewNoteContainerPresent(): void
    {
        $diffs = [
            ['file' => 'app/User.php', 'diff' => '', 'rules' => []],
        ];
        $html = $this->renderer->render($this->makeHopReport(), $diffs);

        $this->assertStringContainsString('review-note-container', $html);
        $this->assertStringContainsString('review-note-toggle', $html);
        $this->assertStringContainsString('review-note-text', $html);
    }

    // -----------------------------------------------------------------------
    // HTML escaping
    // -----------------------------------------------------------------------

    public function testFilePathsAreHtmlEscaped(): void
    {
        $diffs = [
            ['file' => 'app/<evil>.php', 'diff' => '', 'rules' => []],
        ];
        $html = $this->renderer->render($this->makeHopReport(), $diffs);

        $this->assertStringNotContainsString('<evil>', $html);
        $this->assertStringContainsString('&lt;evil&gt;', $html);
    }

    public function testDiffContentIsHtmlEscaped(): void
    {
        $diffs = [
            ['file' => 'test.php', 'diff' => '<script>alert(1)</script>', 'rules' => []],
        ];
        $html = $this->renderer->render($this->makeHopReport(), $diffs);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    // -----------------------------------------------------------------------
    // Annotations (Rector rules)
    // -----------------------------------------------------------------------

    public function testRulesRenderedAsAnnotations(): void
    {
        $diffs = [
            ['file' => 'test.php', 'diff' => '', 'rules' => ['SomeRectorRule', 'AnotherRule']],
        ];
        $html = $this->renderer->render($this->makeHopReport(), $diffs);

        $this->assertStringContainsString('rule-annotations', $html);
        $this->assertStringContainsString('SomeRectorRule', $html);
        $this->assertStringContainsString('AnotherRule', $html);
    }

    public function testEmptyRulesNoAnnotationBlock(): void
    {
        $diffs = [
            ['file' => 'test.php', 'diff' => '', 'rules' => []],
        ];
        $html = $this->renderer->render($this->makeHopReport(), $diffs);

        $this->assertStringNotContainsString('rule-annotations', $html);
    }

    // -----------------------------------------------------------------------
    // Multiple files
    // -----------------------------------------------------------------------

    public function testMultipleFilesRenderMultipleBlocks(): void
    {
        $diffs = [
            ['file' => 'a.php', 'diff' => '', 'rules' => []],
            ['file' => 'b.php', 'diff' => '', 'rules' => []],
            ['file' => 'c.php', 'diff' => '', 'rules' => []],
        ];
        $html = $this->renderer->render($this->makeHopReport(), $diffs);

        $this->assertSame(3, substr_count($html, 'file-diff-block'));
    }

    // -----------------------------------------------------------------------
    // Diff container structure
    // -----------------------------------------------------------------------

    public function testDiffContainerHasUnifiedDiffAndWrapper(): void
    {
        $diffs = [
            ['file' => 'test.php', 'diff' => '--- a/test.php', 'rules' => []],
        ];
        $html = $this->renderer->render($this->makeHopReport(), $diffs);

        $this->assertStringContainsString('unified-diff', $html);
        $this->assertStringContainsString('d2h-wrapper', $html);
        $this->assertStringContainsString('data-diff=', $html);
    }

    // -----------------------------------------------------------------------
    // Extension and directory data attributes
    // -----------------------------------------------------------------------

    public function testExtAndDirDataAttributesPresent(): void
    {
        $diffs = [
            ['file' => 'app/Models/User.php', 'diff' => '', 'rules' => []],
        ];
        $html = $this->renderer->render($this->makeHopReport(), $diffs);

        $this->assertStringContainsString('data-ext="php"', $html);
        $this->assertStringContainsString('data-dir="app/Models"', $html);
    }

    // -----------------------------------------------------------------------
    // Confidence data attribute
    // -----------------------------------------------------------------------

    public function testDefaultConfidenceIsHigh(): void
    {
        $diffs = [
            ['file' => 'test.php', 'diff' => '', 'rules' => []],
        ];
        $html = $this->renderer->render($this->makeHopReport(), $diffs);

        $this->assertStringContainsString('data-confidence="high"', $html);
    }

    public function testExplicitConfidenceIsRendered(): void
    {
        $diffs = [
            ['file' => 'test.php', 'diff' => '', 'rules' => [], 'confidence' => 'low'],
        ];
        $html = $this->renderer->render($this->makeHopReport(), $diffs);

        $this->assertStringContainsString('data-confidence="low"', $html);
    }

    public function testMediumConfidenceRendered(): void
    {
        $diffs = [
            ['file' => 'a.php', 'diff' => '', 'rules' => [], 'confidence' => 'medium'],
        ];
        $html = $this->renderer->render($this->makeHopReport(), $diffs);

        $this->assertStringContainsString('data-confidence="medium"', $html);
    }

    // -----------------------------------------------------------------------
    // Checkbox ID uniqueness
    // -----------------------------------------------------------------------

    public function testCheckboxIdsUniqueForSimilarPaths(): void
    {
        // Files that differ only in special chars should produce different IDs
        $diffs = [
            ['file' => 'a/b.php', 'diff' => '', 'rules' => []],
            ['file' => 'a_b.php', 'diff' => '', 'rules' => []],
        ];
        $html = $this->renderer->render($this->makeHopReport(), $diffs);

        // Extract all signoff-* IDs
        preg_match_all('/id="(signoff-[a-f0-9]+)"/', $html, $matches);
        $ids = $matches[1];

        $this->assertCount(2, $ids);
        $this->assertNotSame($ids[0], $ids[1], 'Checkbox IDs must be unique for different files');
    }
}
