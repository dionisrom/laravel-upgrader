<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator\State;

use App\Orchestrator\State\FileHasher;
use PHPUnit\Framework\TestCase;

final class FileHasherTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/filehasher_test_' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testHashFormat(): void
    {
        $file = $this->tempDir . '/test.php';
        file_put_contents($file, '<?php echo "hello";');

        $hasher = new FileHasher();
        $hash = $hasher->hash($file);

        $this->assertStringStartsWith('sha256:', $hash);
        // "sha256:" = 7 chars, 64 hex chars = 71 total
        $this->assertSame(71, strlen($hash));
        $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $hash);
    }

    public function testHashIsDeterministic(): void
    {
        $file = $this->tempDir . '/deterministic.php';
        file_put_contents($file, '<?php class Foo {}');

        $hasher = new FileHasher();
        $hash1 = $hasher->hash($file);
        $hash2 = $hasher->hash($file);

        $this->assertSame($hash1, $hash2);
    }

    public function testHashDiffersForDifferentContent(): void
    {
        $file1 = $this->tempDir . '/file1.php';
        $file2 = $this->tempDir . '/file2.php';
        file_put_contents($file1, '<?php echo "content A";');
        file_put_contents($file2, '<?php echo "content B";');

        $hasher = new FileHasher();
        $hash1 = $hasher->hash($file1);
        $hash2 = $hasher->hash($file2);

        $this->assertNotSame($hash1, $hash2);
    }

    public function testHashManyReturnsAllPaths(): void
    {
        $file1 = $this->tempDir . '/many1.php';
        $file2 = $this->tempDir . '/many2.php';
        $file3 = $this->tempDir . '/many3.php';
        file_put_contents($file1, 'content 1');
        file_put_contents($file2, 'content 2');
        file_put_contents($file3, 'content 3');

        $paths = [$file1, $file2, $file3];
        $hasher = new FileHasher();
        $result = $hasher->hashMany($paths);

        $this->assertCount(3, $result);
        $this->assertArrayHasKey($file1, $result);
        $this->assertArrayHasKey($file2, $result);
        $this->assertArrayHasKey($file3, $result);

        foreach ($result as $hash) {
            $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $hash);
        }
    }

    public function testHashThrowsForMissingFile(): void
    {
        $hasher = new FileHasher();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/File not found/');

        $hasher->hash($this->tempDir . '/does_not_exist.php');
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
