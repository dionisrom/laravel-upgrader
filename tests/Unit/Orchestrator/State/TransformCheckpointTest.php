<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator\State;

use App\Orchestrator\Hop;
use App\Orchestrator\State\TransformCheckpoint;
use PHPUnit\Framework\TestCase;

final class TransformCheckpointTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/checkpoint_test_' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testWriteCreatesCheckpointFile(): void
    {
        $checkpoint = new TransformCheckpoint($this->tempDir);
        $checkpoint->write(
            hop: '8_to_9',
            completedRules: ['App\\Rules\\RuleA'],
            pendingRules: ['App\\Rules\\RuleB'],
            filesHashed: ['app/Http/Controllers/Foo.php' => 'sha256:' . str_repeat('a', 64)],
        );

        $expectedPath = $this->tempDir . DIRECTORY_SEPARATOR . '.upgrader-state' . DIRECTORY_SEPARATOR . 'checkpoint.json';
        $this->assertFileExists($expectedPath);
    }

    public function testWriteIsAtomic(): void
    {
        $checkpoint = new TransformCheckpoint($this->tempDir);
        $checkpoint->write(
            hop: '8_to_9',
            completedRules: [],
            pendingRules: [],
            filesHashed: [],
        );

        $tmpPath = $this->tempDir . DIRECTORY_SEPARATOR . '.upgrader-state' . DIRECTORY_SEPARATOR . 'checkpoint.json.tmp';
        $this->assertFileDoesNotExist($tmpPath);
    }

    public function testReadReturnsCheckpoint(): void
    {
        $tc = new TransformCheckpoint($this->tempDir, '2.0.0');
        $filesHashed = ['app/Foo.php' => 'sha256:' . str_repeat('b', 64)];
        $tc->write(
            hop: '9_to_10',
            completedRules: ['App\\Rules\\Done'],
            pendingRules: ['App\\Rules\\Pending'],
            filesHashed: $filesHashed,
        );

        $result = $tc->read();

        $this->assertNotNull($result);
        $this->assertSame('9_to_10', $result->hop);
        $this->assertSame('1', $result->schemaVersion);
        $this->assertSame(['App\\Rules\\Done'], $result->completedRules);
        $this->assertSame(['App\\Rules\\Pending'], $result->pendingRules);
        $this->assertSame($filesHashed, $result->filesHashed);
        $this->assertTrue($result->canResume);
        $this->assertSame('2.0.0', $result->hostVersion);
    }

    public function testReadReturnsNullWhenNoFile(): void
    {
        $tc = new TransformCheckpoint($this->tempDir);
        $result = $tc->read();

        $this->assertNull($result);
    }

    public function testReadThrowsOnCorruptedJson(): void
    {
        $stateDir = $this->tempDir . DIRECTORY_SEPARATOR . '.upgrader-state';
        mkdir($stateDir, 0755, true);
        file_put_contents($stateDir . DIRECTORY_SEPARATOR . 'checkpoint.json', '{corrupted json!!!');

        $tc = new TransformCheckpoint($this->tempDir);

        $this->expectException(\JsonException::class);
        $tc->read();
    }

    public function testClearDeletesCheckpointFile(): void
    {
        $tc = new TransformCheckpoint($this->tempDir);
        $tc->write(hop: '8_to_9', completedRules: [], pendingRules: [], filesHashed: []);

        $expectedPath = $this->tempDir . DIRECTORY_SEPARATOR . '.upgrader-state' . DIRECTORY_SEPARATOR . 'checkpoint.json';
        $this->assertFileExists($expectedPath);

        $tc->clear();

        $this->assertFileDoesNotExist($expectedPath);
    }

    public function testIsCompletedReturnsTrueForMatchingHop(): void
    {
        $tc = new TransformCheckpoint($this->tempDir);
        $tc->write(hop: '8_to_9', completedRules: [], pendingRules: [], filesHashed: []);

        $hop = new Hop(
            dockerImage: 'laravel-upgrader/hop-8-to-9',
            fromVersion: '8',
            toVersion: '9',
            type: 'laravel',
            phpBase: null,
        );

        $this->assertTrue($tc->isCompleted($hop));
    }

    public function testIsCompletedReturnsFalseWhenNullCheckpoint(): void
    {
        $tc = new TransformCheckpoint($this->tempDir);

        $hop = new Hop(
            dockerImage: 'laravel-upgrader/hop-8-to-9',
            fromVersion: '8',
            toVersion: '9',
            type: 'laravel',
            phpBase: null,
        );

        $this->assertFalse($tc->isCompleted($hop));
    }

    public function testIsCompletedReturnsFalseWhenPendingRulesExist(): void
    {
        $tc = new TransformCheckpoint($this->tempDir);
        $tc->write(hop: '8_to_9', completedRules: ['App\\Rules\\A'], pendingRules: ['App\\Rules\\B'], filesHashed: []);

        $hop = new Hop(
            dockerImage: 'laravel-upgrader/hop-8-to-9',
            fromVersion: '8',
            toVersion: '9',
            type: 'laravel',
            phpBase: null,
        );

        $this->assertFalse($tc->isCompleted($hop));
    }

    public function testIsCompletedReturnsFalseForDifferentHop(): void
    {
        $tc = new TransformCheckpoint($this->tempDir);
        $tc->write(hop: '9_to_10', completedRules: [], pendingRules: [], filesHashed: []);

        $hop = new Hop(
            dockerImage: 'laravel-upgrader/hop-8-to-9',
            fromVersion: '8',
            toVersion: '9',
            type: 'laravel',
            phpBase: null,
        );

        $this->assertFalse($tc->isCompleted($hop));
    }

    public function testMarkCompletedClearsCheckpoint(): void
    {
        $tc = new TransformCheckpoint($this->tempDir);
        $tc->write(hop: '8_to_9', completedRules: [], pendingRules: [], filesHashed: []);

        $hop = new Hop(
            dockerImage: 'laravel-upgrader/hop-8-to-9',
            fromVersion: '8',
            toVersion: '9',
            type: 'laravel',
            phpBase: null,
        );

        $tc->markCompleted($hop);

        $this->assertNull($tc->read());
    }

    public function testWriteCanResumeFalseRoundTrips(): void
    {
        $tc = new TransformCheckpoint($this->tempDir);
        $tc->write(
            hop: '8_to_9',
            completedRules: ['App\\Rules\\A'],
            pendingRules: ['App\\Rules\\B'],
            filesHashed: [],
            canResume: false,
        );

        $result = $tc->read();
        $this->assertNotNull($result);
        $this->assertFalse($result->canResume);
    }

    public function testWriteThrowsForAbsolutePaths(): void
    {
        $tc = new TransformCheckpoint($this->tempDir);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/absolute path/');

        $tc->write(
            hop: '8_to_9',
            completedRules: [],
            pendingRules: [],
            filesHashed: ['/absolute/path.php' => 'sha256:' . str_repeat('a', 64)],
        );
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
