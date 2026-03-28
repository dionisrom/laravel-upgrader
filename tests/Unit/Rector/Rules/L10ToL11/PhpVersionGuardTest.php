<?php

declare(strict_types=1);

namespace Tests\Unit\Rector\Rules\L10ToL11;

use AppContainer\Rector\Rules\L10ToL11\PhpVersionGuard;
use PHPUnit\Framework\TestCase;

/**
 * @see PhpVersionGuard
 */
final class PhpVersionGuardTest extends TestCase
{
    private PhpVersionGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new PhpVersionGuard();
    }

    // ── satisfiesMinimum() tests ──────────────────────────────────────────────

    public function test_caret_82_satisfies(): void
    {
        self::assertTrue($this->guard->satisfiesMinimum('^8.2'));
    }

    public function test_caret_83_satisfies(): void
    {
        self::assertTrue($this->guard->satisfiesMinimum('^8.3'));
    }

    public function test_caret_84_satisfies(): void
    {
        self::assertTrue($this->guard->satisfiesMinimum('^8.4'));
    }

    public function test_caret_81_does_not_satisfy(): void
    {
        self::assertFalse($this->guard->satisfiesMinimum('^8.1'));
    }

    public function test_caret_80_does_not_satisfy(): void
    {
        self::assertFalse($this->guard->satisfiesMinimum('^8.0'));
    }

    public function test_gte_82_satisfies(): void
    {
        self::assertTrue($this->guard->satisfiesMinimum('>=8.2'));
    }

    public function test_gte_81_does_not_satisfy(): void
    {
        self::assertFalse($this->guard->satisfiesMinimum('>=8.1'));
    }

    public function test_tilde_82_satisfies(): void
    {
        self::assertTrue($this->guard->satisfiesMinimum('~8.2'));
    }

    public function test_exact_82_satisfies(): void
    {
        self::assertTrue($this->guard->satisfiesMinimum('8.2'));
    }

    public function test_exact_81_does_not_satisfy(): void
    {
        self::assertFalse($this->guard->satisfiesMinimum('8.1'));
    }

    public function test_unknown_format_does_not_satisfy(): void
    {
        self::assertFalse($this->guard->satisfiesMinimum('*'));
    }

    public function test_php_9_satisfies(): void
    {
        self::assertTrue($this->guard->satisfiesMinimum('^9.0'));
    }

    // ── check() tests ─────────────────────────────────────────────────────────

    public function test_check_missing_file_returns_error(): void
    {
        $result = $this->guard->check('/nonexistent/composer.json');

        self::assertSame('error', $result['status']);
        self::assertStringContainsString('not found', $result['message']);
    }

    public function test_check_valid_82_returns_ok(): void
    {
        $composerJson = $this->createTempComposerJson(['require' => ['php' => '^8.2']]);

        $result = $this->guard->check($composerJson);

        self::assertSame('ok', $result['status']);
        self::assertSame('^8.2', $result['declared_constraint']);
    }

    public function test_check_php_81_returns_warning(): void
    {
        $composerJson = $this->createTempComposerJson(['require' => ['php' => '^8.1']]);

        $result = $this->guard->check($composerJson);

        self::assertSame('warning', $result['status']);
        self::assertStringContainsString('8.2', $result['message']);
        self::assertSame('^8.1', $result['declared_constraint']);
    }

    public function test_check_no_php_constraint_returns_warning(): void
    {
        $composerJson = $this->createTempComposerJson(['require' => ['laravel/framework' => '^11.0']]);

        $result = $this->guard->check($composerJson);

        self::assertSame('warning', $result['status']);
        self::assertNull($result['declared_constraint']);
    }

    public function test_check_invalid_json_returns_error(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'composer_test_');
        assert($path !== false);
        file_put_contents($path, 'not valid json { bad }');

        try {
            $result = $this->guard->check($path);
            self::assertSame('error', $result['status']);
        } finally {
            unlink($path);
        }
    }

    public function test_php_minimum_is_returned_in_result(): void
    {
        $composerJson = $this->createTempComposerJson(['require' => ['php' => '^8.1']]);

        $result = $this->guard->check($composerJson);

        self::assertSame('8.2', $result['php_minimum']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $data
     */
    private function createTempComposerJson(array $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'composer_test_');
        assert($path !== false);
        file_put_contents($path, json_encode($data));

        $this->registerTempFile($path);

        return $path;
    }

    /** @var list<string> */
    private array $tempFiles = [];

    private function registerTempFile(string $path): void
    {
        $this->tempFiles[] = $path;
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }
}
