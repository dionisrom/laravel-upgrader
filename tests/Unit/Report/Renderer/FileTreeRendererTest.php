<?php

declare(strict_types=1);

namespace Tests\Unit\Report\Renderer;

use App\Report\Renderer\FileTreeRenderer;
use PHPUnit\Framework\TestCase;

final class FileTreeRendererTest extends TestCase
{
    private FileTreeRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new FileTreeRenderer();
    }

    public function testEmptyFileListRendersNoFilesMessage(): void
    {
        $html = $this->renderer->render([]);

        $this->assertStringContainsString('No changed files', $html);
        $this->assertStringContainsString('no-files', $html);
    }

    public function testSingleFileAtRootLevel(): void
    {
        $files = [['file' => 'composer.json', 'changeType' => 'auto']];
        $html = $this->renderer->render($files);

        $this->assertStringContainsString('composer.json', $html);
        $this->assertStringContainsString('file-entry', $html);
        $this->assertStringContainsString('data-file="composer.json"', $html);
        $this->assertStringContainsString('data-change-type="auto"', $html);
    }

    public function testNestedDirectoryStructure(): void
    {
        $files = [
            ['file' => 'app/Models/User.php', 'changeType' => 'auto'],
            ['file' => 'app/Models/Post.php', 'changeType' => 'review'],
        ];
        $html = $this->renderer->render($files);

        // Directory nodes
        $this->assertStringContainsString('dir-node', $html);
        $this->assertStringContainsString('app/', $html);
        $this->assertStringContainsString('Models/', $html);

        // File entries
        $this->assertStringContainsString('User.php', $html);
        $this->assertStringContainsString('Post.php', $html);

        // Uses <details>/<summary> for collapsible directories
        $this->assertStringContainsString('<details', $html);
        $this->assertStringContainsString('<summary', $html);
    }

    public function testChangeTypeIconsRendered(): void
    {
        $files = [
            ['file' => 'auto.php', 'changeType' => 'auto'],
            ['file' => 'review.php', 'changeType' => 'review'],
            ['file' => 'manual.php', 'changeType' => 'manual'],
        ];
        $html = $this->renderer->render($files);

        // Green, yellow, red icons per task AC
        $this->assertStringContainsString('🟢', $html);
        $this->assertStringContainsString('🟡', $html);
        $this->assertStringContainsString('🔴', $html);
    }

    public function testUnknownChangeTypeGetsDefaultIcon(): void
    {
        $files = [['file' => 'unknown.txt', 'changeType' => 'something-else']];
        $html = $this->renderer->render($files);

        $this->assertStringContainsString('📄', $html);
    }

    public function testSpecialCharactersInFileNamesAreEscaped(): void
    {
        $files = [['file' => 'app/views/<template>.php', 'changeType' => 'auto']];
        $html = $this->renderer->render($files);

        $this->assertStringNotContainsString('<<template>', $html);
        $this->assertStringContainsString('&lt;template&gt;', $html);
    }

    public function testDirectoriesSortedBeforeFiles(): void
    {
        $files = [
            ['file' => 'app/z-file.php', 'changeType' => 'auto'],
            ['file' => 'app/subdir/inner.php', 'changeType' => 'auto'],
        ];
        $html = $this->renderer->render($files);

        // subdir/ directory entry should appear before z-file.php file entry
        $subdirPos = strpos($html, 'subdir/');
        $filePos   = strpos($html, 'z-file.php');

        $this->assertNotFalse($subdirPos);
        $this->assertNotFalse($filePos);
        $this->assertLessThan($filePos, $subdirPos, 'Directories should be sorted before files');
    }

    public function testDeeplyNestedPath(): void
    {
        $files = [['file' => 'a/b/c/d/e/deep.php', 'changeType' => 'auto']];
        $html = $this->renderer->render($files);

        $this->assertStringContainsString('deep.php', $html);
        // Should have nested dir-children containers
        $this->assertGreaterThanOrEqual(5, substr_count($html, 'dir-children'));
    }

    public function testDuplicateFilesDoNotCrash(): void
    {
        // When the same file appears twice (e.g., from different sources)
        $files = [
            ['file' => 'app/User.php', 'changeType' => 'auto'],
            ['file' => 'app/User.php', 'changeType' => 'review'],
        ];
        $html = $this->renderer->render($files);

        // The second entry should overwrite the first in the tree
        $this->assertStringContainsString('User.php', $html);
    }

    public function testAccessibilityAttributes(): void
    {
        $files = [['file' => 'test.php', 'changeType' => 'auto']];
        $html = $this->renderer->render($files);

        $this->assertStringContainsString('role="button"', $html);
        $this->assertStringContainsString('tabindex="0"', $html);
        $this->assertStringContainsString('aria-label=', $html);
    }

    public function testChangeBadgePresentInFileEntry(): void
    {
        $files = [['file' => 'test.php', 'changeType' => 'review']];
        $html = $this->renderer->render($files);

        $this->assertStringContainsString('badge-review', $html);
        $this->assertStringContainsString('Review', $html);
    }

    public function testDirectoryIconPresent(): void
    {
        $files = [['file' => 'dir/file.php', 'changeType' => 'auto']];
        $html = $this->renderer->render($files);

        $this->assertStringContainsString('📁', $html);
    }
}
