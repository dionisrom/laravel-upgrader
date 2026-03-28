<?php

declare(strict_types=1);

namespace Tests\Unit;

use AppContainer\Config\ConfigMerger;
use PHPUnit\Framework\TestCase;

class ConfigMergerTest extends TestCase
{
    private ConfigMerger $merger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->merger = new ConfigMerger();
    }

    // -----------------------------------------------------------------------
    // merge() — basic behavior
    // -----------------------------------------------------------------------

    public function testNewKeysAreAdded(): void
    {
        $result = $this->merger->merge(
            ['existing' => 'value'],
            ['new_key' => 'new_value'],
            []
        );

        $this->assertSame('value', $result['existing']);
        $this->assertSame('new_value', $result['new_key']);
    }

    public function testCustomKeysPreservedWhenNotInKnownChanged(): void
    {
        $result = $this->merger->merge(
            ['driver' => 'custom-driver'],
            ['driver' => 'new-driver'],
            [] // driver not in known changed
        );

        $this->assertSame('custom-driver', $result['driver']);
    }

    public function testKnownChangedKeysAreOverwritten(): void
    {
        $result = $this->merger->merge(
            ['same_site' => null],
            ['same_site' => 'lax'],
            ['same_site']
        );

        $this->assertSame('lax', $result['same_site']);
    }

    public function testDeepMergePreservesCustomNestedKeys(): void
    {
        $existing = [
            'passwords' => [
                'users' => [
                    'provider' => 'users',
                    'table' => 'password_resets',
                    'custom_key' => 'keep_me',
                ],
            ],
        ];

        $changes = [
            'passwords' => [
                'users' => [
                    'throttle' => 60,
                ],
            ],
        ];

        $result = $this->merger->merge($existing, $changes, ['passwords']);

        $this->assertSame('keep_me', $result['passwords']['users']['custom_key']);
        $this->assertSame(60, $result['passwords']['users']['throttle']);
        $this->assertSame('users', $result['passwords']['users']['provider']);
    }

    public function testNestedKnownChangedKeysWithDotNotation(): void
    {
        $existing = [
            'stores' => [
                'redis' => [
                    'driver' => 'old',
                    'client' => 'predis',
                ],
            ],
        ];

        $changes = [
            'stores' => [
                'redis' => [
                    'client' => 'phpredis',
                ],
            ],
        ];

        $result = $this->merger->merge($existing, $changes, ['stores.redis.client']);

        $this->assertSame('phpredis', $result['stores']['redis']['client']);
        $this->assertSame('old', $result['stores']['redis']['driver']);
    }

    public function testParentKeyAsWildcardOverwritesAllChildren(): void
    {
        $existing = [
            'session' => [
                'driver' => 'file',
                'lifetime' => 120,
            ],
        ];

        $changes = [
            'session' => [
                'driver' => 'redis',
                'lifetime' => 180,
            ],
        ];

        // When 'session' itself is a known-changed key, all its children are overwritable
        $result = $this->merger->merge($existing, $changes, ['session']);

        $this->assertSame('redis', $result['session']['driver']);
        $this->assertSame(180, $result['session']['lifetime']);
    }

    public function testEmptyArraysMergeCorrectly(): void
    {
        $result = $this->merger->merge([], ['key' => 'value'], []);
        $this->assertSame(['key' => 'value'], $result);
    }

    // -----------------------------------------------------------------------
    // renderPhpConfig()
    // -----------------------------------------------------------------------

    public function testRenderPhpConfigProducesValidPhp(): void
    {
        $config = [
            'key' => 'value',
            'nested' => [
                'inner' => true,
            ],
        ];

        $output = $this->merger->renderPhpConfig($config);

        $this->assertStringStartsWith("<?php\n\nreturn [", $output);
        $this->assertStringEndsWith("];\n", $output);
        $this->assertStringContainsString("'key' => 'value'", $output);
        $this->assertStringContainsString("'inner' => true", $output);
    }

    public function testRenderPhpConfigEmptyArray(): void
    {
        $output = $this->merger->renderPhpConfig([]);
        $this->assertSame("<?php\n\nreturn [];\n", $output);
    }
}
