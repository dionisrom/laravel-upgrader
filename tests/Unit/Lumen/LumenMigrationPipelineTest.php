<?php

declare(strict_types=1);

namespace Tests\Unit\Lumen;

use AppContainer\Lumen\LumenMigrationPipeline;
use PHPUnit\Framework\TestCase;

final class LumenMigrationPipelineTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/lumen-pipeline-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        putenv('UPGRADER_EXTRA_COMPOSER_CACHE_DIR');
        $this->removeDirectory($this->tmpDir);
    }

    public function testShouldRunComposerInstallReturnsFalseWithoutLockFile(): void
    {
        $cacheDir = $this->tmpDir . '/cache';
        $targetDir = $this->tmpDir . '/target';
        mkdir($cacheDir, 0777, true);
        mkdir($targetDir, 0777, true);
        putenv('UPGRADER_EXTRA_COMPOSER_CACHE_DIR=' . $cacheDir);

        $pipeline = new LumenMigrationPipeline($this->tmpDir, $this->tmpDir, $this->tmpDir . '/rector.php');
        $method = new \ReflectionMethod($pipeline, 'shouldRunComposerInstall');
        $method->setAccessible(true);

        self::assertFalse($method->invoke($pipeline, $targetDir));
    }

    public function testShouldRunComposerInstallReturnsTrueWhenCacheAndLockExist(): void
    {
        $cacheDir = $this->tmpDir . '/cache';
        $targetDir = $this->tmpDir . '/target';
        mkdir($cacheDir, 0777, true);
        mkdir($targetDir, 0777, true);
        file_put_contents($targetDir . '/composer.lock', '{}');
        putenv('UPGRADER_EXTRA_COMPOSER_CACHE_DIR=' . $cacheDir);

        $pipeline = new LumenMigrationPipeline($this->tmpDir, $this->tmpDir, $this->tmpDir . '/rector.php');
        $method = new \ReflectionMethod($pipeline, 'shouldRunComposerInstall');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($pipeline, $targetDir));
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
                continue;
            }

            @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}