<?php

declare(strict_types=1);

namespace Tests\Unit\Rector;

use AppContainer\Rector\PackageRuleActivator;
use AppContainer\Rector\PackageVersionMatrix;
use PHPUnit\Framework\TestCase;

final class PackageRuleActivatorTest extends TestCase
{
    private PackageRuleActivator $activator;

    private string $configDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configDir = dirname(__DIR__, 3) . '/config/package-rules';
        $this->activator = new PackageRuleActivator(new PackageVersionMatrix($this->configDir));
    }

    public function test_returns_empty_array_for_missing_file(): void
    {
        $result = $this->activator->activate('/non/existent/composer.lock', '9-to-10');

        self::assertSame([], $result);
    }

    public function test_returns_empty_array_when_no_known_packages_installed(): void
    {
        $lockPath = __DIR__ . '/../../Fixtures/composer-laravel9.lock';

        // composer-laravel9.lock only has laravel/framework — no known package rules.
        $result = $this->activator->activate($lockPath, '9-to-10');

        self::assertSame([], $result);
    }

    public function test_activates_livewire_rules_when_package_present(): void
    {
        $tmpDir = sys_get_temp_dir() . '/upgrader-test-' . uniqid();
        mkdir($tmpDir, 0755, true);

        $lockContent = json_encode([
            'packages' => [
                ['name' => 'laravel/framework', 'version' => 'v9.52.15'],
                ['name' => 'livewire/livewire', 'version' => 'v2.12.0'],
            ],
            'packages-dev' => [],
        ], JSON_THROW_ON_ERROR);

        $lockPath = $tmpDir . '/composer.lock';
        file_put_contents($lockPath, $lockContent);

        $result = $this->activator->activate($lockPath, '9-to-10');

        // Livewire v2 on hop 9-to-10 should activate EmitToDispatchRector + ComputedPropertyRector
        self::assertNotEmpty($result);
        self::assertContains(
            'AppContainer\\Rector\\Rules\\Package\\Livewire\\EmitToDispatchRector',
            $result,
        );

        unlink($lockPath);
        rmdir($tmpDir);
    }

    public function test_does_not_activate_rules_for_packages_not_in_lock(): void
    {
        $tmpDir = sys_get_temp_dir() . '/upgrader-test-' . uniqid();
        mkdir($tmpDir, 0755, true);

        // Only laravel/framework — no livewire, no spatie.
        $lockPath = $tmpDir . '/composer.lock';
        file_put_contents($lockPath, json_encode([
            'packages' => [['name' => 'laravel/framework', 'version' => 'v9.52.15']],
            'packages-dev' => [],
        ]));

        $result = $this->activator->activate($lockPath, '9-to-10');

        self::assertSame([], $result);

        unlink($lockPath);
        rmdir($tmpDir);
    }

    public function test_handles_invalid_json_gracefully(): void
    {
        $tmpDir = sys_get_temp_dir() . '/upgrader-test-' . uniqid();
        mkdir($tmpDir, 0755, true);

        $lockPath = $tmpDir . '/composer.lock';
        file_put_contents($lockPath, '{{ not json }}');

        $result = $this->activator->activate($lockPath, '9-to-10');
        self::assertSame([], $result);

        unlink($lockPath);
        rmdir($tmpDir);
    }

    public function test_handles_empty_packages(): void
    {
        $tmpDir = sys_get_temp_dir() . '/upgrader-test-' . uniqid();
        mkdir($tmpDir, 0755, true);

        $lockPath = $tmpDir . '/composer.lock';
        file_put_contents($lockPath, json_encode(['packages' => [], 'packages-dev' => []]));

        $result = $this->activator->activate($lockPath, '9-to-10');
        self::assertSame([], $result);

        unlink($lockPath);
        rmdir($tmpDir);
    }

    public function test_activates_filament_rules_for_v2_on_l9_to_l10(): void
    {
        $tmpDir = sys_get_temp_dir() . '/upgrader-test-' . uniqid();
        mkdir($tmpDir, 0755, true);

        $lockPath = $tmpDir . '/composer.lock';
        file_put_contents($lockPath, json_encode([
            'packages' => [
                ['name' => 'laravel/framework', 'version' => 'v9.52.15'],
                ['name' => 'filament/filament', 'version' => 'v2.17.0'],
            ],
            'packages-dev' => [],
        ]));

        $result = $this->activator->activate($lockPath, '9-to-10');

        self::assertNotEmpty($result);
        self::assertContains(
            'AppContainer\\Rector\\Rules\\Package\\Filament\\FilamentFormTableNamespaceRector',
            $result,
        );

        unlink($lockPath);
        rmdir($tmpDir);
    }
}
