<?php

declare(strict_types=1);

namespace Tests\Unit\Composer;

use App\Composer\LaravelVersionDetector;
use PHPUnit\Framework\TestCase;

final class LaravelVersionDetectorTest extends TestCase
{
    private LaravelVersionDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new LaravelVersionDetector();
    }

    public function testDetectsLaravel8(): void
    {
        $workspace = $this->createLockWorkspace('laravel/framework', 'v8.83.27');
        self::assertSame('8', $this->detector->detect($workspace));
    }

    public function testDetectsLaravel9(): void
    {
        $workspace = $this->createLockWorkspace('laravel/framework', 'v9.52.15');
        self::assertSame('9', $this->detector->detect($workspace));
    }

    public function testDetectsLumenFramework(): void
    {
        $workspace = $this->createLockWorkspace('laravel/lumen-framework', '8.3.4');
        self::assertSame('8', $this->detector->detect($workspace));
    }

    public function testReturnsNullWhenNoLockFile(): void
    {
        self::assertNull($this->detector->detect(sys_get_temp_dir() . '/nonexistent-' . uniqid()));
    }

    public function testReturnsNullWhenNoLaravelPackage(): void
    {
        $workspace = $this->createLockWorkspace('some/other-package', '3.0.0');
        self::assertNull($this->detector->detect($workspace));
    }

    public function testReturnsNullForMalformedLock(): void
    {
        $dir = sys_get_temp_dir() . '/upgrader-detect-' . uniqid();
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/composer.lock', 'not-json');

        self::assertNull($this->detector->detect($dir));
    }

    private function createLockWorkspace(string $packageName, string $version): string
    {
        $dir = sys_get_temp_dir() . '/upgrader-detect-' . uniqid();
        mkdir($dir, 0755, true);

        $lock = [
            'packages' => [
                ['name' => $packageName, 'version' => $version],
            ],
        ];

        file_put_contents($dir . '/composer.lock', json_encode($lock, JSON_THROW_ON_ERROR));

        return $dir;
    }
}
