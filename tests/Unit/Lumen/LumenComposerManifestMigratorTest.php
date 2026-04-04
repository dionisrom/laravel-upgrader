<?php

declare(strict_types=1);

namespace Tests\Unit\Lumen;

use AppContainer\Lumen\LumenComposerManifestMigrator;
use PHPUnit\Framework\TestCase;

final class LumenComposerManifestMigratorTest extends TestCase
{
    public function testMigratesComposerManifestOntoLaravelScaffold(): void
    {
        $source = sys_get_temp_dir() . '/upgrader-lumen-source-' . uniqid('', true);
        $target = sys_get_temp_dir() . '/upgrader-lumen-target-' . uniqid('', true);

        mkdir($source, 0755, true);
        mkdir($target, 0755, true);

        file_put_contents($source . '/composer.json', json_encode([
            'name' => 'acme/lumen-app',
            'repositories' => [
                ['type' => 'git', 'url' => 'git@example.com:private/repo.git'],
            ],
            'require' => [
                'php' => '^8.3',
                'laravel/lumen-framework' => '^8.0',
                'guzzlehttp/guzzle' => '^7.0',
                'flipbox/lumen-generator' => '^8.0',
            ],
            'autoload' => [
                'psr-4' => ['App\\' => 'app/'],
            ],
        ], JSON_THROW_ON_ERROR));

        file_put_contents($target . '/composer.json', json_encode([
            'name' => 'laravel/laravel',
            'require' => [
                'php' => '^8.0.2',
                'laravel/framework' => '^9.0',
            ],
            'scripts' => [
                'post-autoload-dump' => ['Illuminate\\Foundation\\ComposerScripts::postAutoloadDump'],
            ],
        ], JSON_THROW_ON_ERROR));

        $result = (new LumenComposerManifestMigrator())->migrate($source, $target);

        $manifest = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('acme/lumen-app', $manifest['name']);
        self::assertSame('^8.3', $manifest['require']['php']);
        self::assertSame('^9.0', $manifest['require']['laravel/framework']);
        self::assertSame('^7.0', $manifest['require']['guzzlehttp/guzzle']);
        self::assertArrayNotHasKey('laravel/lumen-framework', $manifest['require']);
        self::assertArrayNotHasKey('flipbox/lumen-generator', $manifest['require']);
        self::assertSame('git@example.com:private/repo.git', $manifest['repositories'][0]['url']);
        self::assertContains('flipbox/lumen-generator', $result->removedPackages);
        self::assertNotEmpty($result->manualReviewItems);
    }
}