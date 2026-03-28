<?php

declare(strict_types=1);

namespace Tests\Unit\Rector;

use AppContainer\Rector\PackageVersionMatrix;
use PHPUnit\Framework\TestCase;

/**
 * @see PackageVersionMatrix
 */
final class PackageVersionMatrixTest extends TestCase
{
    private string $configDir;

    protected function setUp(): void
    {
        parent::setUp();
        // Point to the real config/package-rules directory.
        $this->configDir = dirname(__DIR__, 3) . '/config/package-rules';
    }

    public function test_returns_rules_for_known_package_and_hop(): void
    {
        $matrix = new PackageVersionMatrix($this->configDir);

        // livewire/livewire v2.12.0 on hop-9-to-10 should return Livewire rules.
        $rules = $matrix->getRules('livewire/livewire', 'v2.12.0', '9-to-10');

        self::assertNotEmpty($rules);
        self::assertContains(
            'AppContainer\\Rector\\Rules\\Package\\Livewire\\EmitToDispatchRector',
            $rules,
        );
    }

    public function test_returns_empty_for_unknown_package(): void
    {
        $matrix = new PackageVersionMatrix($this->configDir);

        $rules = $matrix->getRules('unknown/package', '1.0.0', '9-to-10');

        self::assertSame([], $rules);
    }

    public function test_returns_empty_for_missing_hop(): void
    {
        $matrix = new PackageVersionMatrix($this->configDir);

        $rules = $matrix->getRules('livewire/livewire', '2.12.0', 'non-existent-hop');

        self::assertSame([], $rules);
    }

    public function test_returns_empty_when_version_does_not_satisfy_constraint(): void
    {
        $matrix = new PackageVersionMatrix($this->configDir);

        // hop-8-to-9 has empty rules for livewire (from_constraint ^2.0, no rules).
        // But v1.0.0 does NOT satisfy ^2.0, so rules should be empty.
        $rules = $matrix->getRules('livewire/livewire', '1.0.0', '9-to-10');

        self::assertSame([], $rules);
    }

    public function test_dev_versions_satisfy_any_constraint(): void
    {
        $matrix = new PackageVersionMatrix($this->configDir);

        $rules = $matrix->getRules('livewire/livewire', 'dev-main', '9-to-10');

        // Dev versions satisfy any constraint — should return the hop's rules.
        self::assertNotEmpty($rules);
    }

    public function test_caret_constraint_same_major_different_minor(): void
    {
        $matrix = new PackageVersionMatrix($this->configDir);

        // v2.5.0 satisfies ^2.0 (same major, higher minor).
        $rules = $matrix->getRules('livewire/livewire', 'v2.5.0', '9-to-10');

        self::assertNotEmpty($rules);
    }

    public function test_returns_empty_for_non_existent_config_dir(): void
    {
        $matrix = new PackageVersionMatrix('/non/existent/path');

        $rules = $matrix->getRules('livewire/livewire', '2.12.0', '9-to-10');

        self::assertSame([], $rules);
    }

    public function test_caches_loaded_matrix_across_calls(): void
    {
        $matrix = new PackageVersionMatrix($this->configDir);

        // Two calls for the same package — second should use cache.
        $rules1 = $matrix->getRules('livewire/livewire', '2.12.0', '9-to-10');
        $rules2 = $matrix->getRules('livewire/livewire', '2.12.0', '9-to-10');

        self::assertSame($rules1, $rules2);
    }

    public function test_filament_returns_rules_for_v2_on_hop_9_to_10(): void
    {
        $matrix = new PackageVersionMatrix($this->configDir);

        $rules = $matrix->getRules('filament/filament', 'v2.17.0', '9-to-10');

        self::assertNotEmpty($rules);
        self::assertContains(
            'AppContainer\\Rector\\Rules\\Package\\Filament\\FilamentFormTableNamespaceRector',
            $rules,
        );
    }

    public function test_spatie_medialibrary_returns_rules_for_v9_on_hop_9_to_10(): void
    {
        $matrix = new PackageVersionMatrix($this->configDir);

        $rules = $matrix->getRules('spatie/laravel-medialibrary', '9.11.0', '9-to-10');

        self::assertNotEmpty($rules);
        self::assertContains(
            'AppContainer\\Rector\\Rules\\Package\\Spatie\\HasMediaTraitRector',
            $rules,
        );
    }

    public function test_warn_does_not_throw_for_unsupported_hop(): void
    {
        $matrix = new PackageVersionMatrix($this->configDir);

        // Should not throw; only writes to stderr.
        $matrix->warnIfUnsupported('livewire/livewire', '2.12.0', 'hop-99-to-100');

        $this->addToAssertionCount(1); // Reaching here means no exception was thrown.
    }
}
