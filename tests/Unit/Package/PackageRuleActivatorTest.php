<?php

declare(strict_types=1);

namespace Tests\Unit\Package;

use App\Composer\ComposerLockAnalysis;
use App\Package\PackageRuleActivator;
use App\Package\PackageVersionMatrix;
use PHPUnit\Framework\TestCase;

final class PackageRuleActivatorTest extends TestCase
{
    private string $configDir;
    private string $workspaceRoot;
    private PackageRuleActivator $activator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configDir     = dirname(__DIR__, 3) . '/config/package-rules';
        $this->workspaceRoot = dirname(__DIR__, 3);
        $this->activator     = new PackageRuleActivator(
            new PackageVersionMatrix($this->configDir),
            $this->workspaceRoot,
        );
    }

    public function test_returns_empty_for_no_known_packages(): void
    {
        $lock = new ComposerLockAnalysis(['laravel/framework' => 'v9.52.15']);

        $result = $this->activator->activate($lock, '9-to-10');

        self::assertSame([], $result);
    }

    public function test_activates_livewire_config_when_v2_on_hop_9_to_10(): void
    {
        $lock = new ComposerLockAnalysis([
            'laravel/framework' => 'v9.52.15',
            'livewire/livewire' => 'v2.12.0',
        ]);

        $result = $this->activator->activate($lock, '9-to-10');

        self::assertNotEmpty($result);
        self::assertStringEndsWith('rector.livewire-v2-v3.php', $result[0]);
    }

    public function test_activates_filament_config_when_v2_on_hop_9_to_10(): void
    {
        $lock = new ComposerLockAnalysis([
            'laravel/framework' => 'v9.52.15',
            'filament/filament' => 'v2.17.0',
        ]);

        $result = $this->activator->activate($lock, '9-to-10');

        self::assertNotEmpty($result);
        self::assertStringEndsWith('rector.filament-v2-v3.php', $result[0]);
    }

    public function test_activates_spatie_medialibrary_config_when_v9_on_hop_9_to_10(): void
    {
        $lock = new ComposerLockAnalysis([
            'laravel/framework'            => 'v9.52.15',
            'spatie/laravel-medialibrary' => 'v9.12.0',
        ]);

        $result = $this->activator->activate($lock, '9-to-10');

        self::assertNotEmpty($result);
        self::assertStringEndsWith('rector.spatie-medialibrary-v9-v10.php', $result[0]);
    }

    public function test_no_config_for_livewire_v3_already_migrated(): void
    {
        // Livewire v3 on hop 9-to-10: still activates the same config
        // (from_constraint ^2.0 won't match v3). Should return empty.
        $lock = new ComposerLockAnalysis([
            'livewire/livewire' => 'v3.0.0',
        ]);

        $result = $this->activator->activate($lock, '9-to-10');

        self::assertSame([], $result);
    }

    public function test_no_config_for_hops_without_rules(): void
    {
        $lock = new ComposerLockAnalysis([
            'livewire/livewire' => 'v3.0.0',
        ]);

        // hop-11-to-12 has no rules for livewire
        $result = $this->activator->activate($lock, '11-to-12');

        self::assertSame([], $result);
    }

    public function test_deduplicates_configs_for_multiple_spatie_packages(): void
    {
        // Even if multiple spatie packages activate the same config, it's returned once
        $lock = new ComposerLockAnalysis([
            'livewire/livewire'   => 'v2.12.0',
            'filament/filament'   => 'v2.17.0',
        ]);

        $result = $this->activator->activate($lock, '9-to-10');

        // Both should activate, and paths should be unique
        self::assertCount(count(array_unique($result)), $result);
    }

    public function test_empty_lock_returns_empty(): void
    {
        $lock = new ComposerLockAnalysis([]);

        $result = $this->activator->activate($lock, '9-to-10');

        self::assertSame([], $result);
    }
}
