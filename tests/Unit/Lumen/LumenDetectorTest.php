<?php

declare(strict_types=1);

namespace Tests\Unit\Lumen;

use AppContainer\Detector\FrameworkDetector;
use AppContainer\Lumen\LumenDetector;
use PHPUnit\Framework\TestCase;

final class LumenDetectorTest extends TestCase
{
    private LumenDetector $detector;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = new LumenDetector(new FrameworkDetector());
        $this->tempDir = sys_get_temp_dir() . '/upgrader-lumen-tests-' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->removeDir($this->tempDir);
    }

    public function testAmbiguousDetectionReportsRequireDevPackageMetadata(): void
    {
        $workspace = $this->tempDir . '/lumen-require-dev-ambiguous';
        mkdir($workspace, 0777, true);

        file_put_contents(
            $workspace . '/composer.json',
            json_encode([
                'require' => ['laravel/framework' => '^8.0'],
                'require-dev' => ['laravel/lumen-framework' => '^8.0'],
            ])
        );

        ob_start();
        $result = $this->detector->detect($workspace);
        $output = (string) ob_get_clean();

        self::assertSame('lumen_ambiguous', $result->framework);
        self::assertTrue($result->hasLumenPackage);
        self::assertFalse($result->hasLumenBootstrap);

        $events = array_values(array_filter(array_map('trim', explode("\n", $output))));
        self::assertCount(2, $events);

        $warningEvent = json_decode($events[0], true);
        self::assertIsArray($warningEvent);
        self::assertSame('warning', $warningEvent['event']);
        self::assertSame('lumen_ambiguous', $warningEvent['type']);

        $detectionEvent = json_decode($events[1], true);
        self::assertIsArray($detectionEvent);
        self::assertSame('lumen_detection', $detectionEvent['event']);
        self::assertSame('lumen_ambiguous', $detectionEvent['framework']);
        self::assertTrue($detectionEvent['has_package']);
        self::assertFalse($detectionEvent['has_bootstrap']);
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