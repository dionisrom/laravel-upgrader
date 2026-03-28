<?php

declare(strict_types=1);

namespace Tests\Unit\Package\Rules;

use AppContainer\Rector\Rules\Package\Filament\FilamentRules;
use AppContainer\Rector\Rules\Package\Horizon\HorizonRules;
use AppContainer\Rector\Rules\Package\Livewire\LivewireRuleSet;
use AppContainer\Rector\Rules\Package\Nova\NovaRules;
use AppContainer\Rector\Rules\Package\Passport\PassportRules;
use AppContainer\Rector\Rules\Package\Sanctum\SanctumRules;
use AppContainer\Rector\Rules\Package\Spatie\SpatiePackageRules;
use PHPUnit\Framework\TestCase;

/**
 * Tests for all AbstractPackageRuleSet concrete implementations.
 *
 * Verifies: package name, hop support, rule class existence, isApplicable().
 */
final class PackageRuleSetTest extends TestCase
{
    // ─── LivewireRuleSet ──────────────────────────────────────────────────────

    public function test_livewire_rule_set_package_name(): void
    {
        $set = new LivewireRuleSet();
        self::assertSame('livewire/livewire', $set->getPackageName());
    }

    public function test_livewire_rule_set_applicable(): void
    {
        $set = new LivewireRuleSet();
        self::assertTrue($set->isApplicable('livewire/livewire'));
        self::assertFalse($set->isApplicable('livewire/other'));
    }

    public function test_livewire_rules_for_hop_9_to_10(): void
    {
        $set   = new LivewireRuleSet();
        $rules = $set->getRuleClasses('9-to-10');

        self::assertNotEmpty($rules);
        foreach ($rules as $class) {
            self::assertTrue(class_exists($class), "Rule class {$class} does not exist");
        }
    }

    public function test_livewire_rules_for_unknown_hop_returns_empty(): void
    {
        $set = new LivewireRuleSet();
        self::assertSame([], $set->getRuleClasses('1-to-2'));
    }

    public function test_livewire_get_all_rule_classes_returns_unique(): void
    {
        $set  = new LivewireRuleSet();
        $all  = $set->getAllRuleClasses();
        $unique = array_unique($all);
        self::assertSame(count($unique), count($all), 'getAllRuleClasses() must not contain duplicates');
    }

    // ─── SpatiePackageRules ───────────────────────────────────────────────────

    public function test_spatie_rule_set_package_name(): void
    {
        $set = new SpatiePackageRules();
        self::assertSame('spatie/laravel-medialibrary', $set->getPackageName());
    }

    public function test_spatie_rules_for_hop_9_to_10(): void
    {
        $set   = new SpatiePackageRules();
        $rules = $set->getRuleClasses('9-to-10');

        self::assertNotEmpty($rules);
        foreach ($rules as $class) {
            self::assertTrue(class_exists($class), "Rule class {$class} does not exist");
        }
    }

    // ─── FilamentRules ────────────────────────────────────────────────────────

    public function test_filament_rule_set_package_name(): void
    {
        $set = new FilamentRules();
        self::assertSame('filament/filament', $set->getPackageName());
    }

    public function test_filament_rules_for_hop_9_to_10(): void
    {
        $set   = new FilamentRules();
        $rules = $set->getRuleClasses('9-to-10');

        self::assertNotEmpty($rules);
        foreach ($rules as $class) {
            self::assertTrue(class_exists($class), "Rule class {$class} does not exist");
        }
    }

    // ─── SanctumRules ────────────────────────────────────────────────────────

    public function test_sanctum_rule_set_package_name(): void
    {
        $set = new SanctumRules();
        self::assertSame('laravel/sanctum', $set->getPackageName());
    }

    public function test_sanctum_returns_empty_rules_no_ast_changes(): void
    {
        $set = new SanctumRules();
        self::assertSame([], $set->getRuleClasses('9-to-10'));
        self::assertSame([], $set->getRuleClasses('10-to-11'));
    }

    // ─── PassportRules ────────────────────────────────────────────────────────

    public function test_passport_rule_set_package_name(): void
    {
        $set = new PassportRules();
        self::assertSame('laravel/passport', $set->getPackageName());
    }

    public function test_passport_returns_empty_rules_manual_review(): void
    {
        $set = new PassportRules();
        self::assertSame([], $set->getRuleClasses('9-to-10'));
    }

    // ─── NovaRules ────────────────────────────────────────────────────────────

    public function test_nova_rule_set_package_name(): void
    {
        $set = new NovaRules();
        self::assertSame('laravel/nova', $set->getPackageName());
    }

    public function test_nova_returns_empty_rules(): void
    {
        $set = new NovaRules();
        self::assertSame([], $set->getRuleClasses('9-to-10'));
        self::assertSame([], $set->getRuleClasses('10-to-11'));
        self::assertSame([], $set->getRuleClasses('11-to-12'));
    }

    // ─── HorizonRules ─────────────────────────────────────────────────────────

    public function test_horizon_rule_set_package_name(): void
    {
        $set = new HorizonRules();
        self::assertSame('laravel/horizon', $set->getPackageName());
    }

    public function test_horizon_returns_empty_rules_config_level(): void
    {
        $set = new HorizonRules();
        self::assertSame([], $set->getRuleClasses('9-to-10'));
        self::assertSame([], $set->getRuleClasses('10-to-11'));
    }

    // ─── JSON Matrix Cross-Check ──────────────────────────────────────────────

    /**
     * Ensures PHP RuleSet supportedHops() with non-empty rules are backed by
     * a rector_config entry in the corresponding JSON matrix file.
     *
     * @dataProvider ruleSetWithJsonProvider
     */
    public function test_php_ruleset_hops_with_rules_have_json_rector_config(
        \AppContainer\Rector\Rules\Package\AbstractPackageRuleSet $ruleSet,
    ): void {
        $configDir = dirname(__DIR__, 4) . '/config/package-rules';
        $slug      = str_replace('/', '-', $ruleSet->getPackageName());
        $jsonPath  = $configDir . '/' . $slug . '.json';

        if (! is_file($jsonPath)) {
            self::markTestSkipped("No JSON matrix for {$ruleSet->getPackageName()}");
        }

        $matrix = json_decode(file_get_contents($jsonPath), true);
        self::assertIsArray($matrix);

        foreach ($ruleSet->supportedHops() as $hop) {
            $rules = $ruleSet->getRuleClasses($hop);
            if ($rules === []) {
                continue; // No rules for this hop — no config needed
            }

            $hopKey    = 'hop-' . $hop;
            $hopConfig = $matrix['hops'][$hopKey] ?? null;

            self::assertIsArray(
                $hopConfig,
                "PHP RuleSet {$ruleSet->getPackageName()} declares rules for hop '{$hop}' but JSON matrix has no entry for '{$hopKey}'",
            );

            self::assertNotEmpty(
                $hopConfig['rector_config'] ?? '',
                "PHP RuleSet {$ruleSet->getPackageName()} declares rules for hop '{$hop}' but JSON matrix '{$hopKey}' has no rector_config",
            );
        }
    }

    /**
     * @return iterable<string, array{\AppContainer\Rector\Rules\Package\AbstractPackageRuleSet}>
     */
    public static function ruleSetWithJsonProvider(): iterable
    {
        yield 'livewire'   => [new LivewireRuleSet()];
        yield 'spatie'     => [new SpatiePackageRules()];
        yield 'filament'   => [new FilamentRules()];
        yield 'sanctum'    => [new SanctumRules()];
        yield 'passport'   => [new PassportRules()];
        yield 'nova'       => [new NovaRules()];
        yield 'horizon'    => [new HorizonRules()];
    }
}
