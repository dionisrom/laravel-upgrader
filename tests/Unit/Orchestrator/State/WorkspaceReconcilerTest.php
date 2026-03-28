<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator\State;

use App\Orchestrator\State\Checkpoint;
use App\Orchestrator\State\CheckpointNotResumableException;
use App\Orchestrator\State\FileHasher;
use App\Orchestrator\State\NoCheckpointException;
use App\Orchestrator\State\WorkspaceReconciler;
use PHPUnit\Framework\TestCase;

final class WorkspaceReconcilerTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/reconciler_test_' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testReconcileThrowsWhenNoCheckpoint(): void
    {
        $reconciler = new WorkspaceReconciler();

        $this->expectException(NoCheckpointException::class);
        $this->expectExceptionMessageMatches('/No checkpoint found at/');
        $this->expectExceptionMessageMatches('/Run without --resume/');

        $reconciler->reconcile(null, $this->tempDir);
    }

    public function testReconcileThrowsWhenCanResumeIsFalse(): void
    {
        $checkpoint = new Checkpoint(
            hop: '8_to_9',
            schemaVersion: '1',
            completedRules: ['App\\Rules\\Done'],
            pendingRules: ['App\\Rules\\Pending'],
            filesHashed: [],
            timestamp: '2026-03-21T14:30:00+00:00',
            canResume: false,
            hostVersion: '1.0.0',
        );

        $reconciler = new WorkspaceReconciler();

        $this->expectException(CheckpointNotResumableException::class);
        $this->expectExceptionMessageMatches('/not resumable/');

        $reconciler->reconcile($checkpoint, $this->tempDir);
    }

    public function testReconcileSkipsUnchangedFiles(): void
    {
        // Create a real file and hash it
        $file = $this->tempDir . '/app/Foo.php';
        mkdir(dirname($file), 0755, true);
        file_put_contents($file, '<?php class Foo {}');

        $hasher = new FileHasher();
        $hash = $hasher->hash($file);

        $checkpoint = new Checkpoint(
            hop: '8_to_9',
            schemaVersion: '1',
            completedRules: ['App\\Rules\\RuleA', 'App\\Rules\\RuleB'],
            pendingRules: ['App\\Rules\\RuleC'],
            filesHashed: ['app/Foo.php' => $hash],
            timestamp: '2026-03-21T14:30:00+00:00',
            canResume: true,
            hostVersion: '1.0.0',
        );

        $reconciler = new WorkspaceReconciler();
        $result = $reconciler->reconcile($checkpoint, $this->tempDir);

        $this->assertSame(['App\\Rules\\RuleC'], $result->pendingRules);
        $this->assertSame(['App\\Rules\\RuleA', 'App\\Rules\\RuleB'], $result->skippedRules);
        $this->assertSame([], $result->modifiedFiles);
        $this->assertFalse($result->hasModifiedFiles);
    }

    public function testReconcileDetectsModifiedFiles(): void
    {
        // Create a file, hash it, then modify it
        $file = $this->tempDir . '/app/Bar.php';
        mkdir(dirname($file), 0755, true);
        file_put_contents($file, '<?php class Bar {}');

        $hasher = new FileHasher();
        $originalHash = $hasher->hash($file);

        // Modify the file after capturing the hash
        file_put_contents($file, '<?php class Bar { public function modified() {} }');

        $checkpoint = new Checkpoint(
            hop: '8_to_9',
            schemaVersion: '1',
            completedRules: ['App\\Rules\\DoneRule'],
            pendingRules: [],
            filesHashed: ['app/Bar.php' => $originalHash],
            timestamp: '2026-03-21T14:30:00+00:00',
            canResume: true,
            hostVersion: '1.0.0',
        );

        $reconciler = new WorkspaceReconciler();
        $result = $reconciler->reconcile($checkpoint, $this->tempDir);

        $this->assertContains('app/Bar.php', $result->modifiedFiles);
        $this->assertTrue($result->hasModifiedFiles);
    }

    public function testReconcileDetectsMissingFiles(): void
    {
        // Use a hash for a file that doesn't exist
        $checkpoint = new Checkpoint(
            hop: '8_to_9',
            schemaVersion: '1',
            completedRules: ['App\\Rules\\Done'],
            pendingRules: ['App\\Rules\\Pending'],
            filesHashed: ['app/Missing.php' => 'sha256:' . str_repeat('a', 64)],
            timestamp: '2026-03-21T14:30:00+00:00',
            canResume: true,
            hostVersion: '1.0.0',
        );

        $reconciler = new WorkspaceReconciler();
        $result = $reconciler->reconcile($checkpoint, $this->tempDir);

        $this->assertContains('app/Missing.php', $result->modifiedFiles);
        $this->assertTrue($result->hasModifiedFiles);
    }

    public function testReconcileIsNoOpWhenWorkspaceUnchanged(): void
    {
        // Create multiple files, hash them all, reconcile immediately — should be clean
        $file1 = $this->tempDir . '/src/ClassA.php';
        $file2 = $this->tempDir . '/src/ClassB.php';
        mkdir(dirname($file1), 0755, true);
        file_put_contents($file1, '<?php class ClassA {}');
        file_put_contents($file2, '<?php class ClassB {}');

        $hasher = new FileHasher();
        $hash1 = $hasher->hash($file1);
        $hash2 = $hasher->hash($file2);

        $checkpoint = new Checkpoint(
            hop: '8_to_9',
            schemaVersion: '1',
            completedRules: [],
            pendingRules: ['App\\Rules\\Pending'],
            filesHashed: [
                'src/ClassA.php' => $hash1,
                'src/ClassB.php' => $hash2,
            ],
            timestamp: '2026-03-21T14:30:00+00:00',
            canResume: true,
            hostVersion: '1.0.0',
        );

        $reconciler = new WorkspaceReconciler();
        $result = $reconciler->reconcile($checkpoint, $this->tempDir);

        $this->assertSame([], $result->modifiedFiles);
        $this->assertFalse($result->hasModifiedFiles);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
