<?php

declare(strict_types=1);

namespace Tests\Unit\Detector;

use AppContainer\Detector\Exception\DetectionException;
use AppContainer\Detector\FrameworkDetector;
use PHPUnit\Framework\TestCase;

final class FrameworkDetectorTest extends TestCase
{
    private FrameworkDetector $detector;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = new FrameworkDetector();
        $this->tempDir  = sys_get_temp_dir() . '/upgrader-framework-tests-' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->removeDir($this->tempDir);
    }

    // -------------------------------------------------
    // Laravel detection
    // -------------------------------------------------

    public function testDetectsLaravelWhenNeitherConditionMet(): void
    {
        $workspace = $this->makeWorkspace('laravel-standard', [
            'require' => ['laravel/framework' => '^8.0'],
        ]);

        $result = $this->detector->detect($workspace);

        self::assertSame('laravel', $result);
    }

    // -------------------------------------------------
    // Lumen detection (both conditions)
    // -------------------------------------------------

    public function testDetectsLumenWhenBothConditionsMet(): void
    {
        $workspace = $this->makeWorkspace('lumen-both', [
            'require' => ['laravel/lumen-framework' => '^8.0'],
        ]);

        $bootstrapDir = $workspace . '/bootstrap';
        mkdir($bootstrapDir, 0777, true);
        file_put_contents(
            $bootstrapDir . '/app.php',
            "<?php\n\$app = new Laravel\Lumen\Application(dirname(__DIR__));\nreturn \$app;\n"
        );

        $result = $this->detector->detect($workspace);

        self::assertSame('lumen', $result);
    }

    // -------------------------------------------------
    // Lumen ambiguous: only composer.json
    // -------------------------------------------------

    public function testDetectsLumenAmbiguousWhenOnlyPackagePresent(): void
    {
        $workspace = $this->makeWorkspace('lumen-package-only', [
            'require' => ['laravel/lumen-framework' => '^8.0'],
        ]);

        // No bootstrap/app.php

        ob_start();
        $result = $this->detector->detect($workspace);
        $output = (string) ob_get_clean();

        self::assertSame('lumen_ambiguous', $result);

        $decoded = json_decode($output, true);
        self::assertIsArray($decoded);
        self::assertSame('warning', $decoded['event']);
        self::assertSame('lumen_ambiguous', $decoded['type']);
    }

    // -------------------------------------------------
    // Lumen ambiguous: only bootstrap/app.php
    // -------------------------------------------------

    public function testDetectsLumenAmbiguousWhenOnlyBootstrapPresent(): void
    {
        $workspace = $this->makeWorkspace('lumen-bootstrap-only', [
            'require' => ['laravel/framework' => '^8.0'],
        ]);

        $bootstrapDir = $workspace . '/bootstrap';
        mkdir($bootstrapDir, 0777, true);
        file_put_contents(
            $bootstrapDir . '/app.php',
            "<?php\n\$app = new Laravel\Lumen\Application(dirname(__DIR__));\nreturn \$app;\n"
        );

        ob_start();
        $result = $this->detector->detect($workspace);
        $output = (string) ob_get_clean();

        self::assertSame('lumen_ambiguous', $result);

        $decoded = json_decode($output, true);
        self::assertIsArray($decoded);
        self::assertSame('warning', $decoded['event']);
    }

    // -------------------------------------------------
    // Lumen fixture file
    // -------------------------------------------------

    public function testDetectsLumenUsingFixtureFiles(): void
    {
        $fixturesDir = dirname(__DIR__, 2) . '/Fixtures';

        $workspace = $this->makeWorkspaceFromContents('lumen-fixture', [
            'require' => ['laravel/lumen-framework' => '^8.0'],
        ]);

        $bootstrapDir = $workspace . '/bootstrap';
        mkdir($bootstrapDir, 0777, true);
        copy($fixturesDir . '/bootstrap-lumen.php', $bootstrapDir . '/app.php');

        $result = $this->detector->detect($workspace);

        self::assertSame('lumen', $result);
    }

    // -------------------------------------------------
    // Error cases
    // -------------------------------------------------

    public function testThrowsDetectionExceptionWhenComposerJsonMissing(): void
    {
        $this->expectException(DetectionException::class);
        $this->expectExceptionMessage('composer.json not found');

        $this->detector->detect($this->tempDir . '/no-such-dir');
    }

    // -------------------------------------------------
    // Helpers
    // -------------------------------------------------

    /**
     * @param array<string, mixed> $composerData
     */
    private function makeWorkspace(string $name, array $composerData): string
    {
        return $this->makeWorkspaceFromContents($name, $composerData);
    }

    /**
     * @param array<string, mixed> $composerData
     */
    private function makeWorkspaceFromContents(string $name, array $composerData): string
    {
        $workspace = $this->tempDir . '/' . $name;
        mkdir($workspace, 0777, true);

        file_put_contents(
            $workspace . '/composer.json',
            json_encode($composerData)
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
