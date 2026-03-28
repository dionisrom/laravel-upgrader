<?php

declare(strict_types=1);

namespace AppContainer\Rector\Rules\Package\Passport;

use AppContainer\Rector\Rules\Package\AbstractPackageRuleSet;

/**
 * PHP rule set descriptor for laravel/passport.
 *
 * Passport V10→V11 (Laravel 9→10 era): `Passport::routes()` deprecated.
 * This is flagged for manual review; no safe auto-fix is possible without
 * knowing the application's OAuth flow configuration.
 */
final class PassportRules extends AbstractPackageRuleSet
{
    public function getPackageName(): string
    {
        return 'laravel/passport';
    }

    /**
     * @return list<string>
     */
    public function getRuleClasses(string $hop): array
    {
        // No auto-fixable rules for Passport at this time.
        // Passport route registration changes require manual review.
        return [];
    }

    /**
     * @return list<string>
     */
    public function supportedHops(): array
    {
        return ['9-to-10'];
    }
}
