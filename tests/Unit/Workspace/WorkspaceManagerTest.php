<?php

declare(strict_types=1);

namespace Tests\Unit\Workspace;

use App\Orchestrator\State\TransformCheckpoint;
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

    public function testCreateWorkspaceThrowsConcurrentUpgradeExceptionWhenLockHeld(): void
    {
        $repoPath = $this->makeFixtureRepo();

        // First workspace acquires the lock
        $workspace1 = $this->manager->createWorkspace($repoPath, '10');

        // Second manager instance trying the same repo should fail
        $manager2 = new WorkspaceManager();

        $this->expectException(\App\Workspace\Exception\ConcurrentUpgradeException::class);
        $this->expectExceptionMessage('Another upgrade is already running for this repository.');

        try {
            $manager2->createWorkspace($repoPath, '10');
        } finally {
            $this->manager->cleanup($workspace1);
        }
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
                'diff' => $this->makeUnifiedDiff("<?php\nclass Foo {}\n", "<?php\nclass Foo\n{\n}\n", 'app/Foo.php'),
                'appliedRectors' => ['Rector\\CodingStyle\\Rector\\Class_\\AddBracesRector'],
            ],
        ];

        $result = $this->manager->applyDiffs($workspacePath, $diffs);

        $this->assertInstanceOf(ApplyResult::class, $result);
        $this->assertSame(1, $result->appliedCount);
        $this->assertSame(0, $result->failedCount);
        $this->assertStringContainsString("class Foo\n{\n}\n", (string) file_get_contents($workspacePath . '/app/Foo.php'));

        $this->manager->cleanup($workspacePath);
    }

    public function testApplyDiffsCreatesNewFileInMissingNestedDirectory(): void
    {
        $workspacePath = $this->tempBase . '/ws-' . uniqid('', true);
        mkdir($workspacePath, 0700, true);

        $newContent = "<?php\nreturn true;\n";
        $diffs = [
            [
                'file' => 'app/Nested/NewFile.php',
                'diff' => $this->makeUnifiedDiff('', $newContent, 'app/Nested/NewFile.php'),
                'appliedRectors' => ['CreateNestedFileRector'],
            ],
        ];

        $result = $this->manager->applyDiffs($workspacePath, $diffs);

        $this->assertSame(1, $result->appliedCount);
        $this->assertSame(0, $result->failedCount);
        $this->assertFileExists($workspacePath . '/app/Nested/NewFile.php');
        $this->assertSame($newContent, file_get_contents($workspacePath . '/app/Nested/NewFile.php'));

        $this->manager->cleanup($workspacePath);
    }

    public function testApplyDiffsEmitsFileChangedEvents(): void
    {
        $events = [];
        $manager = new WorkspaceManager(null, null, static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $workspacePath = $this->makeWorkspaceWithFile('app/Foo.php', "<?php\necho 1;\n");

        $diffs = [
            [
                'file' => 'app/Foo.php',
                'diff' => $this->makeUnifiedDiff("<?php\necho 1;\n", "<?php\necho 2;\n", 'app/Foo.php'),
                'appliedRectors' => ['UpdateEchoRector'],
            ],
        ];

        $manager->applyDiffs($workspacePath, $diffs);

        $this->assertCount(1, $events);
        $this->assertSame('file_changed', $events[0]['event']);
        $this->assertSame('app/Foo.php', $events[0]['file']);
        $this->assertSame(['UpdateEchoRector'], $events[0]['rules']);

        $manager->cleanup($workspacePath);
    }

    public function testApplyDiffsEmitsPipelineErrorOnSyntaxFailure(): void
    {
        $events = [];
        $manager = new WorkspaceManager(null, null, static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $workspacePath = $this->makeWorkspaceWithFile('app/Bad.php', "<?php\necho 'ok';\n");

        $badDiff = "--- a/app/Bad.php\n+++ b/app/Bad.php\n@@ -1,2 +1,2 @@\n <?php\n-echo 'ok';\n+THIS IS NOT VALID PHP <??\n";

        $diffs = [
            ['file' => 'app/Bad.php', 'diff' => $badDiff, 'appliedRectors' => []],
        ];

        $manager->applyDiffs($workspacePath, $diffs);

        $this->assertCount(1, $events);
        $this->assertSame('pipeline_error', $events[0]['event']);
        $this->assertSame('app/Bad.php', $events[0]['file']);

        $manager->cleanup($workspacePath);
    }

    public function testApplyDiffsWritesTransformCheckpointForSuccessfulFile(): void
    {
        $workspacePath = $this->makeWorkspaceWithFile('app/Foo.php', "<?php\necho 1;\n");

        $diffs = [
            [
                'file' => 'app/Foo.php',
                'diff' => $this->makeUnifiedDiff("<?php\necho 1;\n", "<?php\necho 2;\n", 'app/Foo.php'),
                'appliedRectors' => ['UpdateEchoRector'],
            ],
        ];

        $result = $this->manager->applyDiffs($workspacePath, $diffs);
        $checkpoint = (new TransformCheckpoint($workspacePath))->read();

        $this->assertSame(1, $result->appliedCount);
        $this->assertNotNull($checkpoint);
        $this->assertSame('workspace_apply', $checkpoint->hop);
        $this->assertSame(['UpdateEchoRector'], $checkpoint->completedRules);
        $this->assertArrayHasKey('app/Foo.php', $checkpoint->filesHashed);
        $this->assertSame(
            'sha256:' . hash('sha256', (string) file_get_contents($workspacePath . '/app/Foo.php')),
            $checkpoint->filesHashed['app/Foo.php']
        );

        $this->manager->cleanup($workspacePath);
    }

    public function testApplyDiffsHaltsWhenCheckpointUpdateFailsAndKeepsLastValidCheckpoint(): void
    {
        $workspacePath = $this->makeWorkspaceWithFile('app/Foo.php', "<?php\necho 1;\n");
        $checkpoint = new TransformCheckpoint($workspacePath);
        $checkpoint->write('workspace_apply', ['ExistingRule'], [], ['app/Existing.php' => 'sha256:abc']);

        $events = [];
        $manager = new WorkspaceManager(
            null,
            static function (string $_workspacePath, string $_relativeFile, array $_appliedRectors, string $_absolutePath): void {
                throw new \RuntimeException('checkpoint write failed');
            },
            static function (array $event) use (&$events): void {
                $events[] = $event;
            }
        );

        $diffs = [
            [
                'file' => 'app/Foo.php',
                'diff' => $this->makeUnifiedDiff("<?php\necho 1;\n", "<?php\necho 2;\n", 'app/Foo.php'),
                'appliedRectors' => ['UpdateEchoRector'],
            ],
            [
                'file' => 'app/Bar.php',
                'diff' => $this->makeUnifiedDiff('', "<?php\necho 3;\n", 'app/Bar.php'),
                'appliedRectors' => ['CreateBarRector'],
            ],
        ];

        $result = $manager->applyDiffs($workspacePath, $diffs);

        $persistedCheckpoint = (new TransformCheckpoint($workspacePath))->read();

        $this->assertSame(1, $result->failedCount);
        $this->assertSame('app/Foo.php', $result->failedFile);
        $this->assertNotNull($persistedCheckpoint);
        $this->assertSame(['ExistingRule'], $persistedCheckpoint->completedRules);
        $this->assertSame(['app/Existing.php' => 'sha256:abc'], $persistedCheckpoint->filesHashed);
        $this->assertFileDoesNotExist($workspacePath . '/app/Bar.php');

        $pipelineErrors = array_filter($events, fn(array $e) => $e['event'] === 'pipeline_error');
        $fileChanged = array_filter($events, fn(array $e) => $e['event'] === 'file_changed');
        $this->assertCount(1, $pipelineErrors);
        $this->assertCount(0, $fileChanged);

        $this->manager->cleanup($workspacePath);
    }

    public function testCreateWorkspaceUsesConfiguredPathNormalizer(): void
    {
        $repoPath = $this->makeFixtureRepo();
        $windowsPath = 'C:\\projects\\demo-repo';

        $manager = new WorkspaceManager(
            static function (string $path) use ($repoPath): string {
                $normalized = str_replace('\\', '/', $path);

                return $normalized === 'C:/projects/demo-repo'
                    ? str_replace('\\', '/', $repoPath)
                    : $normalized;
            }
        );

        $workspacePath = $manager->createWorkspace($windowsPath, '10');

        $this->assertDirectoryExists($workspacePath);

        $manager->cleanup($workspacePath);
    }

    public function testCreateWorkspaceSetsSecurePermissionsOnNonWindows(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX permission bits are not reliable on Windows.');
        }

        $repoPath = $this->makeFixtureRepo();
        $workspacePath = $this->manager->createWorkspace($repoPath, '10');

        $this->assertSame(0700, fileperms($workspacePath) & 0777);

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

    public function testWriteBackRemovesStaleFilesFromOriginalRepo(): void
    {
        $repoPath = $this->makeFixtureRepo();
        file_put_contents($repoPath . '/keep.php', "<?php // keep\n");
        file_put_contents($repoPath . '/stale.php', "<?php // stale\n");

        $workspacePath = $this->manager->createWorkspace($repoPath, '10');

        // Delete stale.php in workspace (simulating a rename/removal during upgrade)
        unlink($workspacePath . '/stale.php');

        $this->manager->writeBack($workspacePath, $repoPath);

        $this->assertFileExists($repoPath . '/keep.php');
        $this->assertFileDoesNotExist($repoPath . '/stale.php');

        $this->manager->cleanup($workspacePath);
    }

    public function testWriteBackPreservesGitDirectory(): void
    {
        $repoPath = $this->makeFixtureRepo();
        mkdir($repoPath . '/.git', 0700, true);
        file_put_contents($repoPath . '/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($repoPath . '/app/Model.php', "<?php\n");

        $workspacePath = $this->manager->createWorkspace($repoPath, '10');

        // Workspace won't have .git (it's a copy of repo files, .git may or may not be copied)
        // Remove .git from workspace if it was copied to simulate typical behavior
        if (is_dir($workspacePath . '/.git')) {
            $this->removeDirectory($workspacePath . '/.git');
        }

        $this->manager->writeBack($workspacePath, $repoPath);

        // .git must survive write-back
        $this->assertFileExists($repoPath . '/.git/HEAD');

        $this->manager->cleanup($workspacePath);
    }

    public function testWriteBackPreservesVendorDirectoryContents(): void
    {
        $repoPath = $this->makeFixtureRepo();
        mkdir($repoPath . '/vendor/package', 0700, true);
        file_put_contents($repoPath . '/vendor/package/original.txt', "keep\n");

        $workspacePath = $this->manager->createWorkspace($repoPath, '10');
        mkdir($workspacePath . '/vendor/package', 0700, true);
        file_put_contents($workspacePath . '/vendor/package/original.txt', "replace\n");
        file_put_contents($workspacePath . '/vendor/package/new.txt', "new\n");
        file_put_contents($workspacePath . '/app/Changed.php', "<?php\n");

        $this->manager->writeBack($workspacePath, $repoPath);

        $this->assertSame("keep\n", file_get_contents($repoPath . '/vendor/package/original.txt'));
        $this->assertFileDoesNotExist($repoPath . '/vendor/package/new.txt');
        $this->assertFileExists($repoPath . '/app/Changed.php');

        $this->manager->cleanup($workspacePath);
    }

    public function testWriteBackSupportsWindowsShortWorkspacePaths(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('DOS short path regression only applies on Windows.');
        }

        $repoPath = $this->makeFixtureRepo();
        $workspacePath = $this->manager->createWorkspace($repoPath, '10');

        $nestedDir = $workspacePath . '/app/Nested/Deep';
        mkdir($nestedDir, 0700, true);
        file_put_contents($nestedDir . '/CLAUDE.md', "copied\n");

        $shortWorkspacePath = $this->toWindowsShortPath($workspacePath);
        if ($shortWorkspacePath === null || strcasecmp($shortWorkspacePath, $workspacePath) === 0) {
            $this->markTestSkipped('8.3 short path is not available on this Windows volume.');
        }

        $this->manager->writeBack($shortWorkspacePath, $repoPath);

        $this->assertFileExists($repoPath . '/app/Nested/Deep/CLAUDE.md');
        $this->assertSame("copied\n", file_get_contents($repoPath . '/app/Nested/Deep/CLAUDE.md'));

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
    private function makeUnifiedDiff(string $original, string $new, string $filename = 'file'): string
    {
        $generator = new \App\Workspace\DiffGenerator();
        return $generator->generateUnifiedDiff($original, $new, $filename);
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

    private function toWindowsShortPath(string $path): ?string
    {
        $escapedPath = str_replace('"', '""', $path);
        $output = shell_exec('cmd /c for %I in ("' . $escapedPath . '") do @echo %~sI');
        if (!is_string($output)) {
            return null;
        }

        $shortPath = trim($output);

        return $shortPath !== '' ? $shortPath : null;
    }
}
