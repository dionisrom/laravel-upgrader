<?php

declare(strict_types=1);

namespace Tests\Unit\Rector\Rules\L12ToL13;

use AppContainer\Rector\Rules\L12ToL13\PhpVersionGuard;
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

    public function test_caret_83_satisfies(): void
    {
        self::assertTrue($this->guard->satisfiesMinimum('^8.3'));
    }

    public function test_caret_84_satisfies(): void
    {
        self::assertTrue($this->guard->satisfiesMinimum('^8.4'));
    }

    public function test_caret_82_does_not_satisfy(): void
    {
        // ^8.2 allows PHP 8.2 which is below 8.3 — warn
        self::assertFalse($this->guard->satisfiesMinimum('^8.2'));
    }

    public function test_caret_81_does_not_satisfy(): void
    {
        // ^8.1 allows PHP 8.1 which is below 8.3 — warn
        self::assertFalse($this->guard->satisfiesMinimum('^8.1'));
    }

    public function test_gte_83_satisfies(): void
    {
        self::assertTrue($this->guard->satisfiesMinimum('>=8.3'));
    }

    public function test_gte_82_does_not_satisfy(): void
    {
        // >=8.2 allows PHP 8.2 which is below 8.3 — warn
        self::assertFalse($this->guard->satisfiesMinimum('>=8.2'));
    }

    public function test_tilde_83_satisfies(): void
    {
        self::assertTrue($this->guard->satisfiesMinimum('~8.3'));
    }

    public function test_exact_83_satisfies(): void
    {
        self::assertTrue($this->guard->satisfiesMinimum('8.3'));
    }

    public function test_exact_82_does_not_satisfy(): void
    {
        self::assertFalse($this->guard->satisfiesMinimum('8.2'));
    }

    public function test_unknown_format_does_not_satisfy(): void
    {
        self::assertFalse($this->guard->satisfiesMinimum('*'));
    }

    public function test_php_9_satisfies(): void
    {
        self::assertTrue($this->guard->satisfiesMinimum('^9.0'));
    }

    // ── OR constraint tests ──────────────────────────────────────────────────

    public function test_or_constraint_82_or_90_does_not_satisfy(): void
    {
        // ^8.2 part allows PHP 8.2 (< 8.3) — conservative: warn
        self::assertFalse($this->guard->satisfiesMinimum('^8.2 || ^9.0'));
    }

    public function test_or_constraint_81_or_82_does_not_satisfy(): void
    {
        // Both parts allow versions below 8.3
        self::assertFalse($this->guard->satisfiesMinimum('^8.1 || ^8.2'));
    }

    public function test_or_constraint_83_or_84_satisfies(): void
    {
        self::assertTrue($this->guard->satisfiesMinimum('^8.3 || ^8.4'));
    }

    // ── Range constraint tests ───────────────────────────────────────────────

    public function test_range_gte82_lt90_does_not_satisfy(): void
    {
        // >=8.2 — minimum is 8.2 which is below 8.3
        self::assertFalse($this->guard->satisfiesMinimum('>=8.2 <9.0'));
    }

    public function test_range_gte83_lt90_satisfies(): void
    {
        // >=8.3 <9.0 — PHP 8.3 is in range [8.3, 9.0)
        self::assertTrue($this->guard->satisfiesMinimum('>=8.3 <9.0'));
    }

    public function test_range_gte80_lt82_does_not_satisfy(): void
    {
        // >=8.0 <8.2 — PHP 8.3 is NOT in range [8.0, 8.2)
        self::assertFalse($this->guard->satisfiesMinimum('>=8.0 <8.2'));
    }

    public function test_range_gte80_lt83_does_not_satisfy(): void
    {
        // >=8.0 <8.3 — PHP 8.3 is NOT in range [8.0, 8.3) — exclusive upper bound
        self::assertFalse($this->guard->satisfiesMinimum('>=8.0 <8.3'));
    }

    // ── Caret with lower bound tests ─────────────────────────────────────────

    // ── check() tests ─────────────────────────────────────────────────────────

    public function test_check_missing_file_returns_error(): void
    {
        $result = $this->guard->check('/nonexistent/composer.json');

        self::assertSame('error', $result['status']);
        self::assertStringContainsString('not found', $result['message']);
    }

    public function test_check_valid_83_returns_ok(): void
    {
        $composerJson = $this->createTempComposerJson(['require' => ['php' => '^8.3']]);

        $result = $this->guard->check($composerJson);

        self::assertSame('ok', $result['status']);
        self::assertSame('^8.3', $result['declared_constraint']);
    }

    public function test_check_php_82_returns_warning(): void
    {
        $composerJson = $this->createTempComposerJson(['require' => ['php' => '^8.2']]);

        $result = $this->guard->check($composerJson);

        self::assertSame('warning', $result['status']);
        self::assertStringContainsString('8.3', $result['message']);
        self::assertSame('^8.2', $result['declared_constraint']);
    }

    public function test_check_no_php_constraint_returns_warning(): void
    {
        $composerJson = $this->createTempComposerJson(['require' => ['laravel/framework' => '^12.0']]);

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

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $data
     */
    private function createTempComposerJson(array $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'composer_test_');
        assert($path !== false);
        file_put_contents($path, json_encode($data));

        // Register cleanup
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
