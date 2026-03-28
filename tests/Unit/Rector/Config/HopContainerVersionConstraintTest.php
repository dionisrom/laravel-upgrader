<?php

declare(strict_types=1);

namespace Tests\Unit\Rector\Config;

use PHPUnit\Framework\TestCase;

/**
 * Regression test: ensures hop container composer specs declare
 * driftingly/rector-laravel at a version that includes required set-list
 * constants used in the corresponding rector config.
 *
 * History: hop-11-to-12 was broken because rector.l11-to-l12.php referenced
 * LaravelSetList::LARAVEL_120, which was only added in rector-laravel 2.0.
 * The hop container spec pinned ^1.2 (no LARAVEL_120 → undefined constant at
 * runtime). This test catches that class of mismatch at the spec level.
 */
final class HopContainerVersionConstraintTest extends TestCase
{
    private const HOP_COMPOSER_FILES = [
        'hop-11-to-12' => __DIR__ . '/../../../../docker/hop-11-to-12/composer.hop-11-to-12.json',
        'hop-12-to-13' => __DIR__ . '/../../../../docker/hop-12-to-13/composer.hop-12-to-13.json',
    ];

    /**
     * rector-laravel 2.0 introduced LaravelSetList::LARAVEL_120.
     * The hop-11-to-12 rector config requires this constant.
     * The container must therefore require ^2.0 (not ^1.x).
     */
    public function testHop11To12RequiresRectorLaravel2(): void
    {
        $composerPath = self::HOP_COMPOSER_FILES['hop-11-to-12'];
        self::assertFileExists($composerPath);

        /** @var array{require: array<string, string>} $manifest */
        $manifest = json_decode((string) file_get_contents($composerPath), true, 512, JSON_THROW_ON_ERROR);

        $constraint = $manifest['require']['driftingly/rector-laravel'] ?? '';

        self::assertNotEmpty($constraint, 'driftingly/rector-laravel must be declared in hop-11-to-12 require');

        // Must resolve to ^2.0 or higher — ^1.x does not contain LARAVEL_120.
        self::assertMatchesRegularExpression(
            '/^\^2\.\d+$/',
            $constraint,
            'hop-11-to-12 must require driftingly/rector-laravel ^2.x because '
            . 'rector.l11-to-l12.php uses LaravelSetList::LARAVEL_120 which was added in 2.0. '
            . 'Actual constraint: ' . $constraint
        );
    }

    /**
     * rector-laravel 2.x requires rector/rector ^2.0.
     * Ensure the hop container tracks this dependency properly.
     */
    public function testHop11To12RequiresRector2(): void
    {
        $composerPath = self::HOP_COMPOSER_FILES['hop-11-to-12'];

        /** @var array{require: array<string, string>} $manifest */
        $manifest = json_decode((string) file_get_contents($composerPath), true, 512, JSON_THROW_ON_ERROR);

        $constraint = $manifest['require']['rector/rector'] ?? '';

        self::assertNotEmpty($constraint, 'rector/rector must be declared in hop-11-to-12 require');

        self::assertMatchesRegularExpression(
            '/^\^2\.\d+$/',
            $constraint,
            'hop-11-to-12 must require rector/rector ^2.x because rector-laravel ^2.0 requires it. '
            . 'Actual constraint: ' . $constraint
        );
    }

    /**
     * rector-laravel 2.x introduced LaravelSetList::LARAVEL_130.
     * The hop-12-to-13 rector config requires this constant.
     * The container must therefore require ^2.0 (not ^1.x).
     */
    public function testHop12To13RequiresRectorLaravel2(): void
    {
        $composerPath = self::HOP_COMPOSER_FILES['hop-12-to-13'];
        self::assertFileExists($composerPath);

        /** @var array{require: array<string, string>} $manifest */
        $manifest = json_decode((string) file_get_contents($composerPath), true, 512, JSON_THROW_ON_ERROR);

        $constraint = $manifest['require']['driftingly/rector-laravel'] ?? '';

        self::assertNotEmpty($constraint, 'driftingly/rector-laravel must be declared in hop-12-to-13 require');

        // Must resolve to ^2.0 or higher — ^1.x does not contain LARAVEL_130.
        self::assertMatchesRegularExpression(
            '/^\^2\.\d+$/',
            $constraint,
            'hop-12-to-13 must require driftingly/rector-laravel ^2.x because '
            . 'rector.l12-to-l13.php uses LaravelSetList::LARAVEL_130 which was added in 2.x. '
            . 'Actual constraint: ' . $constraint
        );
    }

    /**
     * rector-laravel 2.x requires rector/rector ^2.0.
     * Ensure the hop-12-to-13 container tracks this dependency properly.
     */
    public function testHop12To13RequiresRector2(): void
    {
        $composerPath = self::HOP_COMPOSER_FILES['hop-12-to-13'];

        /** @var array{require: array<string, string>} $manifest */
        $manifest = json_decode((string) file_get_contents($composerPath), true, 512, JSON_THROW_ON_ERROR);

        $constraint = $manifest['require']['rector/rector'] ?? '';

        self::assertNotEmpty($constraint, 'rector/rector must be declared in hop-12-to-13 require');

        self::assertMatchesRegularExpression(
            '/^\^2\.\d+$/',
            $constraint,
            'hop-12-to-13 must require rector/rector ^2.x because rector-laravel ^2.0 requires it. '
            . 'Actual constraint: ' . $constraint
        );
    }
}
