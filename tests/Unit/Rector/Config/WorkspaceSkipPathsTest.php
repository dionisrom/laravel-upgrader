<?php

declare(strict_types=1);

namespace Tests\Unit\Rector\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 4) . '/rector-configs/workspace-skip-paths.php';

final class WorkspaceSkipPathsTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'upgrader-skip-paths-' . uniqid('', true);
        mkdir($this->tempDir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    public function testMissingVendorDirectoryIsIgnored(): void
    {
        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'storage', 0700, true);

        $skipPaths = upgraderWorkspaceSkipPaths($this->tempDir);

        self::assertSame([$this->normalizePath($this->tempDir . DIRECTORY_SEPARATOR . 'storage')], $skipPaths);
        self::assertNotContains($this->normalizePath($this->tempDir . DIRECTORY_SEPARATOR . 'vendor'), $skipPaths);
    }

    public function testVendorDirectoryIsStillSkippedWhenPresent(): void
    {
        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'vendor', 0700, true);
        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'cache', 0700, true);

        $skipPaths = upgraderWorkspaceSkipPaths($this->tempDir);

        self::assertContains($this->normalizePath($this->tempDir . DIRECTORY_SEPARATOR . 'vendor'), $skipPaths);
        self::assertContains(
            $this->normalizePath($this->tempDir . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'cache'),
            $skipPaths,
        );
    }

    #[DataProvider('affectedConfigProvider')]
    public function testAffectedConfigsUseWorkspaceSkipHelper(string $configPath): void
    {
        $contents = file_get_contents($configPath);

        self::assertIsString($contents);
        self::assertStringContainsString('upgraderWorkspaceSkipPaths(', $contents);
        self::assertStringNotContainsString("->withSkipPath('/workspace/vendor')", $contents);
        self::assertStringNotContainsString('importDocBlockClassNames:', $contents);
    }

    #[DataProvider('hopConfigProvider')]
    public function testHopConfigsDisableRectorParallelism(string $configPath): void
    {
        $contents = file_get_contents($configPath);

        self::assertIsString($contents);
        self::assertStringContainsString('->withoutParallel()', $contents);
        self::assertStringNotContainsString('->withParallel(', $contents);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function affectedConfigProvider(): iterable
    {
        $repoRoot = dirname(__DIR__, 4);

        yield 'hop-8-to-9' => [$repoRoot . '/rector-configs/rector.l8-to-l9.php'];
        yield 'hop-9-to-10' => [$repoRoot . '/rector-configs/rector.l9-to-l10.php'];
        yield 'hop-10-to-11' => [$repoRoot . '/rector-configs/rector.l10-to-l11.php'];
        yield 'hop-11-to-12' => [$repoRoot . '/rector-configs/rector.l11-to-l12.php'];
        yield 'hop-12-to-13' => [$repoRoot . '/rector-configs/rector.l12-to-l13.php'];
        yield 'package-livewire' => [$repoRoot . '/rector-configs/packages/rector.livewire-v2-v3.php'];
        yield 'package-filament' => [$repoRoot . '/rector-configs/packages/rector.filament-v2-v3.php'];
        yield 'package-medialibrary' => [$repoRoot . '/rector-configs/packages/rector.spatie-medialibrary-v9-v10.php'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hopConfigProvider(): iterable
    {
        $repoRoot = dirname(__DIR__, 4);

        yield 'hop-8-to-9' => [$repoRoot . '/rector-configs/rector.l8-to-l9.php'];
        yield 'hop-9-to-10' => [$repoRoot . '/rector-configs/rector.l9-to-l10.php'];
        yield 'hop-10-to-11' => [$repoRoot . '/rector-configs/rector.l10-to-l11.php'];
        yield 'hop-11-to-12' => [$repoRoot . '/rector-configs/rector.l11-to-l12.php'];
        yield 'hop-12-to-13' => [$repoRoot . '/rector-configs/rector.l12-to-l13.php'];
    }

    #[DataProvider('hopComposerProvider')]
    public function testHopComposerFilesAutoloadAppContainerNamespace(string $composerPath): void
    {
        $contents = file_get_contents($composerPath);

        self::assertIsString($contents);
        self::assertStringContainsString('"AppContainer\\\\": "src/"', $contents);
        self::assertStringNotContainsString('"Upgrader\\\\": "src/"', $contents);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hopComposerProvider(): iterable
    {
        $repoRoot = dirname(__DIR__, 4);

        yield 'hop-8-to-9' => [$repoRoot . '/docker/hop-8-to-9/composer.hop-8-to-9.json'];
        yield 'hop-9-to-10' => [$repoRoot . '/docker/hop-9-to-10/composer.hop-9-to-10.json'];
        yield 'hop-10-to-11' => [$repoRoot . '/docker/hop-10-to-11/composer.hop-10-to-11.json'];
        yield 'hop-11-to-12' => [$repoRoot . '/docker/hop-11-to-12/composer.hop-11-to-12.json'];
        yield 'hop-12-to-13' => [$repoRoot . '/docker/hop-12-to-13/composer.hop-12-to-13.json'];
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $currentPath = $path . DIRECTORY_SEPARATOR . $item;

            if (is_dir($currentPath)) {
                $this->removeDirectory($currentPath);

                continue;
            }

            unlink($currentPath);
        }

        rmdir($path);
    }
}