<?php

declare(strict_types=1);

namespace Tests\Unit\Package;

use App\Package\PackageVersionMatrix;
use PHPUnit\Framework\TestCase;

final class PackageVersionMatrixTest extends TestCase
{
    private PackageVersionMatrix $matrix;
    private string $workspaceRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $configDir           = dirname(__DIR__, 3) . '/config/package-rules';
        $this->workspaceRoot = dirname(__DIR__, 3);
        $this->matrix        = new PackageVersionMatrix($configDir);
    }

    public function test_returns_null_for_unknown_package(): void
    {
        $result = $this->matrix->getRectorConfigPath(
            'unknown/package',
            'v1.0.0',
            '9-to-10',
            $this->workspaceRoot,
        );

        self::assertNull($result);
    }

    public function test_returns_null_for_no_rules_in_hop(): void
    {
        // Livewire hop-8-to-9 has no rules
        $result = $this->matrix->getRectorConfigPath(
            'livewire/livewire',
            'v2.12.0',
            '8-to-9',
            $this->workspaceRoot,
        );

        self::assertNull($result);
    }

    public function test_returns_config_path_for_livewire_v2_hop_9_to_10(): void
    {
        $result = $this->matrix->getRectorConfigPath(
            'livewire/livewire',
            'v2.12.0',
            '9-to-10',
            $this->workspaceRoot,
        );

        self::assertNotNull($result);
        self::assertStringEndsWith('rector.livewire-v2-v3.php', $result);
        self::assertFileExists($result);
    }

    public function test_returns_null_when_version_does_not_satisfy_constraint(): void
    {
        // livewire v1.x doesn't satisfy ^2.0
        $result = $this->matrix->getRectorConfigPath(
            'livewire/livewire',
            'v1.3.5',
            '9-to-10',
            $this->workspaceRoot,
        );

        self::assertNull($result);
    }

    public function test_dev_version_satisfies_any_constraint(): void
    {
        $result = $this->matrix->getRectorConfigPath(
            'livewire/livewire',
            'dev-main',
            '9-to-10',
            $this->workspaceRoot,
        );

        // dev-main satisfies ^2.0 - config exists so should not be null
        self::assertNotNull($result);
    }

    public function test_returns_config_path_for_filament_v2_hop_9_to_10(): void
    {
        $result = $this->matrix->getRectorConfigPath(
            'filament/filament',
            'v2.17.0',
            '9-to-10',
            $this->workspaceRoot,
        );

        self::assertNotNull($result);
        self::assertStringEndsWith('rector.filament-v2-v3.php', $result);
    }

    public function test_returns_config_path_for_spatie_medialibrary_v9_hop_9_to_10(): void
    {
        $result = $this->matrix->getRectorConfigPath(
            'spatie/laravel-medialibrary',
            'v9.12.0',
            '9-to-10',
            $this->workspaceRoot,
        );

        self::assertNotNull($result);
        self::assertStringEndsWith('rector.spatie-medialibrary-v9-v10.php', $result);
    }

    public function test_warn_if_unsupported_emits_warning_for_missing_hop(): void
    {
        $warning = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$warning): bool {
            $warning = ['errno' => $errno, 'errstr' => $errstr];

            return true;
        }, E_USER_WARNING);

        try {
            $this->matrix->warnIfUnsupported('livewire/livewire', 'v2.12.0', '99-to-100');
        } finally {
            restore_error_handler();
        }

        self::assertNotNull($warning, 'Expected E_USER_WARNING to be emitted');
        self::assertSame(E_USER_WARNING, $warning['errno']);
        self::assertStringContainsString('livewire/livewire', $warning['errstr']);
        self::assertStringContainsString('99-to-100', $warning['errstr']);
    }

    public function test_warn_if_unsupported_no_warning_for_known_hop(): void
    {
        $warning = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$warning): bool {
            $warning = ['errno' => $errno, 'errstr' => $errstr];

            return true;
        }, E_USER_WARNING);

        try {
            $this->matrix->warnIfUnsupported('livewire/livewire', 'v2.12.0', '9-to-10');
        } finally {
            restore_error_handler();
        }

        self::assertNull($warning, 'No warning should be emitted for a known hop');
    }

    public function test_warn_if_unsupported_no_warning_for_unknown_package(): void
    {
        $warning = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$warning): bool {
            $warning = ['errno' => $errno, 'errstr' => $errstr];

            return true;
        }, E_USER_WARNING);

        try {
            $this->matrix->warnIfUnsupported('unknown/package', 'v1.0.0', '9-to-10');
        } finally {
            restore_error_handler();
        }

        self::assertNull($warning, 'No warning for unknown packages (no JSON file)');
    }
}
