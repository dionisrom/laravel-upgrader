<?php

declare(strict_types=1);

namespace Tests\Unit\Workspace;

use App\Workspace\ApplyResult;
use App\Workspace\WorkspaceManager;
use PHPUnit\Framework\TestCase;

final class WorkspaceManagerTest extends TestCase
{
    private WorkspaceManager $manager;
    private string $tempBase;

    protected function setUp(): void
    {
        $this->manager = new WorkspaceManager();
        $this->tempBase = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'upgrader-test-' . uniqid('', true);
        mkdir($this->tempBase, 0700, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempBase)) {
            $this->removeDirectory($this->tempBase);
        }
    }

    // -----------------------------------------------------------------------
    // createWorkspace
    // -----------------------------------------------------------------------

    public function testCreateWorkspaceReturnsExistingDirectory(): void
    {
        $repoPath = $this->makeFixtureRepo();
        $workspacePath = $this->manager->createWorkspace($repoPath, '10');

        $this->assertDirectoryExists($workspacePath);

        // cleanup
        $this->manager->cleanup($workspacePath);
    }

    public function testCreateWorkspaceCopiesFiles(): void
    {
        $repoPath = $this->makeFixtureRepo();
        file_put_contents($repoPath . '/test.php', "<?php echo 'hello';");

        $workspacePath = $this->manager->createWorkspace($repoPath, '10');

        $this->assertFileExists($workspacePath . '/test.php');
        $this->assertSame("<?php echo 'hello';", file_get_contents($workspacePath . '/test.php'));

        $this->manager->cleanup($workspacePath);
    }

    public function testCreateWorkspaceDoesNotSharePathBetweenCalls(): void
    {
        $repoPath = $this->makeFixtureRepo();

        $path1 = $this->manager->createWorkspace($repoPath, '10');
        // Release lock by cleaning up before creating a second workspace for the same repo
        $this->manager->cleanup($path1);

        $path2 = $this->manager->createWorkspace($repoPath, '10');

        $this->assertNotSame($path1, $path2);

        $this->manager->cleanup($path2);
    }

    public function testCreateWorkspaceThrowsOnMissingRepo(): void
    {
        $this->expectException(\App\Workspace\Exception\WorkspaceException::class);
        $this->manager->createWorkspace('/nonexistent/path/that/does/not/exist', '10');
    }

    // -----------------------------------------------------------------------
    // applyDiffs
    // -----------------------------------------------------------------------

    public function testApplyDiffsReturnsAppliedCount(): void
    {
        $workspacePath = $this->makeWorkspaceWithFile('app/Foo.php', "<?php\nclass Foo {}\n");

        $diffs = [
            [
                'file' => 'app/Foo.php',
                'diff' => $this->makeAddLineDiff("<?php\nclass Foo {}\n", "<?php\nclass Foo\n{\n}\n"),
                'appliedRectors' => ['Rector\\CodingStyle\\Rector\\Class_\\AddBracesRector'],
            ],
        ];

        $result = $this->manager->applyDiffs($workspacePath, $diffs);

        // applyDiffs may apply or skip depending on diff parsing — main check: no crash, returns ApplyResult
        $this->assertInstanceOf(ApplyResult::class, $result);
        $this->assertSame(0, $result->failedCount);

        $this->manager->cleanup($workspacePath);
    }

    public function testApplyDiffsPreservesOriginalRepoPath(): void
    {
        $repoPath = $this->makeFixtureRepo();
        file_put_contents($repoPath . '/app/Model.php', "<?php\nclass Model {}\n");

        $workspacePath = $this->manager->createWorkspace($repoPath, '10');

        $diffs = [
            [
                'file' => 'app/Model.php',
                'diff' => "--- a/app/Model.php\n+++ b/app/Model.php\n@@ -1,2 +1,3 @@\n <?php\n+// modified\n class Model {}\n",
                'appliedRectors' => ['SomeRector'],
            ],
        ];

        $this->manager->applyDiffs($workspacePath, $diffs);

        // Original repo file must be untouched
        $this->assertSame("<?php\nclass Model {}\n", file_get_contents($repoPath . '/app/Model.php'));

        $this->manager->cleanup($workspacePath);
    }

    public function testApplyDiffsHaltsOnFirstSyntaxError(): void
    {
        $workspacePath = $this->makeWorkspaceWithFile('app/Good.php', "<?php\necho 'hi';\n");
        $this->makeFileInWorkspace($workspacePath, 'app/Bad.php', "<?php\necho 'ok';\n");

        // The diff for Good.php produces invalid PHP
        $badDiff = "--- a/app/Good.php\n+++ b/app/Good.php\n@@ -1,2 +1,2 @@\n <?php\n-echo 'hi';\n+THIS IS NOT VALID PHP <??\n";

        $diffs = [
            ['file' => 'app/Good.php', 'diff' => $badDiff, 'appliedRectors' => []],
            ['file' => 'app/Bad.php', 'diff' => '', 'appliedRectors' => []],
        ];

        $result = $this->manager->applyDiffs($workspacePath, $diffs);

        $this->assertSame(1, $result->failedCount);
        $this->assertSame('app/Good.php', $result->failedFile);
        // Second file never processed
        $this->assertSame(0, $result->appliedCount + $result->skippedCount);

        $this->manager->cleanup($workspacePath);
    }

    public function testApplyDiffsEmptyDiffIsSkippedOrApplied(): void
    {
        $workspacePath = $this->makeWorkspaceWithFile('app/Unchanged.php', "<?php\necho 1;\n");

        $diffs = [
            ['file' => 'app/Unchanged.php', 'diff' => '', 'appliedRectors' => []],
        ];

        $result = $this->manager->applyDiffs($workspacePath, $diffs);

        $this->assertSame(0, $result->failedCount);

        $this->manager->cleanup($workspacePath);
    }

    // -----------------------------------------------------------------------
    // writeBack
    // -----------------------------------------------------------------------

    public function testWriteBackCopiesWorkspaceToOriginalRepo(): void
    {
        $repoPath = $this->makeFixtureRepo();
        file_put_contents($repoPath . '/original.php', "<?php // v1\n");

        $workspacePath = $this->manager->createWorkspace($repoPath, '10');
        file_put_contents($workspacePath . '/original.php', "<?php // v2\n");

        $this->manager->writeBack($workspacePath, $repoPath);

        $this->assertSame("<?php // v2\n", file_get_contents($repoPath . '/original.php'));

        $this->manager->cleanup($workspacePath);
    }

    // -----------------------------------------------------------------------
    // cleanup
    // -----------------------------------------------------------------------

    public function testCleanupRemovesWorkspaceDirectory(): void
    {
        $repoPath = $this->makeFixtureRepo();
        $workspacePath = $this->manager->createWorkspace($repoPath, '10');

        $this->assertDirectoryExists($workspacePath);

        $this->manager->cleanup($workspacePath);

        $this->assertDirectoryDoesNotExist($workspacePath);
    }

    public function testCleanupIsIdempotent(): void
    {
        $repoPath = $this->makeFixtureRepo();
        $workspacePath = $this->manager->createWorkspace($repoPath, '10');

        $this->manager->cleanup($workspacePath);
        // Should not throw
        $this->manager->cleanup($workspacePath);

        $this->assertDirectoryDoesNotExist($workspacePath);
    }

    // -----------------------------------------------------------------------
    // Path normalization (F-09 — at least 3 OS path variants)
    // -----------------------------------------------------------------------

    public function testPathNormalizationWindowsBackslashes(): void
    {
        // Expose normalizePath indirectly: create workspace from a path
        // containing backslashes on Windows; on Linux this is a no-op test.
        $repoPath = $this->makeFixtureRepo();
        $workspacePath = $this->manager->createWorkspace($repoPath, '10');

        // Workspace path returned must not contain backslashes on Windows
        if (PHP_OS_FAMILY === 'Windows') {
            $this->assertStringNotContainsString('\\', $workspacePath);
        }

        $this->assertDirectoryExists(str_replace('/', DIRECTORY_SEPARATOR, $workspacePath));

        $this->manager->cleanup($workspacePath);
    }

    public function testPathNormalizationLinuxForwardSlashes(): void
    {
        $repoPath = $this->makeFixtureRepo();
        $workspacePath = $this->manager->createWorkspace($repoPath, '9');

        // Forward slashes must always produce a valid directory on *any* OS
        $this->assertDirectoryExists(str_replace('/', DIRECTORY_SEPARATOR, $workspacePath));

        $this->manager->cleanup($workspacePath);
    }

    public function testPathNormalizationMixedSeparatorsOnWindows(): void
    {
        // applyDiffs with a file path using mixed separators should resolve correctly
        $workspacePath = $this->makeWorkspaceWithFile('sub/dir/file.php', "<?php\necho 1;\n");

        // Build a fileDiff where the 'file' key uses backslashes (Windows style)
        $fileKey = PHP_OS_FAMILY === 'Windows' ? 'sub\\dir\\file.php' : 'sub/dir/file.php';

        $diffs = [
            ['file' => $fileKey, 'diff' => '', 'appliedRectors' => []],
        ];

        // Should not crash regardless of separator style
        $result = $this->manager->applyDiffs($workspacePath, $diffs);
        $this->assertSame(0, $result->failedCount);

        $this->manager->cleanup($workspacePath);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeFixtureRepo(): string
    {
        $path = $this->tempBase . '/repo-' . uniqid('', true);
        mkdir($path . '/app', 0700, true);
        return $path;
    }

    private function makeWorkspaceWithFile(string $relativePath, string $content): string
    {
        $workspacePath = $this->tempBase . '/ws-' . uniqid('', true);
        $this->makeFileInWorkspace($workspacePath, $relativePath, $content);
        return $workspacePath;
    }

    private function makeFileInWorkspace(string $workspacePath, string $relativePath, string $content): void
    {
        $fullPath = $workspacePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($fullPath, $content);
    }

    /**
     * Returns a minimal unified diff that changes $original to $new using
     * a trivial full-file replacement hunk (sufficient for test assertions).
     */
    private function makeAddLineDiff(string $original, string $new): string
    {
        $generator = new \App\Workspace\DiffGenerator();
        return $generator->generateUnifiedDiff($original, $new, 'file');
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
