<?php

declare(strict_types=1);

namespace Tests\Unit\Composer;

use AppContainer\Composer\CompatibilityChecker;
use AppContainer\Composer\Exception\CompatibilityDataException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AppContainer\Composer\CompatibilityChecker
 * @covers \AppContainer\Composer\PackageCompatibility
 */
final class CompatibilityCheckerTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/compat-checker-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDirectory($this->tmpDir);
    }

    public function testSupportedPackageReturnsTrue(): void
    {
        $file = $this->writeCompatFile([
            'vendor/pkg' => [
                'support' => true,
                'recommended_version' => '^2.0',
                'notes' => '',
            ],
        ]);

        $checker = new CompatibilityChecker($file);
        $result = $checker->check('vendor/pkg', '^1.0');

        self::assertTrue($result->support === true);
        self::assertSame('^2.0', $result->recommendedVersion);
        self::assertFalse($result->isBlocker());
        self::assertFalse($result->isUnknown());
    }

    public function testUnsupportedPackageIsBlocker(): void
    {
        $file = $this->writeCompatFile([
            'vendor/abandoned' => [
                'support' => false,
                'recommended_version' => null,
                'notes' => 'Abandoned package',
            ],
        ]);

        $checker = new CompatibilityChecker($file);
        $result = $checker->check('vendor/abandoned', '^1.0');

        self::assertFalse($result->support);
        self::assertTrue($result->isBlocker());
        self::assertFalse($result->isUnknown());
    }

    public function testUnknownSupportIsUnknown(): void
    {
        $file = $this->writeCompatFile([
            'vendor/mystery' => [
                'support' => 'unknown',
                'recommended_version' => null,
                'notes' => 'Check GitHub',
            ],
        ]);

        $checker = new CompatibilityChecker($file);
        $result = $checker->check('vendor/mystery', '^1.0');

        self::assertSame('unknown', $result->support);
        self::assertFalse($result->isBlocker());
        self::assertTrue($result->isUnknown());
    }

    public function testMissingPackageReturnsUnknown(): void
    {
        $file = $this->writeCompatFile([]);

        $checker = new CompatibilityChecker($file);
        $result = $checker->check('vendor/not-in-matrix', '^1.0');

        self::assertSame('unknown', $result->support);
        self::assertTrue($result->isUnknown());
        self::assertNull($result->recommendedVersion);
        self::assertStringContainsString('not found', $result->notes);
    }

    public function testIsBlockerHelperMethod(): void
    {
        $file = $this->writeCompatFile([
            'vendor/blocked' => [
                'support' => false,
                'recommended_version' => null,
                'notes' => '',
            ],
        ]);

        $checker = new CompatibilityChecker($file);
        self::assertTrue($checker->isBlocker('vendor/blocked'));
    }

    public function testMissingFileThrowsException(): void
    {
        $this->expectException(CompatibilityDataException::class);
        new CompatibilityChecker($this->tmpDir . '/nonexistent.json');
    }

    public function testInvalidJsonThrowsException(): void
    {
        $path = $this->tmpDir . '/bad.json';
        file_put_contents($path, '{not valid json');

        $this->expectException(CompatibilityDataException::class);
        $this->expectExceptionMessageMatches('/Invalid JSON/');
        new CompatibilityChecker($path);
    }

    public function testMissingPackagesKeyThrowsException(): void
    {
        $path = $this->tmpDir . '/no-packages.json';
        file_put_contents($path, json_encode(['generated' => '2025-01-01']));

        $this->expectException(CompatibilityDataException::class);
        $this->expectExceptionMessageMatches('/packages/');
        new CompatibilityChecker($path);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $packages
     */
    private function writeCompatFile(array $packages): string
    {
        $path = $this->tmpDir . '/compat.json';
        file_put_contents($path, json_encode([
            'generated' => '2025-01-01',
            'packages' => $packages,
        ]));
        return $path;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
