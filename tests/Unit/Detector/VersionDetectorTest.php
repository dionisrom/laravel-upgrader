<?php

declare(strict_types=1);

namespace Tests\Unit\Detector;

use AppContainer\Detector\Exception\DetectionException;
use AppContainer\Detector\Exception\InvalidHopException;
use AppContainer\Detector\VersionDetector;
use PHPUnit\Framework\TestCase;

final class VersionDetectorTest extends TestCase
{
    private VersionDetector $detector;

    private string $fixturesDir;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector    = new VersionDetector();
        $this->fixturesDir = dirname(__DIR__, 2) . '/Fixtures';
        $this->tempDir     = sys_get_temp_dir() . '/upgrader-tests-' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->removeDir($this->tempDir);
    }

    // -------------------------------------------------
    // detectLaravelVersion
    // -------------------------------------------------

    public function testDetectsLaravel8Version(): void
    {
        $workspace = $this->buildWorkspaceFromFixture('composer-laravel8.lock');

        $version = $this->detector->detectLaravelVersion($workspace);

        self::assertSame('8.83.27', $version);
    }

    public function testDetectsLaravel9Version(): void
    {
        $workspace = $this->buildWorkspaceFromFixture('composer-laravel9.lock');

        $version = $this->detector->detectLaravelVersion($workspace);

        self::assertSame('9.52.15', $version);
    }

    public function testDetectsLumenVersionFromLumenPackage(): void
    {
        $workspace = $this->buildWorkspaceFromFixture('composer-lumen8.lock');

        $version = $this->detector->detectLaravelVersion($workspace);

        self::assertSame('8.3.4', $version);
    }

    public function testDetectsLumen9VersionFromFixture(): void
    {
        $workspace = $this->buildWorkspaceFromFixture('composer-lumen9.lock');

        $version = $this->detector->detectLaravelVersion($workspace);

        self::assertSame('9.4.2', $version);
    }

    public function testThrowsInvalidHopExceptionForLaravel10(): void
    {
        $workspace = $this->tempDir . '/l10-workspace';
        mkdir($workspace, 0777, true);

        file_put_contents(
            $workspace . '/composer.lock',
            json_encode([
                'packages' => [
                    ['name' => 'laravel/framework', 'version' => 'v10.48.0'],
                ],
            ])
        );

        $this->expectException(InvalidHopException::class);
        $this->expectExceptionMessage('Detected Laravel 10.x');

        $this->detector->detectLaravelVersion($workspace);
    }

    public function testThrowsInvalidHopExceptionForLaravel11(): void
    {
        $workspace = $this->tempDir . '/l11-workspace';
        mkdir($workspace, 0777, true);

        file_put_contents(
            $workspace . '/composer.lock',
            json_encode([
                'packages' => [
                    ['name' => 'laravel/framework', 'version' => 'v11.0.0'],
                ],
            ])
        );

        $this->expectException(InvalidHopException::class);

        $this->detector->detectLaravelVersion($workspace);
    }

    public function testThrowsDetectionExceptionWhenLockFileMissing(): void
    {
        $this->expectException(DetectionException::class);
        $this->expectExceptionMessage('composer.lock not found');

        $this->detector->detectLaravelVersion($this->tempDir . '/no-such-dir');
    }

    public function testThrowsDetectionExceptionWhenNoLaravelPackageFound(): void
    {
        $workspace = $this->tempDir . '/no-laravel-workspace';
        mkdir($workspace, 0777, true);

        file_put_contents(
            $workspace . '/composer.lock',
            json_encode([
                'packages' => [
                    ['name' => 'symfony/console', 'version' => 'v6.4.0'],
                ],
            ])
        );

        $this->expectException(DetectionException::class);
        $this->expectExceptionMessage('Neither laravel/framework nor laravel/lumen-framework');

        $this->detector->detectLaravelVersion($workspace);
    }

    // -------------------------------------------------
    // detectPhpConstraint
    // -------------------------------------------------

    public function testDetectsPhpConstraint(): void
    {
        $workspace = $this->tempDir . '/php-workspace';
        mkdir($workspace, 0777, true);

        file_put_contents(
            $workspace . '/composer.json',
            json_encode(['require' => ['php' => '^8.0']])
        );

        $constraint = $this->detector->detectPhpConstraint($workspace);

        self::assertSame('^8.0', $constraint);
    }

    public function testDetectsGeqPhpConstraint(): void
    {
        $workspace = $this->tempDir . '/php-geq-workspace';
        mkdir($workspace, 0777, true);

        file_put_contents(
            $workspace . '/composer.json',
            json_encode(['require' => ['php' => '>=8.1']])
        );

        $constraint = $this->detector->detectPhpConstraint($workspace);

        self::assertSame('>=8.1', $constraint);
    }

    public function testThrowsDetectionExceptionWhenComposerJsonMissing(): void
    {
        $this->expectException(DetectionException::class);
        $this->expectExceptionMessage('composer.json not found');

        $this->detector->detectPhpConstraint($this->tempDir . '/no-such-dir');
    }

    public function testThrowsDetectionExceptionWhenPhpConstraintMissing(): void
    {
        $workspace = $this->tempDir . '/no-php-workspace';
        mkdir($workspace, 0777, true);

        file_put_contents(
            $workspace . '/composer.json',
            json_encode(['require' => ['laravel/framework' => '^8.0']])
        );

        $this->expectException(DetectionException::class);
        $this->expectExceptionMessage('No PHP version constraint');

        $this->detector->detectPhpConstraint($workspace);
    }

    // -------------------------------------------------
    // Helpers
    // -------------------------------------------------

    private function buildWorkspaceFromFixture(string $lockFilename): string
    {
        $workspace = $this->tempDir . '/' . $lockFilename;
        mkdir($workspace, 0777, true);

        copy(
            $this->fixturesDir . '/' . $lockFilename,
            $workspace . '/composer.lock'
        );

        // Provide a composer.json with PHP constraint so detectPhpConstraint works too
        file_put_contents(
            $workspace . '/composer.json',
            json_encode(['require' => ['php' => '^8.0']])
        );

        return $workspace;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
