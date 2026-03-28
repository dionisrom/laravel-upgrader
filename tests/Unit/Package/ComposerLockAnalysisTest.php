<?php

declare(strict_types=1);

namespace Tests\Unit\Package;

use App\Composer\ComposerLockAnalysis;
use PHPUnit\Framework\TestCase;

final class ComposerLockAnalysisTest extends TestCase
{
    public function test_from_missing_file_returns_empty(): void
    {
        $analysis = ComposerLockAnalysis::fromLockFile('/non/existent/composer.lock');

        self::assertSame([], $analysis->installedPackages);
        self::assertFalse($analysis->hasPackage('livewire/livewire'));
        self::assertNull($analysis->getVersion('livewire/livewire'));
    }

    public function test_from_lock_file_parses_packages(): void
    {
        $tmpFile = sys_get_temp_dir() . '/test-composer-' . uniqid() . '.lock';
        file_put_contents($tmpFile, json_encode([
            'packages' => [
                ['name' => 'livewire/livewire', 'version' => 'v2.12.0'],
                ['name' => 'laravel/framework', 'version' => 'v9.52.15'],
            ],
            'packages-dev' => [
                ['name' => 'phpunit/phpunit', 'version' => 'v10.0.0'],
            ],
        ]));

        $analysis = ComposerLockAnalysis::fromLockFile($tmpFile);
        unlink($tmpFile);

        self::assertTrue($analysis->hasPackage('livewire/livewire'));
        self::assertSame('v2.12.0', $analysis->getVersion('livewire/livewire'));
        self::assertTrue($analysis->hasPackage('laravel/framework'));
        self::assertTrue($analysis->hasPackage('phpunit/phpunit'));
        self::assertFalse($analysis->hasPackage('unknown/package'));
    }

    public function test_invalid_json_returns_empty(): void
    {
        $tmpFile = sys_get_temp_dir() . '/test-composer-' . uniqid() . '.lock';
        file_put_contents($tmpFile, '{{not valid json}}');

        $analysis = ComposerLockAnalysis::fromLockFile($tmpFile);
        unlink($tmpFile);

        self::assertSame([], $analysis->installedPackages);
    }

    public function test_constructor_direct_instantiation(): void
    {
        $analysis = new ComposerLockAnalysis(['filament/filament' => 'v3.0.0']);

        self::assertTrue($analysis->hasPackage('filament/filament'));
        self::assertSame('v3.0.0', $analysis->getVersion('filament/filament'));
        self::assertFalse($analysis->hasPackage('livewire/livewire'));
    }
}
